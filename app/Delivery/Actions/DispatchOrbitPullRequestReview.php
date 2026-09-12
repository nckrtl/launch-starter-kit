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
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Herdr\RequestFailed;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

final readonly class DispatchOrbitPullRequestReview
{
    private const int START_RECOVERY_ATTEMPTS = 9;

    private const int START_RECOVERY_DELAY_MICROSECONDS = 250_000;

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
        private OrbitPullRequestReviewSourceValidator $sources,
        private OrbitIssueSnapshotFactory $snapshots,
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
            $this->assertCurrentIssue($delivery, $preparation, $currentIssue, false);

            try {
                $reviewIssue = $this->transitions->transitionToInReview(
                    $currentIssue,
                    $currentIssue->contractHash,
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

            $this->assertCurrentIssue($delivery, $preparation, $reviewIssue, true);

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

    public function recoverInterrupted(int $deliveryId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

        if ($delivery === null) {
            throw new OrbitPullRequestReviewDispatchFailed('The Orbit delivery does not exist.');
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig || $config->herdrSession !== config('herdr.session')) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The blocked delivery does not use Commander\'s active Orbit configuration.',
            );
        }

        $preparation = $this->preparations->startup($delivery);
        [$phase, $dispatch, $receipt, $prompt] = $this->prepareInterruptedRecovery(
            $delivery->id,
            $config,
        );
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($delivery->id);
            $this->assertInterruptedRecoveryLedger($delivery, $phase->id, $dispatch->id, $receipt->id, $config);
            $this->verifyCandidateAndPullRequest(
                $delivery,
                $config,
                $preparation,
                $receipt,
                $dispatch,
                DeliveryStatus::Blocked,
                false,
            );
            $issue = $this->issues->fetchActive(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue($delivery, $preparation, $issue, true);

            try {
                $agent = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (Exception $exception) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained pull request reviewer could not be verified.',
                    0,
                    $exception,
                );
            }

            if (! $this->sameRecoveredAgent($agent, $dispatch)
                || $agent->workingDirectory !== $preparation->worktree->path
                || ! in_array($agent->agentStatus, ['idle', 'done'], true)
                || ! $this->independentFromBuilders($delivery, $agent, $agent->agentName)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained pull request reviewer is not safe to resume.',
                );
            }

            $this->markRecoveryPromptAttempted(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
                $agent,
            );

            try {
                $prompted = $this->herdr->promptAgent($agent->agentName, $prompt);
            } catch (Exception $exception) {
                $this->markBlocked(
                    $delivery,
                    $dispatch,
                    AgentDispatchStatus::Ambiguous,
                    'herdr_prompt_ambiguous',
                    $exception,
                );

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
                $this->markBlocked(
                    $delivery,
                    $dispatch,
                    AgentDispatchStatus::Ambiguous,
                    'herdr_prompt_identity_ambiguous',
                    $exception,
                );

                throw $exception;
            }

            $this->completePrompt($delivery->id, $dispatch->id, $prompted);

            return $dispatch->refresh();
        } finally {
            $reservation->release();
        }
    }

    public function recoverAmbiguousStart(int $deliveryId, int $expectedPhaseId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

        if ($delivery === null) {
            throw new OrbitPullRequestReviewDispatchFailed('The Orbit delivery does not exist.');
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig || $config->herdrSession !== config('herdr.session')) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The blocked delivery does not use Commander\'s active Orbit configuration.',
            );
        }

        $preparation = $this->preparations->startup($delivery);
        [$phase, $dispatch, $receipt, $prompt] = $this->prepareAmbiguousStartRecovery(
            $delivery->id,
            $expectedPhaseId,
            $config,
        );
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($delivery->id);
            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
            );
            $this->verifyCandidateAndPullRequest(
                $delivery,
                $config,
                $preparation,
                $receipt,
                $dispatch,
                DeliveryStatus::Blocked,
                false,
            );
            $issue = $this->issues->fetchActive(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue($delivery, $preparation, $issue, true);

            $pane = new HerdrAgentIdentifiers(
                workspaceId: (string) $dispatch->herdr_workspace_id,
                tabId: (string) $dispatch->herdr_tab_id,
                paneId: (string) $dispatch->herdr_pane_id,
                terminalId: (string) $dispatch->herdr_terminal_id,
                agentId: null,
                agentName: (string) $dispatch->herdr_agent_name,
            );
            $started = $this->recoverAgentStart(
                $dispatch,
                $pane,
                new RequestFailed((string) $dispatch->error_message, 'agent_pane_busy'),
            );
            $this->persistRecoveredStart(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
                $started,
            );

            return $this->verifyAndPromptStarted(
                $delivery,
                $dispatch->refresh(),
                $started,
                $prompt,
                $config,
                $preparation,
                $receipt,
            );
        } finally {
            $reservation->release();
        }
    }

    public function bindAmbiguousStartRecovery(int $deliveryId): ?int
    {
        try {
            $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

            if ($delivery === null) {
                return null;
            }

            $config = $this->configs->hydrate($delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig || $config->herdrSession !== config('herdr.session')) {
                return null;
            }

            $phase = $delivery->phaseRuns()
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->latest('attempt')
                ->first();

            if ($phase === null) {
                return null;
            }

            [$bound] = $this->prepareAmbiguousStartRecovery($delivery->id, $phase->id, $config);

            return $bound->id;
        } catch (Exception) {
            return null;
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
            || $phase === null
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

            if ($phase === null || $phase->attempt < 1
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
                || $dispatch->herdr_agent_name !== strtolower((string) $locked->external_issue_key).'-loop-pr-review-'.$phase->attempt
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
        DeliveryStatus $expectedStatus = DeliveryStatus::Preparing,
        bool $blockOnUnmergeable = true,
    ): void {
        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== $expectedStatus
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
        $this->assertPullRequest(
            $delivery,
            $verified,
            $pullRequest,
            $reviewDispatch,
            $blockOnUnmergeable,
        );
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
        bool $blockOnUnmergeable,
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

            if ($blockOnUnmergeable) {
                $this->markBlocked(
                    $delivery,
                    $reviewDispatch,
                    AgentDispatchStatus::Failed,
                    'pr_review_mergeability_changed',
                    $exception,
                );
            }

            throw $exception;
        }

        throw new OrbitPullRequestReviewDispatchFailed(
            'GitHub has not resolved pull request mergeability for independent review.',
        );
    }

    private function assertCurrentIssue(
        Delivery $delivery,
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
            || ! $this->snapshots->matchesExpectedContract(
                $issue,
                $preparation->snapshot->contractHash,
                $delivery->pull_request_url,
            )
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

    /** @return array{PhaseRun, AgentDispatch, Receipt, string} */
    private function prepareInterruptedRecovery(int $deliveryId, OrbitProjectConfig $config): array
    {
        return DB::transaction(function () use ($deliveryId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
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

            if ($phase === null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The interrupted pull request review phase is missing.',
                );
            }

            $dispatch = $phase->agentDispatches()->first();

            if ($dispatch === null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The interrupted pull request review dispatch is missing.',
                );
            }

            $receipt = $this->sourceReceipt($delivery, $phase);
            $this->assertInterruptedRecoveryLedger(
                $delivery,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
            );
            $prompt = $this->workflow->pullRequestReviewPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $receipt->payload,
                $this->pullRequestInput($phase),
            );

            if (! hash_equals($dispatch->prompt_hash, hash('sha256', $prompt))) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The interrupted pull request review prompt no longer matches its ledger.',
                );
            }

            return [$phase, $dispatch, $receipt, $prompt];
        });
    }

    /** @return array{PhaseRun, AgentDispatch, Receipt, string} */
    private function prepareAmbiguousStartRecovery(
        int $deliveryId,
        int $expectedPhaseId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $expectedPhaseId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
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
            $dispatch = $phase?->agentDispatches()->first();

            if ($phase === null || $dispatch === null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The ambiguous pull request reviewer start ledger is incomplete.',
                );
            }

            $receipt = $this->sourceReceipt($delivery, $phase);
            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $expectedPhaseId,
                $dispatch->id,
                $receipt->id,
                $config,
            );
            $prompt = $this->workflow->pullRequestReviewPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $receipt->payload,
                $this->pullRequestInput($phase),
            );

            if (! hash_equals($dispatch->prompt_hash, hash('sha256', $prompt))) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The ambiguous pull request reviewer prompt no longer matches its ledger.',
                );
            }

            return [$phase, $dispatch, $receipt, $prompt];
        });
    }

    private function assertAmbiguousStartRecoveryLedger(
        Delivery $delivery,
        int $phaseId,
        ?int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
    ): void {
        $project = $delivery->projectOrchestration()->firstOrFail();
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->latest('attempt')
            ->first();
        $dispatches = $phase?->agentDispatches()
            ->where('agent_role', OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
            ->get();
        $dispatch = $dispatches?->first();
        $failure = $delivery->failure_details;
        $source = $phase === null ? null : $this->sources->sourceReceipt($delivery, $phase);
        $expectedKey = $phase === null ? null : IdempotencyKey::forDispatch(
            $delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            $phase->attempt,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value;
        $expectedPromptHash = null;

        if ($phase !== null && $dispatch !== null && $source !== null) {
            $prompt = $this->workflow->pullRequestReviewPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $source->payload,
                $this->pullRequestInput($phase),
            );
            $expectedPromptHash = hash('sha256', $prompt);
        }

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || ! is_array($failure)
            || ($failure['code'] ?? null) !== 'herdr_start_ambiguous'
            || ($failure['dispatch_id'] ?? null) !== $dispatchId
            || ($failure['message'] ?? null) !== $dispatch?->error_message
            || $project->state !== ProjectOrchestrationState::Enabled
            || $project->config !== $config->toArray()
            || $phase === null
            || $phase->id !== $phaseId
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->receipts()->exists()
            || $phase->agentDispatches()->count() !== 1
            || $source === null
            || $source->id !== $receiptId
            || $dispatches?->count() !== 1
            || $dispatch === null
            || $dispatch->id !== $dispatchId
            || $dispatch->idempotency_key !== $expectedKey
            || $dispatch->status !== AgentDispatchStatus::Ambiguous
            || $dispatch->error_code !== 'herdr_start_ambiguous'
            || ! is_string($dispatch->error_message)
            || ! str_ends_with($dispatch->error_message, ' (agent_pane_busy)')
            || $dispatch->herdr_session !== $config->herdrSession
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->herdr_agent_id !== null
            || $dispatch->state_change_seq !== null
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-pr-review-'.$phase->attempt
            || $dispatch->prompt_name !== 'orbit_pr_review'
            || $dispatch->prompt_version !== 1
            || preg_match('/^[a-f0-9]{64}$/', $dispatch->prompt_hash) !== 1
            || $expectedPromptHash === null
            || ! hash_equals($dispatch->prompt_hash, $expectedPromptHash)
            || $dispatch->dispatched_at !== null
            || $dispatch->settled_at !== null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The ambiguous pull request reviewer start is not safe to recover.',
            );
        }
    }

    private function assertInterruptedRecoveryLedger(
        Delivery $delivery,
        int $phaseId,
        ?int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
    ): void {
        $project = $delivery->projectOrchestration()->firstOrFail();
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->latest('attempt')
            ->first();
        $dispatches = $phase?->agentDispatches()
            ->where('agent_role', OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
            ->get();
        $dispatch = $dispatches?->first();
        $failure = $delivery->failure_details;
        $source = $phase === null ? null : $this->sources->sourceReceipt($delivery, $phase);
        $expectedKey = $phase === null ? null : IdempotencyKey::forDispatch(
            $delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            $phase->attempt,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value;
        $expectedPromptHash = null;

        if ($phase !== null && $dispatch !== null && $source !== null) {
            $prompt = $this->workflow->pullRequestReviewPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $this->receiptCommand($phase, $dispatch),
                $source->payload,
                $this->pullRequestInput($phase),
            );
            $expectedPromptHash = hash('sha256', $prompt);
        }

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || ! is_array($failure)
            || ($failure['code'] ?? null) !== 'pr_review_dispatch_interrupted'
            || ($failure['dispatch_id'] ?? null) !== $dispatchId
            || ($failure['stage'] ?? null) !== 'pr_review_final_verification'
            || $project->state !== ProjectOrchestrationState::Enabled
            || $project->config !== $config->toArray()
            || $phase === null
            || $phase->id !== $phaseId
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->receipts()->exists()
            || $phase->agentDispatches()->count() !== 1
            || $source === null
            || $source->id !== $receiptId
            || $dispatches?->count() !== 1
            || $dispatch === null
            || $dispatch->id !== $dispatchId
            || $dispatch->idempotency_key !== $expectedKey
            || $dispatch->status !== AgentDispatchStatus::Starting
            || $dispatch->error_code !== 'pr_review_final_verification'
            || $dispatch->error_message !== null
            || $dispatch->herdr_session !== $config->herdrSession
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->state_change_seq === null
            || $dispatch->state_change_seq < 0
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-pr-review-'.$phase->attempt
            || $dispatch->prompt_name !== 'orbit_pr_review'
            || $dispatch->prompt_version !== 1
            || preg_match('/^[a-f0-9]{64}$/', $dispatch->prompt_hash) !== 1
            || $expectedPromptHash === null
            || ! hash_equals($dispatch->prompt_hash, $expectedPromptHash)
            || $dispatch->dispatched_at === null
            || $dispatch->settled_at !== null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The interrupted pull request review dispatch is not safe to recover.',
            );
        }
    }

    private function markRecoveryPromptAttempted(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $agent,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $config,
            $agent,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
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
            $this->assertInterruptedRecoveryLedger(
                $delivery,
                $phaseId,
                $dispatchId,
                $receiptId,
                $config,
            );
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();

            if (! $this->sameRecoveredAgent($agent, $dispatch)
                || $agent->workingDirectory !== $delivery->worktree_path
                || ! in_array($agent->agentStatus, ['idle', 'done'], true)
                || ! $this->independentFromBuilders($delivery, $agent, $agent->agentName)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The retained pull request reviewer changed before prompt submission.',
                );
            }

            $dispatch->forceFill([
                'herdr_agent_id' => $agent->agentId ?? $dispatch->herdr_agent_id,
                'state_change_seq' => $agent->stateChangeSeq,
                'error_code' => 'herdr_prompt_attempted',
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Preparing,
                'failure_details' => null,
            ])->save();
        });
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

        return $this->verifyAndPromptStarted(
            $delivery,
            $dispatch,
            $started,
            $prompt,
            $config,
            $preparation,
            $receipt,
        );
    }

    private function verifyAndPromptStarted(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $started,
        string $prompt,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $receipt,
    ): AgentDispatch {

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
                $issue->contractHash,
            );
            $this->assertCurrentIssue($freshDelivery, $preparation, $issue, true);
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

        $this->completePrompt($delivery->id, $dispatch->id, $prompted);

        return $dispatch->refresh();
    }

    private function completePrompt(
        int $deliveryId,
        int $dispatchId,
        HerdrAgentIdentifiers $prompted,
    ): void {
        DB::transaction(function () use ($deliveryId, $dispatchId, $prompted): void {
            $lockedDelivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $promptWasAttempted = $lockedDispatch->status === AgentDispatchStatus::Starting
                && $lockedDispatch->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted && $lockedDispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The pull request review dispatch changed while its prompt was submitted.',
                );
            }

            $lockedDispatch->herdr_agent_id = $prompted->agentId ?? $lockedDispatch->herdr_agent_id;
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
                $started = $this->recoverAgentStart($dispatch, $pane, $startFailure);
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

    private function recoverAgentStart(
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $pane,
        Exception $initialFailure,
    ): HerdrAgentIdentifiers {
        $failure = $initialFailure;
        $mayStart = $initialFailure instanceof RequestFailed
            && $initialFailure->errorCode === 'agent_pane_busy';

        for ($attempt = 0; $attempt < self::START_RECOVERY_ATTEMPTS; $attempt++) {
            try {
                return $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (RequestFailed $readFailure) {
                if ($readFailure->errorCode !== 'agent_not_found') {
                    throw $readFailure;
                }
            }

            if ($mayStart) {
                try {
                    return $this->herdr->startAgent(
                        $pane->paneId,
                        (string) $dispatch->herdr_agent_name,
                        $this->reviewLaunch(),
                    );
                } catch (Exception $startFailure) {
                    $failure = $startFailure;
                    $mayStart = $startFailure instanceof RequestFailed
                        && $startFailure->errorCode === 'agent_pane_busy';
                }
            }

            if ($attempt < self::START_RECOVERY_ATTEMPTS - 1) {
                Sleep::usleep(self::START_RECOVERY_DELAY_MICROSECONDS);
            }
        }

        throw new OrbitPullRequestReviewDispatchFailed(
            'The retained Herdr reviewer did not become recoverable within the bounded start window.',
            0,
            $failure,
        );
    }

    private function persistRecoveredStart(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $started,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $config,
            $started,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
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
            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $phaseId,
                $dispatchId,
                $receiptId,
                $config,
            );
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
            $pane = new HerdrAgentIdentifiers(
                workspaceId: (string) $dispatch->herdr_workspace_id,
                tabId: (string) $dispatch->herdr_tab_id,
                paneId: (string) $dispatch->herdr_pane_id,
                terminalId: (string) $dispatch->herdr_terminal_id,
                agentId: null,
                agentName: (string) $dispatch->herdr_agent_name,
            );

            if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)
                || $started->workingDirectory !== $delivery->worktree_path
                || ! in_array($started->agentStatus, ['idle', 'done'], true)
                || ! $this->independentFromBuilders($delivery, $started, $started->agentName)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The recovered Herdr reviewer does not match the retained start intent.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Starting,
                'herdr_agent_id' => $started->agentId,
                'state_change_seq' => $started->stateChangeSeq,
                'dispatched_at' => now(),
                'error_code' => 'pr_review_final_verification',
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Preparing,
                'failure_details' => null,
            ])->save();
        });
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
                ->latest('attempt')
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
            && ($agent->agentId === null
                || $dispatch->herdr_agent_id === null
                || $agent->agentId === $dispatch->herdr_agent_id)
            && $agent->agentName === $dispatch->herdr_agent_name;
    }

    private function sameRecoveredAgent(HerdrAgentIdentifiers $agent, AgentDispatch $dispatch): bool
    {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && ($dispatch->herdr_agent_id === null
                || ($agent->agentId !== null && $agent->agentId === $dispatch->herdr_agent_id))
            && $agent->agentName === $dispatch->herdr_agent_name
            && $agent->stateChangeSeq !== null
            && $dispatch->state_change_seq !== null
            && $agent->stateChangeSeq >= $dispatch->state_change_seq;
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
        $receipt = $this->sources->sourceReceipt($delivery, $review);

        if ($receipt === null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The immutable implementation receipt no longer matches the pull request review intent.',
            );
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
