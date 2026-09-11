<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
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
use App\Delivery\Exceptions\OrbitImplementationDispatchFailed;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitImplementationReceiptValidator;
use App\Delivery\Workflow\OrbitPlanReviewReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Exception;
use Illuminate\Support\Facades\DB;

final readonly class DispatchOrbitImplementation
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitIssueProvider $issues,
        private OrbitIssueTransitioner $transitions,
        private HerdrRuntime $herdr,
        private OrbitFeatureWorkflow $workflow,
        private OrbitImplementationReceiptValidator $implementationReceipts,
        private OrbitPlanReviewReceiptValidator $reviewReceipts,
    ) {}

    public function handle(int $deliveryId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $this->assertLiveDelivery($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitImplementationDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitImplementationDispatchFailed('The Orbit project does not use Commander\'s active Herdr session.');
        }

        $preparation = $this->preparations->startup($delivery);
        [$dispatch, $sourceReceipt, $builder, $prompt] = $this->prepareDispatch($delivery, $config);

        if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            [$delivery, $dispatch, $sourceReceipt, $builder] = $this->recheckBeforeMutation(
                $delivery->id,
                $dispatch->id,
                $sourceReceipt->id,
                $builder->id,
                $config,
            );

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $phase = $dispatch->phaseRun()->firstOrFail();
            $currentIssue = $this->verifyImplementationInput(
                $delivery,
                $phase,
                $config,
                $preparation,
                $sourceReceipt,
                true,
            );

            if ($this->isPullRequestReviewCorrection($phase)) {
                try {
                    $currentIssue = $this->transitions->transitionToInProgress(
                        $currentIssue,
                        $preparation->snapshot->contractHash,
                    );
                } catch (OrbitIssueTransitionFailed $exception) {
                    $code = $exception->ambiguous
                        ? 'linear_implementation_transition_ambiguous'
                        : 'linear_implementation_transition_failed';
                    $status = $exception->ambiguous
                        ? AgentDispatchStatus::Ambiguous
                        : AgentDispatchStatus::Failed;
                    $this->markBlocked($delivery, $dispatch, $status, $code, $exception);

                    throw new OrbitImplementationDispatchFailed(
                        $exception->ambiguous
                            ? 'The Linear correction transition outcome is unresolved.'
                            : 'Linear rejected the correction transition before the Builder was prompted.',
                        0,
                        $exception,
                    );
                }

                $this->assertCurrentIssue($preparation, $currentIssue);
            }

            try {
                $retained = $this->herdr->getAgent((string) $builder->herdr_agent_name);
            } catch (Exception $exception) {
                throw new OrbitImplementationDispatchFailed('The retained Orbit Builder could not be inspected.', 0, $exception);
            }

            if (! $this->isAvailableBuilder($retained, $builder, $config, $preparation)) {
                $exception = new OrbitImplementationDispatchFailed(
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
                throw new OrbitImplementationDispatchFailed('Another process already claimed the Orbit implementation dispatch.');
            }

            return $this->promptRetainedBuilder(
                $delivery,
                $dispatch,
                $builder,
                $retained,
                $sourceReceipt,
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
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
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
            || $delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $project->state !== ProjectOrchestrationState::Enabled
            || ! $active) {
            throw new OrbitImplementationDispatchFailed('The delivery is not eligible for Orbit implementation dispatch.');
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
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->where('agent_role', OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $locked->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $locked->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || ! in_array($locked->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()) {
                throw new OrbitImplementationDispatchFailed('The live Orbit delivery changed before implementation dispatch.');
            }

            $sourceReceipt = $this->sourceReceipt($locked, $phase);
            $builder = $this->sourceBuilderForPhase($locked, $config, $phase, $sourceReceipt);
            $this->assertDispatchIntent($locked, $phase, $dispatch);

            if ($locked->status === DeliveryStatus::Queued) {
                if ($phase->status !== PhaseRunStatus::Pending
                    || $dispatch->status !== AgentDispatchStatus::Pending) {
                    throw new OrbitImplementationDispatchFailed('The retained implementation state is inconsistent.');
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
                throw new OrbitImplementationDispatchFailed('The retained implementation state is inconsistent.');
            }

            $prompt = $this->implementationPrompt($locked, $phase, $dispatch, $sourceReceipt);
            $promptHash = hash('sha256', $prompt);

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = $promptHash;
                $dispatch->save();
            } elseif (! hash_equals($dispatch->prompt_hash, $promptHash)) {
                throw new OrbitImplementationDispatchFailed('The retained implementation prompt is inconsistent.');
            }

            return [$dispatch, $sourceReceipt, $builder, $prompt];
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
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
            ->firstOrFail();
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $sourceReceipt = $this->sourceReceipt($delivery, $phase);
        $builder = $this->sourceBuilderForPhase($delivery, $config, $phase, $sourceReceipt);
        $this->assertDispatchPrompt($delivery, $phase, $dispatch, $sourceReceipt);

        if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()
            || $sourceReceipt->id !== $receiptId
            || $builder->id !== $builderId) {
            throw new OrbitImplementationDispatchFailed('The live Orbit implementation input changed before external mutation.');
        }

        if ($delivery->status === DeliveryStatus::WaitingForAgent
            && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return [$delivery, $dispatch, $sourceReceipt, $builder];
        }

        if ($delivery->status !== DeliveryStatus::Preparing
            || $phase->status !== PhaseRunStatus::Running
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Pending) {
            if ($delivery->status === DeliveryStatus::Preparing
                && $dispatch->status === AgentDispatchStatus::Starting) {
                $this->blockInterruptedDelivery($delivery, $dispatch);

                throw new OrbitImplementationDispatchFailed(
                    'The prior implementation dispatch was interrupted after dispatch began; manual recovery is required.',
                );
            }

            throw new OrbitImplementationDispatchFailed('The Orbit implementation dispatch changed before external mutation.');
        }

        return [$delivery, $dispatch, $sourceReceipt, $builder];
    }

    private function verifyImplementationInput(
        Delivery $delivery,
        PhaseRun $phase,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $sourceReceipt,
        bool $allowPullRequestReviewState = false,
    ): OrbitIssueSnapshot {
        $issue = $this->issues->fetch($preparation->snapshot->issueId, $preparation->snapshot->issueKey);
        $this->assertCurrentIssue(
            $preparation,
            $issue,
            $allowPullRequestReviewState && $this->isPullRequestReviewCorrection($phase),
        );

        if ($sourceReceipt->kind === 'orbit_implementation') {
            $this->verifyImplementationCorrectionInput($delivery, $config, $preparation, $sourceReceipt);

            return $issue;
        }

        $candidateSha = $this->sha($sourceReceipt->payload, 'candidate_sha');
        $artifactSha = $this->sha($sourceReceipt->payload, 'artifact_sha');
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
            'PASS',
        );

        if ($verified->candidateSha !== $delivery->candidate_sha
            || $verified->candidateSha !== $candidateSha
            || $verified->artifactSha !== null
            || $verified->planContentsHash !== null
            || $artifact->artifactSha !== $artifactSha
            || $artifact->planContentsHash !== ($sourceReceipt->payload['plan_sha256'] ?? null)) {
            throw new OrbitImplementationDispatchFailed('The verified implementation input no longer matches its passing review.');
        }

        return $issue;
    }

    private function verifyImplementationCorrectionInput(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $implementationReceipt,
    ): void {
        $payload = $implementationReceipt->payload;
        $verified = $this->implementations->verifyImplementationOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $this->sha($payload, 'reviewed_candidate_sha'),
            $this->sha($payload, 'candidate_sha'),
            $this->sha($payload, 'artifact_sha'),
            $this->string($payload, 'gate_receipt_path'),
            $this->string($payload, 'pull_request_body'),
        );

        if ($verified->candidateSha !== $delivery->candidate_sha
            || $verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || $verified->artifactSha !== ($payload['artifact_sha'] ?? null)
            || $verified->gateReceiptPath !== ($payload['gate_receipt_path'] ?? null)
            || $verified->pullRequestBodyHash !== ($payload['pull_request_body_sha256'] ?? null)
            || $verified->flow !== ($payload['flow'] ?? null)) {
            throw new OrbitImplementationDispatchFailed(
                'The verified correction input no longer matches its implementation receipt.',
            );
        }
    }

    private function assertCurrentIssue(
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
        bool $allowPullRequestReviewState = false,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $validState = is_array($state)
            && ($state['type'] ?? null) === 'started'
            && ($allowPullRequestReviewState
                ? in_array($state['name'] ?? null, ['In Progress', 'In Review'], true)
                : ($state['name'] ?? null) === 'In Progress');

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! $validState) {
            throw new OrbitIssueContractChanged('The Orbit issue changed before implementation dispatch.');
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
                'error_code' => 'implementation_dispatch_starting',
                'error_message' => null,
            ]) === 1;
    }

    private function promptRetainedBuilder(
        Delivery $delivery,
        AgentDispatch $dispatch,
        AgentDispatch $builder,
        HerdrAgentIdentifiers $retained,
        Receipt $sourceReceipt,
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
            'error_code' => 'implementation_final_verification',
            'error_message' => null,
        ])->save();

        try {
            $this->assertFinalLedger($delivery->id, $dispatch->id, $sourceReceipt->id, $builder->id, $config);
            $freshDelivery = Delivery::query()->findOrFail($delivery->id);
            $freshPhase = $freshDelivery->phaseRuns()
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->firstOrFail();
            $this->verifyImplementationInput(
                $freshDelivery,
                $freshPhase,
                $config,
                $preparation,
                Receipt::query()->findOrFail($sourceReceipt->id),
            );
            $this->markPromptAttempted(
                $delivery->id,
                $dispatch->id,
                $sourceReceipt->id,
                $builder->id,
                $config,
            );
        } catch (Exception $exception) {
            $this->markBlocked(
                $delivery,
                $dispatch,
                AgentDispatchStatus::Failed,
                'implementation_final_verification_failed',
                $exception,
            );

            throw new OrbitImplementationDispatchFailed(
                'Final implementation verification failed before prompting the retained Builder.',
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

            throw new OrbitImplementationDispatchFailed(
                'The Herdr prompt outcome is unresolved; Commander will not submit it again automatically.',
                0,
                $exception,
            );
        }

        if (! $this->sameAgent($prompted, $dispatch)) {
            $exception = new OrbitImplementationDispatchFailed(
                'Herdr prompted an agent outside the retained implementation dispatch.',
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
                throw new OrbitImplementationDispatchFailed('The implementation changed while its prompt was submitted.');
            }

            $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
            $lockedDispatch->error_code = null;
            $lockedDispatch->error_message = null;

            if ($promptWasAttempted) {
                $lockedDispatch->status = AgentDispatchStatus::Waiting;
            }

            $lockedDispatch->save();

            if ($lockedDelivery->current_phase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
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
                ->where('error_code', 'implementation_final_verification')
                ->update(['error_code' => 'herdr_prompt_attempted', 'error_message' => null]) !== 1) {
                throw new OrbitImplementationDispatchFailed('The implementation ledger changed before prompt submission.');
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
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
            ->firstOrFail();
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $sourceReceipt = $this->sourceReceipt($delivery, $phase);
        $builder = $this->sourceBuilderForPhase($delivery, $config, $phase, $sourceReceipt);
        $this->assertDispatchPrompt($delivery, $phase, $dispatch, $sourceReceipt);

        if ($delivery->status !== DeliveryStatus::Preparing
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()
            || $phase->status !== PhaseRunStatus::Running
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Starting
            || $dispatch->error_code !== 'implementation_final_verification'
            || $sourceReceipt->id !== $receiptId
            || $builder->id !== $builderId
            || ! $this->sameStoredAgent($dispatch, $builder)) {
            throw new OrbitImplementationDispatchFailed('The implementation ledger changed before prompting.');
        }
    }

    /** @return array<string, mixed> */
    private function pullRequestInput(PhaseRun $phase): array
    {
        $input = $phase->input;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if (! is_array($pullRequest) || array_is_list($pullRequest)) {
            throw new OrbitImplementationDispatchFailed('The implementation correction has malformed pull request input.');
        }

        $normalized = [];

        foreach ($pullRequest as $key => $value) {
            if (! is_string($key)) {
                throw new OrbitImplementationDispatchFailed(
                    'The implementation correction has malformed pull request input.',
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function implementationPrompt(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $sourceReceipt,
    ): string {
        if ($phase->attempt === 1) {
            return $this->workflow->implementationPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $sourceReceipt->payload,
            );
        }

        if ($this->isPullRequestReviewCorrection($phase)) {
            $input = $phase->input;

            if (! is_array($input)) {
                throw new OrbitImplementationDispatchFailed(
                    'The pull request review correction has malformed review input.',
                );
            }

            $reviewReceipt = $this->associativeArray(
                $input['pr_review_receipt'] ?? null,
                'The pull request review correction has malformed review input.',
            );
            $publishedReview = $this->associativeArray(
                $input['published_review'] ?? null,
                'The pull request review correction has malformed publication input.',
            );

            return $this->workflow->pullRequestCorrectionPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $sourceReceipt->payload,
                $reviewReceipt,
                $this->pullRequestInput($phase),
                $publishedReview,
            );
        }

        return $this->workflow->implementationCorrectionPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $phase->id,
            $dispatch->id,
            $this->receiptCommand($phase, $dispatch),
            $sourceReceipt->payload,
            $this->pullRequestInput($phase),
        );
    }

    private function sourceReceipt(Delivery $delivery, PhaseRun $implementation): Receipt
    {
        return match ($implementation->attempt) {
            1 => $this->sourceReviewReceipt($delivery, $implementation),
            2 => $this->sourceImplementationReceipt($delivery, $implementation),
            default => throw new OrbitImplementationDispatchFailed(
                'The implementation attempt is not supported for retained-Builder dispatch.',
            ),
        };
    }

    private function sourceImplementationReceipt(Delivery $delivery, PhaseRun $correction): Receipt
    {
        $input = $correction->input;
        $receiptId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;

        if (! is_int($receiptId)) {
            throw new OrbitImplementationDispatchFailed('The implementation correction has malformed receipt input.');
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $source = $receipt?->phaseRun;
        $sourceDispatches = $source?->agentDispatches;
        $sourceDispatch = $sourceDispatches?->first();

        if ($receipt === null || $source === null || $sourceDispatches === null || $sourceDispatch === null
            || ! $this->implementationReceipts->matchesInput($delivery, $correction)
            || ! $this->implementationReceipts->matches($delivery, $source, $sourceDispatch, $receipt)) {
            throw new OrbitImplementationDispatchFailed(
                'The implementation correction no longer matches its published implementation receipt.',
            );
        }

        return $receipt;
    }

    private function sourceBuilderForPhase(
        Delivery $delivery,
        OrbitProjectConfig $config,
        PhaseRun $implementation,
        Receipt $sourceReceipt,
    ): AgentDispatch {
        if ($implementation->attempt === 1) {
            return $this->sourceBuilder($delivery, $config, $sourceReceipt);
        }

        $sourceImplementation = $sourceReceipt->phaseRun;
        $sourceDispatches = $sourceImplementation->agentDispatches;
        $sourceDispatch = $sourceDispatches->first();
        $reviewReceipt = $this->sourceReviewReceipt($delivery, $sourceImplementation);
        $builder = $this->sourceBuilder($delivery, $config, $reviewReceipt);

        if ($sourceDispatches->count() !== 1 || $sourceDispatch === null
            || ! $this->sameStoredAgent($sourceDispatch, $builder)) {
            throw new OrbitImplementationDispatchFailed(
                'The merge-conflict correction did not retain the exact implementation Builder.',
            );
        }

        return $builder;
    }

    private function sourceReviewReceipt(Delivery $delivery, PhaseRun $implementation): Receipt
    {
        $input = $implementation->input;
        $receiptId = is_array($input) ? ($input['plan_review_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['plan_review_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['plan_review_receipt_id', 'plan_review_receipt']) !== []
            || count($input) !== 2) {
            throw new OrbitImplementationDispatchFailed('The implementation has malformed plan-review input.');
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $review = $receipt?->phaseRun;
        $reviewDispatch = $review?->agentDispatches->first();
        $reviewCandidate = $payload['candidate_sha'] ?? null;
        $reviewDelivery = clone $delivery;
        $reviewDelivery->candidate_sha = is_string($reviewCandidate) ? $reviewCandidate : null;

        if ($receipt === null || $review === null || $reviewDispatch === null
            || $implementation->delivery_id !== $delivery->id
            || $implementation->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $implementation->attempt !== 1
            || $review->delivery_id !== $delivery->id
            || $review->phase_name !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            || ! in_array($review->attempt, [1, 2], true)
            || $review->status !== PhaseRunStatus::Completed
            || $review->output !== ['receipt_id' => $receipt->id, 'result' => 'pass']
            || $review->agentDispatches->count() !== 1
            || $reviewDispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            || $reviewDispatch->status !== AgentDispatchStatus::Settled
            || $receipt->payload !== $payload
            || ($payload['result'] ?? null) !== 'pass'
            || ! $this->reviewReceipts->matches($reviewDelivery, $review, $reviewDispatch, $receipt)) {
            throw new OrbitImplementationDispatchFailed('The implementation no longer matches its passing plan review.');
        }

        return $receipt;
    }

    private function sourceBuilder(
        Delivery $delivery,
        OrbitProjectConfig $config,
        Receipt $reviewReceipt,
    ): AgentDispatch {
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
            throw new OrbitImplementationDispatchFailed('The implementation has no exact retained Builder.');
        }

        $reviewAttempt = $reviewReceipt->phaseRun->attempt;
        $correction = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 2)
            ->first();

        if ($reviewAttempt === 1 && $correction !== null) {
            throw new OrbitImplementationDispatchFailed('The implementation has unexpected correction provenance.');
        }

        if ($reviewAttempt === 2) {
            $correctionDispatches = $correction?->agentDispatches()->get();
            $correctionBuilder = $correctionDispatches?->first();

            if ($correction === null || $correctionDispatches?->count() !== 1 || $correctionBuilder === null
                || $correction->status !== PhaseRunStatus::Completed
                || $correctionBuilder->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
                || $correctionBuilder->status !== AgentDispatchStatus::Settled
                || ! $this->sameStoredAgent($correctionBuilder, $builder)) {
                throw new OrbitImplementationDispatchFailed('The corrected plan did not retain the exact Builder.');
            }
        }

        return $builder;
    }

    private function assertDispatchIntent(Delivery $delivery, PhaseRun $phase, AgentDispatch $dispatch): void
    {
        $expectedKey = IdempotencyKey::forDispatch(
            $delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            $phase->attempt,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value;
        $expectedName = strtolower((string) $delivery->external_issue_key).'-loop-builder';
        $expectedPrompt = match (true) {
            $phase->attempt === 1 => 'orbit_implementation',
            $this->isPullRequestReviewCorrection($phase) => 'orbit_pr_review_correction',
            default => 'orbit_implementation_correction',
        };
        $expectedVersion = $phase->attempt === 1
            ? OrbitFeatureWorkflow::IMPLEMENTATION_PROMPT_VERSION
            : OrbitFeatureWorkflow::IMPLEMENTATION_CORRECTION_PROMPT_VERSION;

        if (! in_array($phase->attempt, [1, 2], true)
            || $phase->agentDispatches()->count() !== 1
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->idempotency_key !== $expectedKey
            || $dispatch->herdr_agent_name !== $expectedName
            || $dispatch->prompt_name !== $expectedPrompt
            || $dispatch->prompt_version !== $expectedVersion) {
            throw new OrbitImplementationDispatchFailed('The retained implementation intent is inconsistent.');
        }
    }

    private function isPullRequestReviewCorrection(PhaseRun $phase): bool
    {
        return $phase->attempt === 2
            && is_array($phase->input)
            && array_key_exists('pr_review_receipt_id', $phase->input);
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value, string $message): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new OrbitImplementationDispatchFailed($message);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new OrbitImplementationDispatchFailed($message);
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }

    private function assertDispatchPrompt(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $sourceReceipt,
    ): void {
        $this->assertDispatchIntent($delivery, $phase, $dispatch);
        $prompt = $this->implementationPrompt($delivery, $phase, $dispatch, $sourceReceipt);

        if (! hash_equals($dispatch->prompt_hash, hash('sha256', $prompt))) {
            throw new OrbitImplementationDispatchFailed('The retained implementation prompt is inconsistent.');
        }
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

    private function sameStoredAgent(AgentDispatch $left, AgentDispatch $right): bool
    {
        return $left->herdr_session === $right->herdr_session
            && $left->herdr_workspace_id === $right->herdr_workspace_id
            && $left->herdr_tab_id === $right->herdr_tab_id
            && $left->herdr_pane_id === $right->herdr_pane_id
            && $left->herdr_terminal_id === $right->herdr_terminal_id
            && $left->herdr_agent_id === $right->herdr_agent_id
            && $left->herdr_agent_name === $right->herdr_agent_name;
    }

    private function receiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-implementation-receipt %d %d',
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
                'code' => 'implementation_dispatch_interrupted',
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
            throw new OrbitImplementationDispatchFailed("The implementation has an invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitImplementationDispatchFailed("The implementation has an invalid {$key}.");
        }

        return $value;
    }
}
