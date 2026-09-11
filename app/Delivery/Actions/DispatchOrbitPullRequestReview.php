<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitImplementationReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Exception;
use Illuminate\Support\Facades\DB;

final readonly class DispatchOrbitPullRequestReview
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitActiveIssueProvider $issues,
        private OrbitReviewIssueTransitioner $transitions,
        private OrbitPullRequestInspector $pullRequests,
        private HerdrRuntime $herdr,
        private OrbitFeatureWorkflow $workflow,
        private OrbitImplementationReceiptValidator $receipts,
    ) {}

    public function handle(int $deliveryId, ?int $expectedPhaseId = null): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $this->assertLiveDelivery($delivery, $expectedPhaseId);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitPullRequestReviewDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The Orbit project does not use Commander\'s active Herdr session.',
            );
        }

        $preparation = $this->preparations->startup($delivery);
        [$dispatch, $receipt, $prompt] = $this->prepareDispatch($delivery, $config, $expectedPhaseId);

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
                $expectedPhaseId,
            );

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $this->verifyCandidateAndPullRequest($delivery, $config, $preparation, $receipt, $dispatch);
            $currentIssue = $this->issues->fetchActive(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue($preparation, $currentIssue, false);

            try {
                $reviewIssue = $this->transitions->transitionToInReview(
                    $currentIssue,
                    $preparation->snapshot->contractHash,
                );
            } catch (OrbitIssueTransitionFailed $exception) {
                $this->markBlocked(
                    $delivery,
                    $dispatch,
                    AgentDispatchStatus::Ambiguous,
                    'linear_pr_review_transition_ambiguous',
                    $exception,
                );

                throw new OrbitPullRequestReviewDispatchFailed(
                    'The Linear In Review transition outcome is unresolved.',
                    0,
                    $exception,
                );
            }

            $this->assertCurrentIssue($preparation, $reviewIssue, true);

            if (! $this->claim($dispatch)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'Another process already claimed the Orbit pull request review dispatch.',
                );
            }

            return $this->startAndPrompt($delivery, $dispatch, $prompt, $config, $preparation, $receipt);
        } finally {
            $reservation->release();
        }
    }

    private function assertLiveDelivery(Delivery $delivery, ?int $expectedPhaseId): void
    {
        $project = $delivery->projectOrchestration;
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->latest('attempt')
            ->first();
        $existing = $phase?->agentDispatches()->first();

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $project->state !== ProjectOrchestrationState::Enabled
            || $phase?->attempt !== 1
            || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)
            || ! in_array($delivery->status, [
                DeliveryStatus::Queued,
                DeliveryStatus::Preparing,
                DeliveryStatus::WaitingForAgent,
            ], true)
            || ($delivery->status === DeliveryStatus::WaitingForAgent
                && ($existing === null
                    || ! in_array($existing->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)))) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The delivery is not eligible for live Orbit pull request review dispatch.',
            );
        }
    }

    /** @return array{AgentDispatch, Receipt, string} */
    private function prepareDispatch(
        Delivery $delivery,
        OrbitProjectConfig $config,
        ?int $expectedPhaseId,
    ): array {
        return DB::transaction(function () use ($delivery, $config, $expectedPhaseId): array {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $project = $locked->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $locked->id)
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
            $phase = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();

            if ($phase === null || $phase->attempt !== 1
                || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained Orbit pull request review phase is inconsistent.',
                );
            }

            $dispatches = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->where('agent_role', OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
                ->get();
            $dispatch = $dispatches->first();

            if ($dispatches->count() !== 1 || $dispatch === null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained Orbit pull request review dispatch is inconsistent.',
                );
            }

            $receipt = $this->sourceReceipt($locked, $phase);
            $expectedKey = IdempotencyKey::forDispatch(
                $locked->id,
                $phase->phase_name,
                $phase->attempt,
                OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            )->value;

            if ($locked->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $locked->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $locked->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || ! in_array($locked->status, [
                    DeliveryStatus::Queued,
                    DeliveryStatus::Preparing,
                    DeliveryStatus::WaitingForAgent,
                ], true)
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $phase->agentDispatches()->count() !== 1
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->herdr_agent_name !== strtolower((string) $locked->external_issue_key).'-loop-pr-review-1'
                || $dispatch->prompt_name !== 'orbit_pr_review'
                || $dispatch->prompt_version !== 1) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained Orbit pull request review intent is inconsistent.',
                );
            }

            if ($locked->status === DeliveryStatus::Queued) {
                if ($phase->status !== PhaseRunStatus::Pending
                    || $dispatch->status !== AgentDispatchStatus::Pending) {
                    throw new OrbitPullRequestReviewDispatchFailed(
                        'The retained Orbit pull request review state is inconsistent.',
                    );
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
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained Orbit pull request review state is inconsistent.',
                );
            }

            $prompt = $this->workflow->pullRequestReviewPrompt(
                (string) $locked->external_issue_key,
                (string) $locked->worktree_path,
                $locked->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $receipt->payload,
                $this->pullRequestInput($phase),
            );
            $promptHash = hash('sha256', $prompt);

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = $promptHash;
                $dispatch->save();
            } elseif (! hash_equals($dispatch->prompt_hash, $promptHash)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained Orbit pull request review prompt metadata is inconsistent.',
                );
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
        ?int $expectedPhaseId,
    ): array {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
        $phase = $dispatch->phaseRun()->firstOrFail();

        if ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId) {
            throw new OrbitPullRequestReviewDispatchFailed('The queued pull request review phase is stale.');
        }

        $receipt = $this->sourceReceipt($delivery, $phase);

        if ($receipt->id !== $receiptId
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The live Orbit project or pull request review input changed before external mutation.',
            );
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

                throw new OrbitPullRequestReviewDispatchFailed(
                    'The prior pull request review dispatch was interrupted after startup began; manual recovery is required.',
                );
            }

            throw new OrbitPullRequestReviewDispatchFailed(
                'The Orbit pull request review dispatch changed before external mutation.',
            );
        }

        return [$delivery, $dispatch, $receipt];
    }

    private function verifyCandidateAndPullRequest(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $receipt,
        AgentDispatch $reviewDispatch,
    ): void {
        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::Preparing
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The live Orbit delivery changed before pull request review verification.',
            );
        }

        $payload = $receipt->payload;
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
        $this->assertVerifiedOutcome($receipt, $verified);
        $pullRequest = $this->pullRequests->inspect(
            $this->pullRequestNumber($delivery),
            (string) $delivery->external_issue_key,
            $verified->candidateSha,
            $this->string($payload, 'pull_request_body'),
        );
        $this->assertPullRequest($delivery, $verified, $pullRequest, $reviewDispatch);
    }

    private function assertVerifiedOutcome(Receipt $receipt, VerifiedOrbitImplementationOutcome $verified): void
    {
        $payload = $receipt->payload;

        if ($verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || $verified->artifactSha !== ($payload['artifact_sha'] ?? null)
            || $verified->gateReceiptPath !== ($payload['gate_receipt_path'] ?? null)
            || $verified->pullRequestBodyHash !== ($payload['pull_request_body_sha256'] ?? null)
            || $verified->flow !== ($payload['flow'] ?? null)) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The verified implementation no longer matches its immutable receipt.',
            );
        }
    }

    private function assertPullRequest(
        Delivery $delivery,
        VerifiedOrbitImplementationOutcome $verified,
        PublishedOrbitPullRequest $pullRequest,
        AgentDispatch $reviewDispatch,
    ): void {
        if ($pullRequest->number !== $delivery->pull_request_number
            || $pullRequest->url !== $delivery->pull_request_url
            || $pullRequest->candidateSha !== $verified->candidateSha
            || $pullRequest->bodyHash !== $verified->pullRequestBodyHash) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The published pull request no longer matches the review intent.',
            );
        }

        if ($pullRequest->mergeable === true) {
            return;
        }

        if ($pullRequest->mergeable === false) {
            $exception = new OrbitPullRequestReviewDispatchFailed(
                'The published pull request became unmergeable before independent review.',
            );
            $this->markBlocked(
                $delivery,
                $reviewDispatch,
                AgentDispatchStatus::Failed,
                'pr_review_mergeability_changed',
                $exception,
            );

            throw $exception;
        }

        throw new OrbitPullRequestReviewDispatchFailed(
            'GitHub has not resolved pull request mergeability for independent review.',
        );
    }

    private function assertCurrentIssue(
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
        bool $inReview,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $assignee = $issue->payload['assignee'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');
        $validState = is_array($state)
            && ($state['type'] ?? null) === 'started'
            && ($inReview
                ? ($state['name'] ?? null) === 'In Review'
                : in_array($state['name'] ?? null, ['In Progress', 'In Review'], true));
        $validAssignee = $inReview
            ? $assignee === null
            : $assignee === null || (is_array($assignee) && ($assignee['id'] ?? null) === $nickId);

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_string($viewerId) || ! is_string($nickId)
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || ! $validAssignee || ! $validState) {
            throw new OrbitIssueContractChanged(
                $inReview
                    ? 'The Orbit issue did not reach exact In Review ownership before review dispatch.'
                    : 'The Orbit issue changed before pull request review dispatch.',
            );
        }
    }

    private function claim(AgentDispatch $dispatch): bool
    {
        return AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update([
                'status' => AgentDispatchStatus::Starting,
                'error_code' => 'pr_review_dispatch_starting',
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

            throw new OrbitPullRequestReviewDispatchFailed('The Herdr worktree-open outcome is unresolved.', 0, $exception);
        }

        try {
            $pane = $this->herdr->splitPane($opened->paneId, $preparation->worktree->path);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pane_split_ambiguous', $exception);

            throw new OrbitPullRequestReviewDispatchFailed('The Herdr pane-split outcome is unresolved.', 0, $exception);
        }

        if ($pane->workspaceId !== $opened->workspaceId || $pane->tabId !== $opened->tabId
            || ! $this->independentFromBuilders($delivery, $pane, (string) $dispatch->herdr_agent_name)) {
            $exception = new OrbitPullRequestReviewDispatchFailed(
                'Herdr returned a pull request reviewer pane that is not independent from the Builder.',
            );
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pr_reviewer_identity_ambiguous', $exception);

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
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The delivery disappeared during final pull request review verification.',
                );
            }

            $freshDispatch = AgentDispatch::query()->whereKey($dispatch->id)->firstOrFail();
            $freshPhase = $freshDispatch->phaseRun()->firstOrFail();
            $freshReceipt = $this->sourceReceipt($freshDelivery, $freshPhase);

            if ($freshReceipt->id !== $receipt->id
                || $freshDispatch->status !== AgentDispatchStatus::Starting
                || $freshDispatch->error_code !== 'pr_review_final_verification'
                || ! $this->independentFromBuilders($freshDelivery, $started, $started->agentName)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The pull request review ledger changed before final verification.',
                );
            }

            $this->verifyCandidateAndPullRequest(
                $freshDelivery,
                $config,
                $preparation,
                $freshReceipt,
                $freshDispatch,
            );
            $issue = $this->issues->fetchActive(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $issue = $this->transitions->transitionToInReview(
                $issue,
                $preparation->snapshot->contractHash,
            );
            $this->assertCurrentIssue($preparation, $issue, true);
        } catch (Exception $exception) {
            $this->markBlocked(
                $delivery,
                $dispatch,
                AgentDispatchStatus::Ambiguous,
                'pr_review_final_verification_failed',
                $exception,
            );

            throw new OrbitPullRequestReviewDispatchFailed(
                'Final pull request review verification failed before prompting the independent reviewer.',
                0,
                $exception,
            );
        }

        $this->markPromptAttempted($delivery->id, $dispatch->id, $receipt->id, $config);

        try {
            $prompted = $this->herdr->promptAgent($started->agentName, $prompt);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_ambiguous', $exception);

            throw new OrbitPullRequestReviewDispatchFailed(
                'The Herdr prompt outcome is unresolved; Commander will not submit it again automatically.',
                0,
                $exception,
            );
        }

        if (! $this->sameAgent($prompted, $dispatch)) {
            $exception = new OrbitPullRequestReviewDispatchFailed(
                'Herdr prompted an agent outside the recorded pull request review dispatch.',
            );
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_identity_ambiguous', $exception);

            throw $exception;
        }

        DB::transaction(function () use ($delivery, $dispatch, $prompted): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $promptWasAttempted = $lockedDispatch->status === AgentDispatchStatus::Starting
                && $lockedDispatch->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted && $lockedDispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The pull request review dispatch changed while its prompt was submitted.',
                );
            }

            $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
            $lockedDispatch->error_code = null;
            $lockedDispatch->error_message = null;

            if ($promptWasAttempted) {
                $lockedDispatch->status = AgentDispatchStatus::Waiting;
            }

            $lockedDispatch->save();

            if ($lockedDelivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
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

                throw new OrbitPullRequestReviewDispatchFailed(
                    'The Herdr agent-start outcome is unresolved.',
                    0,
                    $startFailure,
                );
            }
        }

        if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)
            || ! $this->independentFromBuilders($delivery, $started, $started->agentName)) {
            $exception = new OrbitPullRequestReviewDispatchFailed(
                'Herdr returned an agent that is not independent from the Builder.',
            );
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pr_reviewer_identity_ambiguous', $exception);

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
            'error_code' => 'pr_review_final_verification',
            'error_message' => null,
        ])->save();
    }

    private function markPromptAttempted(
        int $deliveryId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use ($deliveryId, $dispatchId, $receiptId, $config): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->where('attempt', 1)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $source = $this->sourceReceipt($delivery, $phase);

            if ($project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $delivery->status !== DeliveryStatus::Preparing
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Starting
                || $dispatch->error_code !== 'pr_review_final_verification'
                || $receipt->id !== $source->id) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The pull request review ledger changed before prompt submission.',
                );
            }

            $dispatch->error_code = 'herdr_prompt_attempted';
            $dispatch->error_message = null;
            $dispatch->save();
        });
    }

    private function independentFromBuilders(
        Delivery $delivery,
        HerdrAgentIdentifiers $reviewer,
        string $reviewerName,
    ): bool {
        $builders = AgentDispatch::query()
            ->whereHas('phaseRun', fn ($query) => $query
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE))
            ->get();

        foreach ($builders as $builder) {
            if ($builder->herdr_agent_name === $reviewerName
                || ($builder->herdr_pane_id !== null && $builder->herdr_pane_id === $reviewer->paneId)
                || ($builder->herdr_agent_id !== null && $builder->herdr_agent_id === $reviewer->agentId)) {
                return false;
            }
        }

        return true;
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
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
    }

    /** @return array<string, mixed> */
    private function pullRequestInput(PhaseRun $phase): array
    {
        $input = $phase->input;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if (! is_array($pullRequest) || array_is_list($pullRequest)) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The pull request review phase has malformed pull request input.',
            );
        }

        $normalized = [];

        foreach ($pullRequest as $key => $value) {
            if (! is_string($key)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The pull request review phase has malformed pull request input.',
                );
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function sourceReceipt(Delivery $delivery, PhaseRun $review): Receipt
    {
        $input = $review->input;
        $receiptId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || ! is_array($pullRequest) || array_is_list($pullRequest)
            || array_diff(array_keys($input), [
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
            ]) !== []
            || count($input) !== 3) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The pull request review phase has invalid immutable input.',
            );
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $implementation = $receipt?->phaseRun;
        $dispatch = $implementation?->agentDispatches->first();
        $latest = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
            ->first();
        $expectedPrompt = $implementation?->attempt === 2
            ? 'orbit_implementation_correction'
            : 'orbit_implementation';

        if ($receipt === null || $implementation === null || $dispatch === null
            || $review->delivery_id !== $delivery->id
            || $review->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $review->attempt !== 1
            || $implementation->delivery_id !== $delivery->id
            || $implementation->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || ! in_array($implementation->attempt, [1, 2], true)
            || $latest?->id !== $implementation->id
            || $implementation->status !== PhaseRunStatus::Completed
            || $implementation->finished_at === null
            || $implementation->output !== [
                'receipt_id' => $receipt->id,
                'result' => 'ready',
                'pull_request_number' => $delivery->pull_request_number,
                'pull_request_url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]
            || $implementation->agentDispatches->count() !== 1
            || $implementation->receipts()->where('kind', 'orbit_implementation')->count() !== 1
            || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->idempotency_key !== IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                $implementation->attempt,
                OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            )->value
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
            || $dispatch->prompt_name !== $expectedPrompt
            || $dispatch->prompt_version !== 1
            || preg_match('/^[a-f0-9]{64}$/', $dispatch->prompt_hash) !== 1
            || $dispatch->prompt_hash === str_repeat('0', 64)
            || $dispatch->dispatched_at === null || $dispatch->settled_at === null
            || $receipt->payload !== $payload
            || ($payload['result'] ?? null) !== 'ready'
            || ($payload['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || $pullRequest !== [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]
            || ! $this->matchesReceipt($delivery, $implementation, $dispatch, $receipt)) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The immutable implementation receipt no longer matches the pull request review intent.',
            );
        }

        return $receipt;
    }

    private function matchesReceipt(
        Delivery $delivery,
        PhaseRun $implementation,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        if ($implementation->attempt !== 2) {
            return $this->receipts->matches($delivery, $implementation, $dispatch, $receipt);
        }

        $input = $implementation->input;
        $source = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $sourceCandidate = is_array($source) ? ($source['candidate_sha'] ?? null) : null;

        if (! is_string($sourceCandidate)) {
            return false;
        }

        $sourceDelivery = clone $delivery;
        $sourceDelivery->candidate_sha = $sourceCandidate;

        return $this->receipts->matches($sourceDelivery, $implementation, $dispatch, $receipt);
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
                'code' => 'pr_review_dispatch_interrupted',
                'dispatch_id' => $dispatch->id,
                'stage' => $dispatch->error_code,
            ];
            $locked->save();
        });
    }

    private function pullRequestNumber(Delivery $delivery): int
    {
        $number = $delivery->pull_request_number;

        if (! is_int($number) || $number < 1) {
            throw new OrbitPullRequestReviewDispatchFailed('The delivery has no valid pull request number.');
        }

        return $number;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitPullRequestReviewDispatchFailed("The implementation receipt has an invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitPullRequestReviewDispatchFailed("The implementation receipt has an invalid {$key}.");
        }

        return $value;
    }
}
