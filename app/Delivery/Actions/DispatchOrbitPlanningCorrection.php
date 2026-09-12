<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanningDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanReviewReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Exception;
use Illuminate\Support\Facades\DB;

final readonly class DispatchOrbitPlanningCorrection
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private HerdrRuntime $herdr,
        private OrbitFeatureWorkflow $workflow,
        private OrbitPlanReviewReceiptValidator $reviewReceipts,
    ) {}

    public function handle(int $deliveryId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $this->assertLiveDelivery($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitPlanningDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitPlanningDispatchFailed('The Orbit project does not use Commander\'s active Herdr session.');
        }

        $preparation = $this->preparations->startup($delivery);
        [$dispatch, $reviewReceipt, $builder, $prompt] = $this->prepareDispatch($delivery, $config);

        if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            [$delivery, $dispatch, $reviewReceipt, $builder] = $this->recheckBeforeMutation(
                $delivery->id,
                $dispatch->id,
                $reviewReceipt->id,
                $builder->id,
                $config,
            );

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $this->verifyCorrectionInput($delivery, $config, $preparation, $reviewReceipt);

            try {
                $retained = $this->herdr->getAgent((string) $builder->herdr_agent_name);
            } catch (Exception $exception) {
                throw new OrbitPlanningDispatchFailed('The retained Orbit Builder could not be inspected.', 0, $exception);
            }

            if (! $this->isAvailableBuilder($retained, $builder, $config, $preparation)) {
                $exception = new OrbitPlanningDispatchFailed(
                    'The retained Orbit Builder is missing, busy, or outside its recorded worktree.',
                );
                $this->markBlocked(
                    $delivery,
                    $dispatch,
                    AgentDispatchStatus::Failed,
                    'retained_builder_unavailable',
                    $exception,
                );

                throw $exception;
            }

            if (! $this->claim($dispatch)) {
                throw new OrbitPlanningDispatchFailed('Another process already claimed the Orbit planning correction.');
            }

            return $this->promptRetainedBuilder(
                $delivery,
                $dispatch,
                $builder,
                $retained,
                $reviewReceipt,
                $prompt,
                $config,
                $preparation,
            );
        } finally {
            $reservation->release();
        }
    }

    private function assertLiveDelivery(Delivery $delivery): void
    {
        $project = $delivery->projectOrchestration;
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 2)
            ->first();
        $dispatch = $phase?->agentDispatches()->first();
        $active = ($delivery->status === DeliveryStatus::Queued
                && $phase?->status === PhaseRunStatus::Pending
                && $dispatch?->status === AgentDispatchStatus::Pending)
            || ($delivery->status === DeliveryStatus::Preparing
                && $phase?->status === PhaseRunStatus::Running
                && in_array($dispatch?->status, [AgentDispatchStatus::Pending, AgentDispatchStatus::Starting], true))
            || ($delivery->status === DeliveryStatus::WaitingForAgent
                && $phase?->status === PhaseRunStatus::Running
                && in_array($dispatch?->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true));

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $project->state !== ProjectOrchestrationState::Enabled
            || ! $active) {
            throw new OrbitPlanningDispatchFailed('The delivery is not eligible for an Orbit planning correction.');
        }
    }

    /** @return array{AgentDispatch, Receipt, AgentDispatch, string} */
    private function prepareDispatch(Delivery $delivery, OrbitProjectConfig $config): array
    {
        return DB::transaction(function () use ($delivery, $config): array {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $project = $locked->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()
                ->where('delivery_id', $locked->id)
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->where('attempt', 2)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->where('agent_role', OrbitFeatureWorkflow::PLANNING_AGENT_ROLE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $locked->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $locked->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
                || ! in_array($locked->status, [
                    DeliveryStatus::Queued,
                    DeliveryStatus::Preparing,
                    DeliveryStatus::WaitingForAgent,
                ], true)
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()) {
                throw new OrbitPlanningDispatchFailed('The live Orbit delivery changed before correction dispatch.');
            }

            $reviewReceipt = $this->sourceReviewReceipt($locked, $phase);
            $builder = $this->sourceBuilder($locked, $config);
            $this->assertDispatchIntent($locked, $phase, $dispatch);

            if ($locked->status === DeliveryStatus::Queued) {
                if ($phase->status !== PhaseRunStatus::Pending
                    || $dispatch->status !== AgentDispatchStatus::Pending) {
                    throw new OrbitPlanningDispatchFailed('The retained planning-correction state is inconsistent.');
                }

                $phase->status = PhaseRunStatus::Running;
                $phase->started_at = now();
                $phase->save();
                $locked->status = DeliveryStatus::Preparing;
                $locked->save();
            }

            $validActiveState = ($locked->status === DeliveryStatus::Preparing
                    && $phase->status === PhaseRunStatus::Running
                    && in_array($dispatch->status, [AgentDispatchStatus::Pending, AgentDispatchStatus::Starting], true))
                || ($locked->status === DeliveryStatus::WaitingForAgent
                    && $phase->status === PhaseRunStatus::Running
                    && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true));

            if (! $validActiveState) {
                throw new OrbitPlanningDispatchFailed('The retained planning-correction state is inconsistent.');
            }

            $prompt = $this->workflow->planningCorrectionPrompt(
                (string) $locked->external_issue_key,
                (string) $locked->worktree_path,
                $locked->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $reviewReceipt->payload,
            );
            $promptHash = hash('sha256', $prompt);

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = $promptHash;
                $dispatch->save();
            } elseif (! hash_equals($dispatch->prompt_hash, $promptHash)) {
                throw new OrbitPlanningDispatchFailed('The retained planning-correction prompt is inconsistent.');
            }

            return [$dispatch, $reviewReceipt, $builder, $prompt];
        });
    }

    /** @return array{Delivery, AgentDispatch, Receipt, AgentDispatch} */
    private function recheckBeforeMutation(
        int $deliveryId,
        int $dispatchId,
        int $receiptId,
        int $builderId,
        OrbitProjectConfig $config,
    ): array {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 2)
            ->firstOrFail();
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $reviewReceipt = $this->sourceReviewReceipt($delivery, $phase);
        $builder = $this->sourceBuilder($delivery, $config);

        if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()
            || $reviewReceipt->id !== $receiptId
            || $builder->id !== $builderId) {
            throw new OrbitPlanningDispatchFailed('The live Orbit correction input changed before external mutation.');
        }

        if ($delivery->status === DeliveryStatus::WaitingForAgent
            && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return [$delivery, $dispatch, $reviewReceipt, $builder];
        }

        if ($delivery->status !== DeliveryStatus::Preparing
            || $phase->status !== PhaseRunStatus::Running
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Pending) {
            if ($delivery->status === DeliveryStatus::Preparing
                && $dispatch->status === AgentDispatchStatus::Starting) {
                $this->blockInterruptedDelivery($delivery, $dispatch);

                throw new OrbitPlanningDispatchFailed(
                    'The prior planning correction was interrupted after dispatch began; manual recovery is required.',
                );
            }

            throw new OrbitPlanningDispatchFailed('The Orbit planning correction changed before external mutation.');
        }

        return [$delivery, $dispatch, $reviewReceipt, $builder];
    }

    private function verifyCorrectionInput(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $reviewReceipt,
    ): void {
        $issue = $this->issues->fetch($preparation->snapshot->issueId, $preparation->snapshot->issueKey);
        $this->assertCurrentIssue($preparation, $issue);
        $candidateSha = $this->sha($reviewReceipt->payload, 'candidate_sha');
        $artifactSha = $this->sha($reviewReceipt->payload, 'artifact_sha');
        $verified = $this->repository->verifyPlanningOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $candidateSha,
            null,
        );
        $artifact = $this->repository->verifyPlanningArtifact(
            $config,
            new PreparedWorktree($preparation->worktree->path, $candidateSha),
            $preparation->snapshot->issueKey,
            $artifactSha,
            'FIX',
        );

        if ($verified->candidateSha !== $delivery->candidate_sha
            || $verified->candidateSha !== $candidateSha
            || $verified->artifactSha !== null
            || $verified->planContentsHash !== null
            || $artifact->artifactSha !== $artifactSha
            || $artifact->planContentsHash !== ($reviewReceipt->payload['plan_sha256'] ?? null)) {
            throw new OrbitPlanningDispatchFailed('The verified planning correction input no longer matches its review.');
        }
    }

    private function assertCurrentIssue(OrbitDeliveryPreparation $preparation, OrbitIssueSnapshot $issue): void
    {
        $state = $issue->payload['state'] ?? null;

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Progress'
            || ($state['type'] ?? null) !== 'started') {
            throw new OrbitIssueContractChanged('The Orbit issue changed before planning-correction dispatch.');
        }
    }

    private function isAvailableBuilder(
        HerdrAgentIdentifiers $agent,
        AgentDispatch $builder,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
    ): bool {
        return $builder->herdr_session === $config->herdrSession
            && $agent->workspaceId === $builder->herdr_workspace_id
            && $agent->tabId === $builder->herdr_tab_id
            && $agent->paneId === $builder->herdr_pane_id
            && $agent->terminalId === $builder->herdr_terminal_id
            && $agent->agentId === $builder->herdr_agent_id
            && $agent->agentName === $builder->herdr_agent_name
            && $agent->workingDirectory === $preparation->worktree->path
            && in_array($agent->agentStatus, ['idle', 'done'], true);
    }

    private function claim(AgentDispatch $dispatch): bool
    {
        return AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update([
                'status' => AgentDispatchStatus::Starting,
                'error_code' => 'planning_correction_dispatch_starting',
                'error_message' => null,
            ]) === 1;
    }

    private function promptRetainedBuilder(
        Delivery $delivery,
        AgentDispatch $dispatch,
        AgentDispatch $builder,
        HerdrAgentIdentifiers $retained,
        Receipt $reviewReceipt,
        string $prompt,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
    ): AgentDispatch {
        $dispatch->forceFill([
            'herdr_session' => $config->herdrSession,
            'herdr_workspace_id' => $retained->workspaceId,
            'herdr_tab_id' => $retained->tabId,
            'herdr_pane_id' => $retained->paneId,
            'herdr_terminal_id' => $retained->terminalId,
            'herdr_agent_id' => $retained->agentId,
            'herdr_agent_name' => $retained->agentName,
            'state_change_seq' => $retained->stateChangeSeq,
            'dispatched_at' => now(),
            'error_code' => 'planning_correction_final_verification',
            'error_message' => null,
        ])->save();

        try {
            $this->assertFinalLedger($delivery->id, $dispatch->id, $reviewReceipt->id, $builder->id, $config);
            $this->verifyCorrectionInput(
                Delivery::query()->findOrFail($delivery->id),
                $config,
                $preparation,
                Receipt::query()->findOrFail($reviewReceipt->id),
            );
            $this->markPromptAttempted(
                $delivery->id,
                $dispatch->id,
                $reviewReceipt->id,
                $builder->id,
                $config,
            );
        } catch (Exception $exception) {
            $this->markBlocked(
                $delivery,
                $dispatch,
                AgentDispatchStatus::Failed,
                'planning_correction_final_verification_failed',
                $exception,
            );

            throw new OrbitPlanningDispatchFailed(
                'Final planning-correction verification failed before prompting the retained Builder.',
                0,
                $exception,
            );
        }

        try {
            $prompted = $this->herdr->promptAgent($retained->agentName, $prompt);
        } catch (Exception $exception) {
            $this->markBlocked(
                $delivery,
                $dispatch,
                AgentDispatchStatus::Ambiguous,
                'herdr_prompt_ambiguous',
                $exception,
            );

            throw new OrbitPlanningDispatchFailed(
                'The Herdr prompt outcome is unresolved; Commander will not submit it again automatically.',
                0,
                $exception,
            );
        }

        if (! $this->sameAgent($prompted, $dispatch)) {
            $exception = new OrbitPlanningDispatchFailed(
                'Herdr prompted an agent outside the retained planning-correction dispatch.',
            );
            $this->markBlocked(
                $delivery,
                $dispatch,
                AgentDispatchStatus::Ambiguous,
                'herdr_prompt_identity_ambiguous',
                $exception,
            );

            throw $exception;
        }

        DB::transaction(function () use ($delivery, $dispatch, $prompted): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $promptWasAttempted = $lockedDispatch->status === AgentDispatchStatus::Starting
                && $lockedDispatch->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted && $lockedDispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPlanningDispatchFailed('The planning correction changed while its prompt was submitted.');
            }

            $lockedDispatch->herdr_agent_id = $prompted->agentId ?? $lockedDispatch->herdr_agent_id;
            $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
            $lockedDispatch->error_code = null;
            $lockedDispatch->error_message = null;

            if ($promptWasAttempted) {
                $lockedDispatch->status = AgentDispatchStatus::Waiting;
            }

            $lockedDispatch->save();

            if ($lockedDelivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
                && in_array($lockedDelivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)) {
                $lockedDelivery->status = DeliveryStatus::WaitingForAgent;
                $lockedDelivery->failure_details = null;
                $lockedDelivery->save();
            }
        });

        return $dispatch->refresh();
    }

    private function markPromptAttempted(
        int $deliveryId,
        int $dispatchId,
        int $receiptId,
        int $builderId,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use ($deliveryId, $dispatchId, $receiptId, $builderId, $config): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $deliveryId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertFinalLedger($deliveryId, $dispatchId, $receiptId, $builderId, $config);

            if (AgentDispatch::query()
                ->whereKey($dispatchId)
                ->where('status', AgentDispatchStatus::Starting)
                ->where('error_code', 'planning_correction_final_verification')
                ->update(['error_code' => 'herdr_prompt_attempted', 'error_message' => null]) !== 1) {
                throw new OrbitPlanningDispatchFailed('The planning-correction ledger changed before prompt submission.');
            }
        });
    }

    private function assertFinalLedger(
        int $deliveryId,
        int $dispatchId,
        int $receiptId,
        int $builderId,
        OrbitProjectConfig $config,
    ): void {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 2)
            ->firstOrFail();
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $reviewReceipt = $this->sourceReviewReceipt($delivery, $phase);
        $builder = $this->sourceBuilder($delivery, $config);

        if ($delivery->status !== DeliveryStatus::Preparing
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()
            || $phase->status !== PhaseRunStatus::Running
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Starting
            || $dispatch->error_code !== 'planning_correction_final_verification'
            || $reviewReceipt->id !== $receiptId
            || $builder->id !== $builderId
            || $dispatch->herdr_session !== $builder->herdr_session
            || $dispatch->herdr_workspace_id !== $builder->herdr_workspace_id
            || $dispatch->herdr_tab_id !== $builder->herdr_tab_id
            || $dispatch->herdr_pane_id !== $builder->herdr_pane_id
            || $dispatch->herdr_terminal_id !== $builder->herdr_terminal_id
            || $dispatch->herdr_agent_id !== $builder->herdr_agent_id
            || $dispatch->herdr_agent_name !== $builder->herdr_agent_name) {
            throw new OrbitPlanningDispatchFailed('The planning-correction ledger changed before prompting.');
        }
    }

    private function sourceReviewReceipt(Delivery $delivery, PhaseRun $correction): Receipt
    {
        $input = $correction->input;
        $receiptId = is_array($input) ? ($input['plan_review_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['plan_review_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['plan_review_receipt_id', 'plan_review_receipt']) !== []
            || count($input) !== 2) {
            throw new OrbitPlanningDispatchFailed('The planning correction has malformed review input.');
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $review = $receipt?->phaseRun;
        $reviewDispatch = $review?->agentDispatches->first();

        if ($receipt === null || $review === null || $reviewDispatch === null
            || $correction->delivery_id !== $delivery->id
            || $correction->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $correction->attempt !== 2
            || $review->delivery_id !== $delivery->id
            || $review->phase_name !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            || $review->attempt !== 1
            || $review->status !== PhaseRunStatus::Completed
            || $review->output !== ['receipt_id' => $receipt->id, 'result' => 'fix']
            || $review->agentDispatches->count() !== 1
            || $reviewDispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            || $reviewDispatch->status !== AgentDispatchStatus::Settled
            || $receipt->payload !== $payload
            || ($payload['result'] ?? null) !== 'fix'
            || ! $this->reviewReceipts->matches($delivery, $review, $reviewDispatch, $receipt)) {
            throw new OrbitPlanningDispatchFailed('The planning correction no longer matches its fixing review.');
        }

        return $receipt;
    }

    private function sourceBuilder(Delivery $delivery, OrbitProjectConfig $config): AgentDispatch
    {
        $planning = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 1)
            ->first();
        $dispatches = $planning?->agentDispatches()->get();
        $builder = $dispatches?->first();
        $expectedName = strtolower((string) $delivery->external_issue_key).'-loop-builder';

        if ($planning === null || $dispatches?->count() !== 1 || $builder === null
            || $planning->status !== PhaseRunStatus::Completed
            || $builder->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            || $builder->status !== AgentDispatchStatus::Settled
            || $builder->herdr_session !== $config->herdrSession
            || $builder->herdr_agent_name !== $expectedName
            || $builder->herdr_workspace_id === null
            || $builder->herdr_tab_id === null
            || $builder->herdr_pane_id === null
            || $builder->herdr_terminal_id === null
            || $builder->herdr_agent_id === null) {
            throw new OrbitPlanningDispatchFailed('The planning correction has no exact retained Builder.');
        }

        return $builder;
    }

    private function assertDispatchIntent(Delivery $delivery, PhaseRun $phase, AgentDispatch $dispatch): void
    {
        $expectedKey = IdempotencyKey::forDispatch(
            $delivery->id,
            OrbitFeatureWorkflow::INITIAL_PHASE,
            2,
            OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        )->value;
        $expectedName = strtolower((string) $delivery->external_issue_key).'-loop-builder';

        if ($phase->agentDispatches()->count() !== 1
            || $dispatch->idempotency_key !== $expectedKey
            || $dispatch->herdr_agent_name !== $expectedName
            || $dispatch->prompt_name !== 'orbit_planning_correction'
            || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLANNING_CORRECTION_PROMPT_VERSION) {
            throw new OrbitPlanningDispatchFailed('The retained planning-correction intent is inconsistent.');
        }
    }

    private function sameAgent(HerdrAgentIdentifiers $agent, AgentDispatch $dispatch): bool
    {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && ($agent->agentId === null
                || $dispatch->herdr_agent_id === null
                || $agent->agentId === $dispatch->herdr_agent_id)
            && $agent->agentName === $dispatch->herdr_agent_name;
    }

    private function receiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
    }

    private function markBlocked(
        Delivery $delivery,
        AgentDispatch $dispatch,
        AgentDispatchStatus $status,
        string $code,
        Exception $exception,
    ): void {
        DB::transaction(function () use ($delivery, $dispatch, $status, $code, $exception): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch->forceFill([
                'status' => $status,
                'error_code' => $code,
                'error_message' => $exception->getMessage(),
            ])->save();
            $lockedDelivery->status = DeliveryStatus::Blocked;
            $lockedDelivery->failure_details = [
                'code' => $code,
                'dispatch_id' => $lockedDispatch->id,
                'message' => $exception->getMessage(),
            ];
            $lockedDelivery->save();
        });
    }

    private function blockInterruptedDelivery(Delivery $delivery, AgentDispatch $dispatch): void
    {
        DB::transaction(function () use ($delivery, $dispatch): void {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeliveryStatus::Preparing) {
                return;
            }

            $locked->status = DeliveryStatus::Blocked;
            $locked->failure_details = [
                'code' => 'planning_correction_dispatch_interrupted',
                'dispatch_id' => $dispatch->id,
                'stage' => $dispatch->error_code,
            ];
            $locked->save();
        });
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitPlanningDispatchFailed("The planning correction has an invalid {$key}.");
        }

        return $value;
    }
}
