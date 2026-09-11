<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanReviewDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanningReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Exception;
use Illuminate\Support\Facades\DB;

final readonly class DispatchOrbitPlanReview
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private HerdrRuntime $herdr,
        private OrbitFeatureWorkflow $workflow,
        private OrbitPlanningReceiptValidator $planningReceipts,
    ) {}

    public function handle(int $deliveryId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $this->assertLiveDelivery($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitPlanReviewDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitPlanReviewDispatchFailed('The Orbit project does not use Commander\'s active Herdr session.');
        }

        $preparation = $this->preparations->startup($delivery);
        [$dispatch, $receipt, $prompt] = $this->prepareDispatch($delivery, $config);

        if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            [$delivery, $dispatch, $receipt] = $this->recheckBeforeMutation(
                $delivery->id,
                $dispatch->id,
                $receipt->id,
                $config,
            );

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $this->verifyCurrentReviewState($delivery, $config, $preparation, $receipt);

            if (! $this->claim($dispatch)) {
                throw new OrbitPlanReviewDispatchFailed('Another process already claimed the Orbit plan-review dispatch.');
            }

            return $this->startAndPrompt($delivery, $dispatch, $prompt, $config, $preparation, $receipt);
        } finally {
            $reservation->release();
        }
    }

    private function assertLiveDelivery(Delivery $delivery): void
    {
        $project = $delivery->projectOrchestration;
        $existing = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
            ->where('attempt', 1)
            ->first()?->agentDispatches()
            ->first();

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            || $project->state !== ProjectOrchestrationState::Enabled
            || ! in_array($delivery->status, [
                DeliveryStatus::Queued,
                DeliveryStatus::Preparing,
                DeliveryStatus::WaitingForAgent,
            ], true)
            || ($delivery->status === DeliveryStatus::WaitingForAgent
                && ($existing === null
                    || ! in_array($existing->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)))) {
            throw new OrbitPlanReviewDispatchFailed('The delivery is not eligible for live Orbit plan-review dispatch.');
        }
    }

    /** @return array{AgentDispatch, Receipt, string} */
    private function prepareDispatch(Delivery $delivery, OrbitProjectConfig $config): array
    {
        return DB::transaction(function () use ($delivery, $config): array {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $project = $locked->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()
                ->where('delivery_id', $locked->id)
                ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
                ->where('attempt', 1)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->where('agent_role', OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)
                ->lockForUpdate()
                ->firstOrFail();
            $receipt = $this->sourceReceipt($locked, $phase);
            $expectedKey = IdempotencyKey::forDispatch(
                $locked->id,
                $phase->phase_name,
                $phase->attempt,
                OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
            )->value;

            if ($locked->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $locked->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $locked->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                || ! in_array($locked->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $phase->agentDispatches()->count() !== 1
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->herdr_agent_name !== strtolower((string) $locked->external_issue_key).'-loop-plan-review'
                || $dispatch->prompt_name !== 'orbit_plan_review'
                || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLAN_REVIEW_PROMPT_VERSION) {
                throw new OrbitPlanReviewDispatchFailed('The retained Orbit plan-review intent is inconsistent.');
            }

            if ($locked->status === DeliveryStatus::Queued) {
                if ($phase->status !== PhaseRunStatus::Pending || $dispatch->status !== AgentDispatchStatus::Pending) {
                    throw new OrbitPlanReviewDispatchFailed('The retained Orbit plan-review state is inconsistent.');
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
                throw new OrbitPlanReviewDispatchFailed('The retained Orbit plan-review state is inconsistent.');
            }

            $prompt = $this->workflow->planReviewPrompt(
                (string) $locked->external_issue_key,
                (string) $locked->worktree_path,
                $locked->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $receipt->payload,
            );
            $promptHash = hash('sha256', $prompt);

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = $promptHash;
                $dispatch->save();
            } elseif (! hash_equals($dispatch->prompt_hash, $promptHash)) {
                throw new OrbitPlanReviewDispatchFailed('The retained Orbit plan-review prompt metadata is inconsistent.');
            }

            return [$dispatch, $receipt, $prompt];
        });
    }

    /** @return array{Delivery, AgentDispatch, Receipt} */
    private function recheckBeforeMutation(
        int $deliveryId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
    ): array {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $phase = $dispatch->phaseRun()->firstOrFail();
        $receipt = $this->sourceReceipt($delivery, $phase);

        if ($receipt->id !== $receiptId
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()) {
            throw new OrbitPlanReviewDispatchFailed('The live Orbit project or review input changed before external mutation.');
        }

        if ($delivery->status === DeliveryStatus::WaitingForAgent
            && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return [$delivery, $dispatch, $receipt];
        }

        if ($delivery->status !== DeliveryStatus::Preparing
            || $phase->status !== PhaseRunStatus::Running
            || $dispatch->status !== AgentDispatchStatus::Pending) {
            if ($delivery->status === DeliveryStatus::Preparing
                && $dispatch->status === AgentDispatchStatus::Starting) {
                $this->blockInterruptedDelivery($delivery, $dispatch);

                throw new OrbitPlanReviewDispatchFailed(
                    'The prior Orbit plan-review dispatch was interrupted after startup began; manual recovery is required.',
                );
            }

            throw new OrbitPlanReviewDispatchFailed('The Orbit plan-review dispatch changed before external mutation.');
        }

        return [$delivery, $dispatch, $receipt];
    }

    private function verifyCurrentReviewState(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $receipt,
    ): void {
        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::Preparing
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()) {
            throw new OrbitPlanReviewDispatchFailed('The live Orbit delivery changed before plan-review verification.');
        }

        $candidateSha = $receipt->payload['candidate_sha'] ?? null;
        $artifactSha = $receipt->payload['artifact_sha'] ?? null;

        if (! is_string($candidateSha) || ! is_string($artifactSha)
            || $candidateSha !== $delivery->candidate_sha) {
            throw new OrbitPlanReviewDispatchFailed('The immutable planning receipt no longer identifies the delivery candidate.');
        }

        $verified = $this->repository->verifyPlanningOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $candidateSha,
            $artifactSha,
        );

        if ($verified->candidateSha !== $candidateSha
            || $verified->artifactSha !== $artifactSha
            || $verified->planContentsHash !== ($receipt->payload['plan_sha256'] ?? null)) {
            throw new OrbitPlanReviewDispatchFailed('The verified planning artifact no longer matches its immutable receipt.');
        }

        $issue = $this->issues->fetch($preparation->snapshot->issueId, $preparation->snapshot->issueKey);
        $this->assertCurrentIssue($preparation, $issue);
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
            throw new OrbitIssueContractChanged('The Orbit issue changed before plan-review dispatch.');
        }
    }

    private function claim(AgentDispatch $dispatch): bool
    {
        return AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update([
                'status' => AgentDispatchStatus::Starting,
                'error_code' => 'plan_review_dispatch_starting',
                'error_message' => null,
            ]) === 1;
    }

    private function startAndPrompt(
        Delivery $delivery,
        AgentDispatch $dispatch,
        string $prompt,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $receipt,
    ): AgentDispatch {
        try {
            $opened = $this->herdr->openWorktree(
                $config->repository,
                $preparation->worktree->path,
                $preparation->snapshot->issueKey,
            );
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_worktree_open_ambiguous', $exception);

            throw new OrbitPlanReviewDispatchFailed('The Herdr worktree-open outcome is unresolved.', 0, $exception);
        }

        try {
            $pane = $this->herdr->splitPane($opened->paneId, $preparation->worktree->path);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pane_split_ambiguous', $exception);

            throw new OrbitPlanReviewDispatchFailed('The Herdr pane-split outcome is unresolved.', 0, $exception);
        }

        if ($pane->workspaceId !== $opened->workspaceId || $pane->tabId !== $opened->tabId) {
            $exception = new OrbitPlanReviewDispatchFailed('Herdr returned a review pane outside the opened workspace.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pane_identity_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'herdr_session' => $config->herdrSession,
            'herdr_workspace_id' => $pane->workspaceId,
            'herdr_tab_id' => $pane->tabId,
            'herdr_pane_id' => $pane->paneId,
            'herdr_terminal_id' => $pane->terminalId,
            'error_code' => 'herdr_agent_starting',
            'error_message' => null,
        ])->save();

        $started = $this->startAgent($delivery, $dispatch, $pane);
        $this->persistStartedAgent($dispatch, $started);

        try {
            $freshDelivery = Delivery::query()->with('projectOrchestration')->find($delivery->id);

            if ($freshDelivery === null) {
                throw new OrbitPlanReviewDispatchFailed('The delivery disappeared during final review verification.');
            }

            $freshDispatch = AgentDispatch::query()->whereKey($dispatch->id)->firstOrFail();
            $freshPhase = $freshDispatch->phaseRun()->firstOrFail();
            $freshReceipt = $this->sourceReceipt($freshDelivery, $freshPhase);

            if ($freshReceipt->id !== $receipt->id
                || $freshDispatch->status !== AgentDispatchStatus::Starting
                || $freshDispatch->error_code !== 'plan_review_final_verification') {
                throw new OrbitPlanReviewDispatchFailed('The plan-review ledger changed before final verification.');
            }

            $this->verifyCurrentReviewState($freshDelivery, $config, $preparation, $freshReceipt);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'plan_review_final_verification_failed', $exception);

            throw new OrbitPlanReviewDispatchFailed('Final plan-review verification failed before prompting the retained agent.', 0, $exception);
        }

        $dispatch->forceFill(['error_code' => 'herdr_prompt_attempted', 'error_message' => null])->save();

        try {
            $prompted = $this->herdr->promptAgent($started->agentName, $prompt);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_ambiguous', $exception);

            throw new OrbitPlanReviewDispatchFailed(
                'The Herdr prompt outcome is unresolved; Commander will not submit it again automatically.',
                0,
                $exception,
            );
        }

        if (! $this->sameAgent($prompted, $dispatch)) {
            $exception = new OrbitPlanReviewDispatchFailed('Herdr prompted an agent outside the recorded plan-review dispatch.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_identity_ambiguous', $exception);

            throw $exception;
        }

        DB::transaction(function () use ($delivery, $dispatch, $prompted): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $promptWasAttempted = $lockedDispatch->status === AgentDispatchStatus::Starting
                && $lockedDispatch->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted && $lockedDispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPlanReviewDispatchFailed('The plan-review dispatch changed while its prompt was submitted.');
            }

            $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
            $lockedDispatch->error_code = null;
            $lockedDispatch->error_message = null;

            if ($promptWasAttempted) {
                $lockedDispatch->status = AgentDispatchStatus::Waiting;
            }

            $lockedDispatch->save();

            if ($lockedDelivery->current_phase === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                && in_array($lockedDelivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)) {
                $lockedDelivery->status = DeliveryStatus::WaitingForAgent;
                $lockedDelivery->failure_details = null;
                $lockedDelivery->save();
            }
        });

        return $dispatch->refresh();
    }

    private function startAgent(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $pane,
    ): HerdrAgentIdentifiers {
        try {
            $started = $this->herdr->startAgent(
                $pane->paneId,
                (string) $dispatch->herdr_agent_name,
                $this->reviewLaunch(),
            );
        } catch (Exception $startFailure) {
            try {
                $started = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (Exception) {
                $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_start_ambiguous', $startFailure);

                throw new OrbitPlanReviewDispatchFailed('The Herdr agent-start outcome is unresolved.', 0, $startFailure);
            }
        }

        if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)) {
            $exception = new OrbitPlanReviewDispatchFailed('Herdr returned an agent outside the recorded review pane.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_agent_identity_ambiguous', $exception);

            throw $exception;
        }

        return $started;
    }

    private function persistStartedAgent(AgentDispatch $dispatch, HerdrAgentIdentifiers $started): void
    {
        $dispatch->forceFill([
            'herdr_workspace_id' => $started->workspaceId,
            'herdr_tab_id' => $started->tabId,
            'herdr_pane_id' => $started->paneId,
            'herdr_terminal_id' => $started->terminalId,
            'herdr_agent_id' => $started->agentId,
            'herdr_agent_name' => $started->agentName,
            'state_change_seq' => $started->stateChangeSeq,
            'dispatched_at' => now(),
            'error_code' => 'plan_review_final_verification',
            'error_message' => null,
        ])->save();
    }

    private function samePaneAndName(HerdrAgentIdentifiers $agent, HerdrAgentIdentifiers $pane, string $name): bool
    {
        return $agent->workspaceId === $pane->workspaceId
            && $agent->tabId === $pane->tabId
            && $agent->paneId === $pane->paneId
            && $agent->terminalId === $pane->terminalId
            && $agent->agentName === $name;
    }

    private function sameAgent(HerdrAgentIdentifiers $agent, AgentDispatch $dispatch): bool
    {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && $agent->agentId === $dispatch->herdr_agent_id
            && $agent->agentName === $dispatch->herdr_agent_name;
    }

    private function reviewLaunch(): HerdrAgentLaunch
    {
        return new HerdrAgentLaunch('codex', [
            '-m',
            'gpt-5.6-sol',
            '-c',
            'model_reasoning_effort="high"',
            '-c',
            'features.multi_agent=false',
            '-c',
            'mcp_servers.context7.enabled=false',
            '-c',
            'mcp_servers.solo_nick.enabled=false',
            '-c',
            'mcp_servers.solo_mini.enabled=false',
            '--dangerously-bypass-approvals-and-sandbox',
        ], 120_000);
    }

    private function receiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-plan-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
    }

    private function sourceReceipt(Delivery $delivery, PhaseRun $review): Receipt
    {
        $input = $review->input;
        $receiptId = is_array($input) ? ($input['planning_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['planning_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['planning_receipt_id', 'planning_receipt']) !== []
            || count($input) !== 2) {
            throw new OrbitPlanReviewDispatchFailed('The plan-review phase has invalid immutable input.');
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $phase = $receipt?->phaseRun;
        $dispatch = $phase?->agentDispatches->first();

        if ($receipt === null || $phase === null || $dispatch === null
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $phase->attempt !== 1
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'ready']
            || $phase->agentDispatches->count() !== 1
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $receipt->payload !== $payload
            || ($payload['result'] ?? null) !== 'ready'
            || ($payload['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || ! $this->planningReceipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitPlanReviewDispatchFailed('The immutable planning receipt no longer matches the review intent.');
        }

        return $receipt;
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
                'code' => 'plan_review_dispatch_interrupted',
                'dispatch_id' => $dispatch->id,
                'stage' => $dispatch->error_code,
            ];
            $locked->save();
        });
    }
}
