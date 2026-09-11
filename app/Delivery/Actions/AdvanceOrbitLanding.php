<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitLandingReservation;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitMergeLineage;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Exceptions\OrbitPullRequestLandingFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitLanding
{
    public const int RETRY_SECONDS = 5;

    public const int MAINTENANCE_RETRY_SECONDS = 30;

    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitActiveIssueProvider $issues,
        private OrbitMainCorrectnessInspector $main,
        private OrbitPullRequestLandingGateway $pullRequests,
        private OrbitMergeLineageVerifier $merges,
        private OrbitPrimaryCheckoutReconciler $primaryCheckout,
        private OrbitPullRequestReviewReceiptValidator $reviewReceipts,
        private OrbitPullRequestReviewSourceValidator $sources,
        private QueueOrbitMainCacheRefresh $cacheRefresh,
        private ShutdownOrbitHerdrWorkspace $workspaceShutdown,
    ) {}

    /** Return a delay when the same queued job should retry a non-failing wait. */
    public function handle(int $deliveryId, ?int $expectedPhaseId = null): ?int
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
            throw new OrbitLandingAdvancementFailed('The delivery is not an Orbit feature workflow.');
        }

        $intent = $this->landingIntent($delivery, $expectedPhaseId);

        if ($intent === null) {
            return null;
        }

        if ($this->isCompleted($delivery, $intent[0])) {
            $this->cacheRefresh->handle($delivery->id, $intent[0]->id);

            return null;
        }

        if (in_array($delivery->status, [DeliveryStatus::Merging, DeliveryStatus::Landed], true)) {
            return $this->resumePostMerge($delivery, $intent[0]);
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit project is not enabled with valid configuration.',
            );
        }

        $preparation = $this->preparations->startup($delivery);
        $issueId = $preparation->snapshot->issueId;
        $pullRequestUrl = $this->pullRequestUrl($delivery);
        $issueReservation = $this->repository->reserveDelivery(
            $config,
            $preparation->snapshot->issueKey,
        );
        $mergeReservationOwned = false;
        $retainMergeReservation = false;

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
            $intent = $this->landingIntent($delivery, $expectedPhaseId);

            if ($intent === null || ($expectedPhaseId !== null && $intent[0]->id !== $expectedPhaseId)
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed before advancement.',
                );
            }

            [$phase, $review, $implementation] = $intent;
            $verified = $this->verifyImplementation($config, $preparation, $implementation);
            $this->assertCurrentIssue(
                $preparation,
                $this->issues->fetchActive(
                    $preparation->snapshot->issueId,
                    $preparation->snapshot->issueKey,
                ),
            );
            $main = $this->main->inspectMainCorrectness($config);

            if (! $main->passed()) {
                $this->markMainHold($delivery->id, $phase->id, $config, $main);

                return self::MAINTENANCE_RETRY_SECONDS;
            }

            $reservation = $this->pullRequests->reserve(
                $issueId,
                $pullRequestUrl,
            );

            if (! $reservation->owned) {
                $this->markReservationWait($delivery->id, $phase->id, $config, $reservation);

                return self::RETRY_SECONDS;
            }

            $mergeReservationOwned = true;

            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
            $current = $this->landingIntent($delivery, $phase->id);

            if ($current === null || $current[1]->id !== $review->id
                || $current[2]->id !== $implementation->id
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed after merge reservation.',
                );
            }

            $main = $this->main->inspectMainCorrectness($config);

            if (! $main->passed()) {
                $this->markMainHold($delivery->id, $phase->id, $config, $main);

                return self::MAINTENANCE_RETRY_SECONDS;
            }

            $approved = $this->pullRequests->inspectApproved(
                $this->pullRequestNumber($delivery),
                $preparation->snapshot->issueKey,
                $verified->candidateSha,
                $this->string($review->payload, 'pull_request_body'),
                $this->publishedReviewId($phase),
            );
            $this->assertApproved($delivery, $phase, $review, $verified, $approved);

            if ($approved->mergeable === null) {
                $this->markMergeabilityWait($delivery->id, $phase->id, $config);

                return self::RETRY_SECONDS;
            }

            if (! $approved->mergeable) {
                $mergeReservationOwned = false;
                $this->pullRequests->release($issueId, $pullRequestUrl);
                $this->markMergeConflict($delivery->id, $phase->id, $config);

                return null;
            }

            $preMerge = $this->markMerging(
                $delivery->id,
                $phase->id,
                $config,
                $approved,
                $reservation,
                $main,
            );
            $retainMergeReservation = true;
            $merged = $this->pullRequests->merge($approved->number, $approved->candidateSha);
            $this->commitMerged($delivery->id, $phase->id, $preMerge, $merged);

            return $this->reconcileAndFinalize($config, $delivery->id, $phase->id, false);
        } finally {
            if ($mergeReservationOwned && ! $retainMergeReservation) {
                $this->pullRequests->release(
                    $issueId,
                    $pullRequestUrl,
                );
            }

            $issueReservation->release();
        }
    }

    /** @return array{PhaseRun, Receipt, Receipt}|null */
    private function landingIntent(Delivery $delivery, ?int $expectedPhaseId): ?array
    {
        if ($delivery->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE) {
            return null;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)
            ->where('attempt', 1)
            ->first();

        if ($phase === null || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)) {
            return null;
        }

        $input = $phase->input;
        $reviewId = is_array($input) ? ($input['pr_review_receipt_id'] ?? null) : null;
        $implementationId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;
        $review = is_int($reviewId) ? Receipt::query()->find($reviewId) : null;
        $reviewPhase = $review?->phaseRun()->first();
        $reviewDispatches = $reviewPhase?->agentDispatches()->get();
        $reviewDispatch = $reviewDispatches?->first();
        $implementation = $reviewPhase === null
            ? null
            : $this->sources->sourceReceipt($delivery, $reviewPhase, true);
        $published = is_array($input) ? ($input['published_review'] ?? null) : null;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        $published = $this->associativeArray($published);
        $pullRequest = $this->associativeArray($pullRequest);

        if (! is_array($input)
            || ! is_int($implementationId) || $published === null
            || $pullRequest === null
            || $review === null || $reviewPhase === null || $reviewDispatches === null
            || $reviewDispatch === null || $implementation === null
            || $phase->delivery_id !== $delivery->id || $phase->attempt !== 1
            || $phase->agentDispatches()->exists() || $phase->receipts()->exists()
            || $reviewPhase->delivery_id !== $delivery->id
            || $reviewPhase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || ! in_array($reviewPhase->attempt, [1, 2], true)
            || $reviewPhase->status !== PhaseRunStatus::Completed
            || $reviewPhase->finished_at === null || $reviewDispatches->count() !== 1
            || $review->phase_run_id !== $reviewPhase->id
            || ($review->payload['result'] ?? null) !== 'approved'
            || $implementation->id !== $implementationId
            || ! $this->reviewReceipts->matches(
                $delivery,
                $reviewPhase,
                $reviewDispatch,
                $review,
                true,
            )
            || ! $this->matchesPublishedReview($delivery, $review, $published)
            || $reviewPhase->output !== [
                'receipt_id' => $review->id,
                'result' => 'approved',
                'published_review' => $published,
            ]
            || $pullRequest !== [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]
            || $input !== [
                'pr_review_receipt_id' => $review->id,
                'pr_review_receipt' => $review->payload,
                'implementation_receipt_id' => $implementation->id,
                'implementation_receipt' => $implementation->payload,
                'pull_request' => $pullRequest,
                'published_review' => $published,
            ]
            || ! $this->matchesLandingState(
                $delivery,
                $phase,
                $published,
                $implementation->payload['flow'] ?? null,
            )) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit landing intent is inconsistent.',
            );
        }

        return [$phase, $review, $implementation];
    }

    /** @param array<string, mixed> $published */
    private function matchesLandingState(
        Delivery $delivery,
        PhaseRun $phase,
        array $published,
        mixed $implementationFlow,
    ): bool {
        if ($delivery->status === DeliveryStatus::ReadyToMerge) {
            return $phase->status === PhaseRunStatus::Pending
                && $phase->current_block === null && $phase->output === null
                && $phase->started_at === null && $phase->finished_at === null;
        }

        if ($delivery->status === DeliveryStatus::Merging) {
            return $phase->status === PhaseRunStatus::Running
                && $phase->current_block === 'merge' && $phase->started_at !== null
                && $phase->finished_at === null
                && $this->matchesPreMergeOutput($delivery, $phase->output, $published);
        }

        if ($delivery->status !== DeliveryStatus::Landed) {
            return false;
        }

        $activeBlock = $phase->status === PhaseRunStatus::Running
            && in_array($phase->current_block, [
                'merge_verification',
                'repository_reconciliation',
                'workspace_shutdown',
                'reservation_release',
            ], true)
            && $phase->started_at !== null && $phase->finished_at === null;
        $completed = $phase->status === PhaseRunStatus::Completed
            && $phase->current_block === null
            && $phase->started_at !== null && $phase->finished_at !== null;
        $stage = $completed ? 'completed' : $phase->current_block;

        return ($activeBlock || $completed)
            && is_string($stage)
            && is_string($implementationFlow)
            && $this->matchesMergedOutput(
                $delivery,
                $phase->output,
                $published,
                $stage,
                $implementationFlow,
            );
    }

    /** @param array<string, mixed> $published */
    private function matchesPublishedReview(Delivery $delivery, Receipt $review, array $published): bool
    {
        $body = $review->payload['pull_request_body'] ?? null;

        return is_int($published['id'] ?? null) && $published['id'] > 0
            && ($published['reviewer_login'] ?? null) === 'tom-nckrtl[bot]'
            && ($published['candidate_sha'] ?? null) === $delivery->candidate_sha
            && ($published['state'] ?? null) === 'APPROVED'
            && ($published['review_body_sha256'] ?? null) === hash('sha256', 'Approved.')
            && is_string($body)
            && ($published['pull_request_body_sha256'] ?? null) === hash('sha256', $body)
            && array_diff(array_keys($published), [
                'id', 'reviewer_login', 'candidate_sha', 'state',
                'review_body_sha256', 'pull_request_body_sha256',
            ]) === []
            && count($published) === 6;
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>  $published
     */
    private function matchesPreMergeOutput(Delivery $delivery, ?array $output, array $published): bool
    {
        $approval = is_array($output) ? ($output['approved_pull_request'] ?? null) : null;
        $reservation = is_array($output) ? ($output['reservation'] ?? null) : null;
        $mainSha = is_array($output) ? ($output['main_sha'] ?? null) : null;
        $repository = is_array($output) ? ($output['repository'] ?? null) : null;

        return is_array($output) && count($output) === 4
            && is_string($mainSha) && preg_match('/^[a-f0-9]{40}$/', $mainSha) === 1
            && is_string($repository) && str_starts_with($repository, '/')
            && is_array($approval) && ! array_is_list($approval)
            && $approval === [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'candidate_sha' => $delivery->candidate_sha,
                'body_sha256' => $published['pull_request_body_sha256'],
                'review_id' => $published['id'],
                'reviewer_login' => $published['reviewer_login'],
                'mergeable' => true,
            ]
            && is_array($reservation) && ! array_is_list($reservation)
            && $reservation === [
                'owned' => true,
                'issue_id' => $delivery->external_issue_id,
                'pull_request_url' => $delivery->pull_request_url,
                'reserved_at' => $reservation['reserved_at'] ?? null,
            ]
            && is_string($reservation['reserved_at'] ?? null)
            && trim($reservation['reserved_at']) !== '';
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>  $published
     */
    private function matchesMergedOutput(
        Delivery $delivery,
        ?array $output,
        array $published,
        string $stage,
        string $implementationFlow,
    ): bool {
        $merge = is_array($output) ? ($output['merge'] ?? null) : null;
        $verification = is_array($output) ? ($output['merge_verification'] ?? null) : null;
        $reconciliation = is_array($output) ? ($output['repository_reconciliation'] ?? null) : null;
        $workspaceShutdown = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;
        $preMerge = is_array($output) ? $output : null;

        if (! is_array($merge) || array_is_list($merge) || ! is_array($preMerge)
            || ! in_array($stage, [
                'merge_verification',
                'repository_reconciliation',
                'workspace_shutdown',
                'reservation_release',
                'completed',
            ], true)) {
            return false;
        }

        unset(
            $preMerge['merge'],
            $preMerge['merge_verification'],
            $preMerge['repository_reconciliation'],
            $preMerge['workspace_shutdown'],
        );

        $requiresVerification = $stage !== 'merge_verification';
        $requiresReconciliation = in_array($stage, ['workspace_shutdown', 'reservation_release', 'completed'], true);
        $requiresClosedWorkspace = in_array($stage, ['reservation_release', 'completed'], true);

        return $this->matchesPreMergeOutput($delivery, $preMerge, $published)
            && $merge === [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'candidate_sha' => $delivery->candidate_sha,
                'merge_commit_sha' => $merge['merge_commit_sha'] ?? null,
            ]
            && is_string($merge['merge_commit_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $merge['merge_commit_sha']) === 1
            && ($requiresVerification
                ? $this->matchesMergeVerification($merge, $verification, $implementationFlow)
                : $verification === null)
            && ($requiresReconciliation
                ? $this->matchesRepositoryReconciliation($preMerge, $merge, $reconciliation)
                : $reconciliation === null)
            && ($requiresClosedWorkspace
                ? $this->matchesClosedWorkspace($delivery, $workspaceShutdown)
                : ($stage === 'workspace_shutdown'
                    ? $workspaceShutdown === null || is_array($workspaceShutdown)
                    : $workspaceShutdown === null));
    }

    /** @param array<string, mixed> $merge */
    private function matchesMergeVerification(
        array $merge,
        mixed $verification,
        string $implementationFlow,
    ): bool {
        return is_array($verification) && ! array_is_list($verification)
            && count($verification) === 4
            && ($verification['flow'] ?? null) === $implementationFlow
            && ($verification['candidate_sha'] ?? null) === ($merge['candidate_sha'] ?? null)
            && ($verification['merge_commit_sha'] ?? null) === ($merge['merge_commit_sha'] ?? null)
            && is_string($verification['tree_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $verification['tree_sha']) === 1;
    }

    /**
     * @param  array<string, mixed>  $preMerge
     * @param  array<string, mixed>  $merge
     */
    private function matchesRepositoryReconciliation(
        array $preMerge,
        array $merge,
        mixed $reconciliation,
    ): bool {
        return is_array($reconciliation) && ! array_is_list($reconciliation)
            && count($reconciliation) === 4
            && ($reconciliation['repository'] ?? null) === ($preMerge['repository'] ?? null)
            && ($reconciliation['merge_commit_sha'] ?? null) === ($merge['merge_commit_sha'] ?? null)
            && is_string($reconciliation['main_sha'] ?? null)
            && $reconciliation['main_sha'] === ($reconciliation['origin_main_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $reconciliation['main_sha']) === 1;
    }

    private function matchesClosedWorkspace(Delivery $delivery, mixed $shutdown): bool
    {
        $closed = is_array($shutdown) ? ($shutdown['closed'] ?? null) : null;

        return is_array($shutdown) && ! array_is_list($shutdown)
            && ($shutdown['schema'] ?? null) === 1
            && ($shutdown['worktree_path'] ?? null) === $delivery->worktree_path
            && is_string($shutdown['workspace_id'] ?? null)
            && is_string($shutdown['session'] ?? null)
            && is_string($shutdown['workspace_close_attempted_at'] ?? null)
            && is_array($shutdown['owned_agent_names'] ?? null)
            && array_is_list($shutdown['owned_agent_names'])
            && is_array($shutdown['protected_workspace_ids'] ?? null)
            && is_array($shutdown['protected_agent_terminal_ids'] ?? null)
            && is_array($shutdown['protected_pane_ids'] ?? null)
            && is_array($shutdown['target_agent_terminal_ids'] ?? null)
            && is_array($shutdown['target_pane_ids'] ?? null)
            && is_array($closed) && ! array_is_list($closed)
            && ($closed['session'] ?? null) === $shutdown['session']
            && ($closed['workspace_id'] ?? null) === $shutdown['workspace_id']
            && ($closed['worktree_path'] ?? null) === $delivery->worktree_path
            && ($closed['owned_agent_names'] ?? null) === $shutdown['owned_agent_names']
            && is_string($closed['verified_at'] ?? null);
    }

    private function verifyImplementation(
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $implementation,
    ): VerifiedOrbitImplementationOutcome {
        $payload = $implementation->payload;
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

        if ($verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || $verified->artifactSha !== ($payload['artifact_sha'] ?? null)
            || $verified->gateReceiptPath !== ($payload['gate_receipt_path'] ?? null)
            || $verified->pullRequestBodyHash !== ($payload['pull_request_body_sha256'] ?? null)
            || $verified->flow !== ($payload['flow'] ?? null)) {
            throw new OrbitLandingAdvancementFailed(
                'The verified implementation no longer matches its immutable receipt.',
            );
        }

        return $verified;
    }

    private function assertCurrentIssue(
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_array($state) || ($state['name'] ?? null) !== 'In Review'
            || ($state['type'] ?? null) !== 'started'
            || ! is_string($viewerId)
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || $issue->payload['assignee'] !== null) {
            throw new OrbitIssueContractChanged(
                'The Orbit issue changed before landing.',
            );
        }
    }

    private function assertApproved(
        Delivery $delivery,
        PhaseRun $phase,
        Receipt $review,
        VerifiedOrbitImplementationOutcome $verified,
        ApprovedOrbitPullRequest $approved,
    ): void {
        $published = $phase->input['published_review'] ?? null;

        if (! is_array($published)
            || $approved->number !== $delivery->pull_request_number
            || $approved->url !== $delivery->pull_request_url
            || $approved->candidateSha !== $verified->candidateSha
            || $approved->bodyHash !== ($published['pull_request_body_sha256'] ?? null)
            || $approved->bodyHash !== ($review->payload['pull_request_body_sha256'] ?? null)
            || $approved->reviewId !== ($published['id'] ?? null)
            || $approved->reviewerLogin !== ($published['reviewer_login'] ?? null)) {
            throw new OrbitLandingAdvancementFailed(
                'The verified pull request no longer matches the retained approval.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function markMerging(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
        ApprovedOrbitPullRequest $approved,
        OrbitLandingReservation $reservation,
        OrbitMainCorrectness $main,
    ): array {
        return DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $config,
            $approved,
            $reservation,
            $main,
        ): array {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $implementation = $intent[2] ?? null;

            if ($phase === null
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed before merge.',
                );
            }

            $phaseInput = $phase->input;
            $published = $this->associativeArray(
                is_array($phaseInput) ? ($phaseInput['published_review'] ?? null) : null,
            );
            $output = [
                'main_sha' => $main->mainSha,
                'repository' => $config->repository,
                'approved_pull_request' => [
                    'number' => $approved->number,
                    'url' => $approved->url,
                    'candidate_sha' => $approved->candidateSha,
                    'body_sha256' => $approved->bodyHash,
                    'review_id' => $approved->reviewId,
                    'reviewer_login' => $approved->reviewerLogin,
                    'mergeable' => $approved->mergeable,
                ],
                'reservation' => [
                    'owned' => $reservation->owned,
                    'issue_id' => $reservation->issueId,
                    'pull_request_url' => $reservation->pullRequestUrl,
                    'reserved_at' => $reservation->reservedAt,
                ],
            ];

            if ($published === null
                || ! $main->passed() || ! $reservation->owned || $approved->mergeable !== true
                || ! $this->matchesPreMergeOutput(
                    $delivery,
                    $output,
                    $published,
                )) {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit merge authorization is inconsistent.',
                );
            }

            $phase->status = PhaseRunStatus::Running;
            $phase->current_block = 'merge';
            $phase->output = $output;
            $phase->started_at = now();
            $phase->save();
            $delivery->status = DeliveryStatus::Merging;
            $delivery->failure_details = null;
            $delivery->save();

            return $output;
        });
    }

    /** @param array<string, mixed> $preMerge */
    private function commitMerged(
        int $deliveryId,
        int $phaseId,
        array $preMerge,
        MergedOrbitPullRequest $merged,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $preMerge, $merged): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();
            $merge = [
                'number' => $merged->number,
                'url' => $merged->url,
                'candidate_sha' => $merged->candidateSha,
                'merge_commit_sha' => $merged->mergeCommitSha,
            ];
            $output = [...$preMerge, 'merge' => $merge];

            if ($delivery->status === DeliveryStatus::Landed
                && $phase->current_block === 'merge_verification'
                && $phase->output === $output) {
                return;
            }

            if ($delivery->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE
                || $delivery->status !== DeliveryStatus::Merging
                || $delivery->candidate_sha !== $merged->candidateSha
                || $delivery->pull_request_number !== $merged->number
                || $delivery->pull_request_url !== $merged->url
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::LANDING_PHASE
                || $phase->attempt !== 1 || $phase->status !== PhaseRunStatus::Running
                || $phase->current_block !== 'merge' || $phase->output !== $preMerge) {
                throw new OrbitLandingAdvancementFailed(
                    'The confirmed Orbit merge could not be reconciled with its landing ledger.',
                );
            }

            $phase->current_block = 'merge_verification';
            $phase->output = $output;
            $phase->save();
            $delivery->status = DeliveryStatus::Landed;
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function resumePostMerge(Delivery $delivery, PhaseRun $phase): ?int
    {
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);
        $repository = is_array($phase->output) ? ($phase->output['repository'] ?? null) : null;
        $issueKey = $delivery->external_issue_key;

        if (! $config instanceof OrbitProjectConfig
            || ! is_string($repository) || $config->repository !== $repository
            || ! is_string($issueKey) || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit post-merge repository configuration is inconsistent.',
            );
        }

        $issueReservation = $this->repository->reserveDelivery($config, $issueKey);

        try {
            $delivery = $this->delivery($delivery->id);
            $intent = $this->landingIntent($delivery, $phase->id);

            if ($intent === null) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit post-merge ledger changed before recovery.',
                );
            }

            if ($delivery->status === DeliveryStatus::Merging) {
                return $this->resumeMerge($delivery, $intent[0], $config);
            }

            if ($delivery->status !== DeliveryStatus::Landed) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit post-merge delivery is not recoverable.',
                );
            }

            return $this->reconcileAndFinalize($config, $delivery->id, $intent[0]->id, true);
        } finally {
            $issueReservation->release();
        }
    }

    private function reconcileAndFinalize(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
        bool $ensureMergeReservation,
    ): ?int {
        $delivery = $this->delivery($deliveryId);

        if ($ensureMergeReservation) {
            $reservation = $this->pullRequests->reserve(
                (string) $delivery->external_issue_id,
                $this->pullRequestUrl($delivery),
            );

            if (! $reservation->owned) {
                throw new OrbitLandingAdvancementFailed(
                    'The landed Orbit delivery no longer owns its merge reservation.',
                );
            }
        }

        $this->verifyMerge($config, $deliveryId, $phaseId);
        $this->cacheRefresh->handle($deliveryId, $phaseId);
        $this->reconcilePrimaryCheckout($config, $deliveryId, $phaseId);
        $phase = PhaseRun::query()->findOrFail($phaseId);

        if ($phase->current_block === 'workspace_shutdown') {
            if (! $this->workspaceShutdown->handle($config, $deliveryId, $phaseId)) {
                $this->markWorkspaceShutdownWait($deliveryId, $phaseId);

                return self::RETRY_SECONDS;
            }

            $this->recordWorkspaceShutdown($deliveryId, $phaseId);
        }

        $this->releaseAndFinalize(
            $this->delivery($deliveryId),
            PhaseRun::query()->findOrFail($phaseId),
        );

        return null;
    }

    private function verifyMerge(OrbitProjectConfig $config, int $deliveryId, int $phaseId): void
    {
        $delivery = $this->delivery($deliveryId);
        $phase = PhaseRun::query()->findOrFail($phaseId);

        if (in_array($phase->current_block, [
            'repository_reconciliation',
            'workspace_shutdown',
            'reservation_release',
        ], true)) {
            return;
        }

        $output = $phase->output;
        $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
        $worktree = $delivery->worktree_path;

        if ($phase->current_block !== 'merge_verification'
            || $merge === null || ! is_string($worktree)) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit merge cannot be verified from its retained ledger.',
            );
        }

        $verified = $this->merges->verifyMergeLineage(
            $config,
            $worktree,
            $this->sha($merge, 'candidate_sha'),
            $this->sha($merge, 'merge_commit_sha'),
        );
        $this->recordMergeVerification($deliveryId, $phaseId, $verified);
    }

    private function recordMergeVerification(
        int $deliveryId,
        int $phaseId,
        VerifiedOrbitMergeLineage $verified,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $verified): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $implementation = $intent[2] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $evidence = [
                'flow' => $verified->flow,
                'candidate_sha' => $verified->candidateSha,
                'merge_commit_sha' => $verified->mergeCommitSha,
                'tree_sha' => $verified->treeSha,
            ];

            if ($phase !== null && $phase->current_block === 'repository_reconciliation'
                && is_array($output) && ($output['merge_verification'] ?? null) === $evidence) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'merge_verification'
                || $implementation === null
                || ! is_array($output) || $merge === null
                || ($implementation->payload['flow'] ?? null) !== $verified->flow
                || ($merge['candidate_sha'] ?? null) !== $verified->candidateSha
                || ($merge['merge_commit_sha'] ?? null) !== $verified->mergeCommitSha
                || array_key_exists('merge_verification', $output)) {
                throw new OrbitLandingAdvancementFailed(
                    'The verified Orbit merge no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'repository_reconciliation';
            $phase->output = [...$output, 'merge_verification' => $evidence];
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function reconcilePrimaryCheckout(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): void {
        $phase = PhaseRun::query()->findOrFail($phaseId);

        if (in_array($phase->current_block, ['workspace_shutdown', 'reservation_release'], true)) {
            return;
        }

        $output = $phase->output;
        $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);

        if ($phase->current_block !== 'repository_reconciliation' || $merge === null) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit primary checkout cannot reconcile from its retained ledger.',
            );
        }

        $reconciled = $this->primaryCheckout->reconcilePrimaryCheckout(
            $config,
            $this->sha($merge, 'merge_commit_sha'),
        );
        $this->recordRepositoryReconciliation($deliveryId, $phaseId, $reconciled);
    }

    private function recordRepositoryReconciliation(
        int $deliveryId,
        int $phaseId,
        ReconciledOrbitPrimaryCheckout $reconciled,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $reconciled): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $evidence = [
                'repository' => $reconciled->repository,
                'merge_commit_sha' => $reconciled->mergeCommitSha,
                'main_sha' => $reconciled->mainSha,
                'origin_main_sha' => $reconciled->originMainSha,
            ];

            if ($phase !== null && in_array($phase->current_block, ['workspace_shutdown', 'reservation_release'], true)
                && is_array($output) && ($output['repository_reconciliation'] ?? null) === $evidence) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'repository_reconciliation'
                || ! is_array($output) || $merge === null
                || ($output['repository'] ?? null) !== $reconciled->repository
                || ($merge['merge_commit_sha'] ?? null) !== $reconciled->mergeCommitSha
                || $reconciled->mainSha !== $reconciled->originMainSha
                || array_key_exists('repository_reconciliation', $output)) {
                throw new OrbitLandingAdvancementFailed(
                    'The reconciled Orbit primary checkout no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'workspace_shutdown';
            $phase->output = [...$output, 'repository_reconciliation' => $evidence];
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function recordWorkspaceShutdown(int $deliveryId, int $phaseId): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $output = $phase?->output;
            $shutdown = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;

            if ($phase !== null && $phase->current_block === 'reservation_release'
                && $this->matchesClosedWorkspace($delivery, $shutdown)) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'workspace_shutdown'
                || ! is_array($output) || ! $this->matchesClosedWorkspace($delivery, $shutdown)) {
                throw new OrbitLandingAdvancementFailed(
                    'The verified Orbit Herdr workspace shutdown no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'reservation_release';
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function markWorkspaceShutdownWait(int $deliveryId, int $phaseId): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;

            if ($phase === null || $phase->current_block !== 'workspace_shutdown') {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed while recording its workspace shutdown wait.',
                );
            }

            $delivery->failure_details = [
                'code' => 'landing_workspace_shutdown_wait',
                'phase_run_id' => $phase->id,
                'message' => 'Commander is waiting for the owned Herdr agents to exit.',
            ];
            $delivery->save();
        });
    }

    private function resumeMerge(
        Delivery $delivery,
        PhaseRun $phase,
        OrbitProjectConfig $config,
    ): ?int {
        $output = $phase->output;
        $approval = $this->associativeArray(
            is_array($output) ? ($output['approved_pull_request'] ?? null) : null,
        );
        $reservation = $this->pullRequests->reserve(
            (string) $delivery->external_issue_id,
            $this->pullRequestUrl($delivery),
        );

        if (! $reservation->owned || $approval === null || $output === null) {
            throw new OrbitLandingAdvancementFailed(
                'The interrupted Orbit merge no longer owns its reservation.',
            );
        }

        $merged = $this->pullRequests->merge(
            $this->integer($approval, 'number'),
            $this->sha($approval, 'candidate_sha'),
        );
        $this->commitMerged($delivery->id, $phase->id, $output, $merged);

        return $this->reconcileAndFinalize($config, $delivery->id, $phase->id, false);
    }

    private function releaseAndFinalize(Delivery $delivery, PhaseRun $phase): void
    {
        try {
            $this->pullRequests->release(
                (string) $delivery->external_issue_id,
                $this->pullRequestUrl($delivery),
            );
        } catch (OrbitPullRequestLandingFailed $exception) {
            $this->markReleaseRequired($delivery->id, $phase->id, $exception);

            throw $exception;
        }

        DB::transaction(function () use ($delivery, $phase): void {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedPhase = PhaseRun::query()->whereKey($phase->id)->lockForUpdate()->firstOrFail();

            if ($this->isCompleted($locked, $lockedPhase)) {
                return;
            }

            if ($locked->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE
                || $locked->status !== DeliveryStatus::Landed
                || $lockedPhase->delivery_id !== $locked->id
                || $lockedPhase->phase_name !== OrbitFeatureWorkflow::LANDING_PHASE
                || $lockedPhase->attempt !== 1 || $lockedPhase->status !== PhaseRunStatus::Running
                || $lockedPhase->current_block !== 'reservation_release'
                || ! is_array($lockedPhase->output)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed before reservation release completed.',
                );
            }

            $lockedPhase->status = PhaseRunStatus::Completed;
            $lockedPhase->current_block = null;
            $lockedPhase->finished_at = now();
            $lockedPhase->save();
            $locked->failure_details = null;
            $locked->save();
        });
    }

    private function markReleaseRequired(
        int $deliveryId,
        int $phaseId,
        OrbitPullRequestLandingFailed $exception,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $exception): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();

            if ($delivery->status !== DeliveryStatus::Landed
                || $phase->status !== PhaseRunStatus::Running
                || $phase->current_block !== 'reservation_release') {
                return;
            }

            $delivery->failure_details = [
                'code' => 'landing_reservation_release_required',
                'phase_run_id' => $phase->id,
                'message' => $exception->getMessage(),
            ];
            $delivery->save();
        });
    }

    private function markMainHold(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
        OrbitMainCorrectness $main,
    ): void {
        $this->markReadyWait($deliveryId, $phaseId, $config, [
            'code' => 'landing_main_correctness_hold',
            'phase_run_id' => $phaseId,
            'main_sha' => $main->mainSha,
            'correctness_failures' => $main->failures,
        ]);
    }

    private function markReservationWait(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
        OrbitLandingReservation $reservation,
    ): void {
        $this->markReadyWait($deliveryId, $phaseId, $config, [
            'code' => 'landing_merge_reservation_wait',
            'phase_run_id' => $phaseId,
            'owner_issue_id' => $reservation->issueId,
            'owner_pull_request_url' => $reservation->pullRequestUrl,
            'reserved_at' => $reservation->reservedAt,
        ]);
    }

    private function markMergeabilityWait(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
    ): void {
        $this->markReadyWait($deliveryId, $phaseId, $config, [
            'code' => 'landing_mergeability_pending',
            'phase_run_id' => $phaseId,
        ]);
    }

    /** @param array<string, mixed> $details */
    private function markReadyWait(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
        array $details,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $config, $details): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);

            if ($intent === null
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed while recording its wait.',
                );
            }

            $delivery->failure_details = $details;
            $delivery->save();
        });
    }

    private function markMergeConflict(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $config): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);

            if ($intent === null
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed while recording its merge conflict.',
                );
            }

            $delivery->status = DeliveryStatus::Blocked;
            $delivery->failure_details = [
                'code' => 'landing_merge_conflict',
                'phase_run_id' => $phaseId,
                'message' => 'The approved Orbit pull request now conflicts with main.',
            ];
            $delivery->save();
        });
    }

    private function lockLedger(int $deliveryId): Delivery
    {
        $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
        $project = ProjectOrchestration::query()
            ->whereKey($delivery->project_orchestration_id)
            ->lockForUpdate()
            ->firstOrFail();
        $phases = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $phaseIds = $phases->modelKeys();
        AgentDispatch::query()->whereIn('phase_run_id', $phaseIds)->orderBy('id')->lockForUpdate()->get();
        Receipt::query()->whereIn('phase_run_id', $phaseIds)->orderBy('id')->lockForUpdate()->get();
        $delivery->setRelation('projectOrchestration', $project);

        return $delivery;
    }

    private function isCompleted(Delivery $delivery, PhaseRun $phase): bool
    {
        return $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
            && $delivery->status === DeliveryStatus::Landed
            && $phase->delivery_id === $delivery->id
            && $phase->phase_name === OrbitFeatureWorkflow::LANDING_PHASE
            && $phase->attempt === 1 && $phase->status === PhaseRunStatus::Completed
            && $phase->current_block === null && $phase->output !== null
            && $phase->started_at !== null && $phase->finished_at !== null;
    }

    private function publishedReviewId(PhaseRun $phase): int
    {
        $input = $phase->input;
        $published = $this->associativeArray(
            is_array($input) ? ($input['published_review'] ?? null) : null,
        );

        if ($published === null) {
            throw new OrbitLandingAdvancementFailed(
                'The landing intent has invalid published review metadata.',
            );
        }

        return $this->integer($published, 'id');
    }

    private function delivery(int $deliveryId): Delivery
    {
        return Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
    }

    /** @return array<string, mixed>|null */
    private function associativeArray(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $result[$key] = $item;
        }

        return $result;
    }

    private function pullRequestNumber(Delivery $delivery): int
    {
        if (! is_int($delivery->pull_request_number) || $delivery->pull_request_number < 1) {
            throw new OrbitLandingAdvancementFailed(
                'The landing delivery has no valid pull request number.',
            );
        }

        return $delivery->pull_request_number;
    }

    private function pullRequestUrl(Delivery $delivery): string
    {
        $number = $this->pullRequestNumber($delivery);
        $url = $delivery->pull_request_url;

        if (! is_string($url) || $url !== "https://github.com/nckrtl/orbit/pull/{$number}") {
            throw new OrbitLandingAdvancementFailed(
                'The landing delivery has no valid pull request URL.',
            );
        }

        return $url;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitLandingAdvancementFailed(
                "The Orbit landing input has an invalid {$key}.",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitLandingAdvancementFailed(
                "The Orbit landing input has an invalid {$key}.",
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function integer(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new OrbitLandingAdvancementFailed(
                "The Orbit landing input has an invalid {$key}.",
            );
        }

        return $value;
    }
}
