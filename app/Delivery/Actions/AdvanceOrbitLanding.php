<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueCompletionTransitioner;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitProofTopologyCloser;
use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitWorktreeCleaner;
use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitLandingReservation;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\PreparedOrbitWorktreeRemoval;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;
use App\Delivery\Data\RemovedOrbitWorktree;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitMergeLineage;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
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
        private OrbitProofTopologyCloser $proofTopologies,
        private OrbitPullRequestReviewReceiptValidator $reviewReceipts,
        private OrbitPullRequestReviewSourceValidator $sources,
        private QueueOrbitMainCacheRefresh $cacheRefresh,
        private ShutdownOrbitHerdrWorkspace $workspaceShutdown,
        private OrbitWorktreeCleaner $worktreeCleaner,
        private OrbitIssueCompletionTransitioner $issueCompletion,
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
                $implementation->payload['artifact_sha'] ?? null,
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
        mixed $artifactSha,
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

        if (! in_array($delivery->status, [DeliveryStatus::Landed, DeliveryStatus::Completed], true)) {
            return false;
        }

        $activeBlock = $delivery->status === DeliveryStatus::Landed
            && $phase->status === PhaseRunStatus::Running
            && in_array($phase->current_block, [
                'merge_verification',
                'repository_reconciliation',
                'workspace_shutdown',
                'proof_closeout',
                'worktree_cleanup',
                'linear_closeout',
                'reservation_release',
            ], true)
            && $phase->started_at !== null && $phase->finished_at === null;
        $completed = $this->matchesCommanderCompletion($delivery, $phase);
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
                $artifactSha,
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
        mixed $artifactSha,
    ): bool {
        $merge = is_array($output) ? ($output['merge'] ?? null) : null;
        $verification = is_array($output) ? ($output['merge_verification'] ?? null) : null;
        $reconciliation = is_array($output) ? ($output['repository_reconciliation'] ?? null) : null;
        $workspaceShutdown = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;
        $proofCloseout = is_array($output) ? ($output['proof_closeout'] ?? null) : null;
        $worktreeCleanupIntent = is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null;
        $worktreeCleanupAuthorization = is_array($output) ? ($output['worktree_cleanup_authorization'] ?? null) : null;
        $worktreeCleanup = is_array($output) ? ($output['worktree_cleanup'] ?? null) : null;
        $linearCloseout = is_array($output) ? ($output['linear_closeout'] ?? null) : null;
        $preMerge = is_array($output) ? $output : null;
        $normalizedReconciliation = $this->associativeArray($reconciliation);

        if (! is_array($merge) || array_is_list($merge) || ! is_array($preMerge)
            || ! in_array($stage, [
                'merge_verification',
                'repository_reconciliation',
                'workspace_shutdown',
                'proof_closeout',
                'worktree_cleanup',
                'linear_closeout',
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
            $preMerge['proof_closeout'],
            $preMerge['worktree_cleanup_intent'],
            $preMerge['worktree_cleanup_authorization'],
            $preMerge['worktree_cleanup'],
            $preMerge['linear_closeout'],
        );

        $requiresVerification = $stage !== 'merge_verification';
        $requiresReconciliation = in_array($stage, ['workspace_shutdown', 'proof_closeout', 'worktree_cleanup', 'linear_closeout', 'reservation_release', 'completed'], true);
        $requiresClosedWorkspace = in_array($stage, ['proof_closeout', 'worktree_cleanup', 'linear_closeout', 'reservation_release', 'completed'], true);
        $requiresProofCloseout = in_array($stage, ['worktree_cleanup', 'linear_closeout', 'reservation_release', 'completed'], true);
        $requiresLinearCloseout = in_array($stage, ['reservation_release', 'completed'], true);

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
                ? $this->matchesRepositoryReconciliation($preMerge, $merge, $normalizedReconciliation)
                : $normalizedReconciliation === null)
            && ($requiresClosedWorkspace
                ? $this->matchesClosedWorkspace($delivery, $workspaceShutdown)
                : ($stage === 'workspace_shutdown'
                    ? $workspaceShutdown === null || is_array($workspaceShutdown)
                    : $workspaceShutdown === null))
            && ($requiresProofCloseout
                ? $this->matchesProofCloseout(
                    $delivery,
                    $merge,
                    $normalizedReconciliation,
                    $proofCloseout,
                    $implementationFlow,
                    $artifactSha,
                )
                : $proofCloseout === null)
            && $this->matchesWorktreeCleanupState(
                $delivery,
                $merge,
                $output['repository'] ?? null,
                $proofCloseout,
                $worktreeCleanupIntent,
                $worktreeCleanupAuthorization,
                $worktreeCleanup,
                $stage,
                $implementationFlow,
                $artifactSha,
            )
            && ($requiresLinearCloseout
                ? $this->matchesLinearCloseout($delivery, $linearCloseout)
                : $linearCloseout === null);
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

    /**
     * @param  array<string, mixed>  $merge
     * @param  array<string, mixed>|null  $reconciliation
     */
    private function matchesProofCloseout(
        Delivery $delivery,
        array $merge,
        ?array $reconciliation,
        mixed $closeout,
        string $implementationFlow,
        mixed $artifactSha,
    ): bool {
        if ($implementationFlow === 'discovery') {
            return $closeout === [
                'schema' => 1,
                'flow' => 'discovery',
                'required' => false,
            ];
        }

        $record = is_array($closeout) ? ($closeout['record'] ?? null) : null;

        return $implementationFlow === 'proof'
            && is_string($artifactSha)
            && preg_match('/^[a-f0-9]{40}$/', $artifactSha) === 1
            && is_array($closeout) && ! array_is_list($closeout)
            && count($closeout) === 4
            && ($closeout['schema'] ?? null) === 1
            && ($closeout['flow'] ?? null) === 'proof'
            && ($closeout['required'] ?? null) === true
            && is_array($record) && ! array_is_list($record)
            && array_keys($record) === [
                'schema',
                'state',
                'issue',
                'attempt_id',
                'candidate_sha',
                'artifact_sha',
                'merge_sha',
                'main_sha',
                'generation_id',
                'error',
                'recorded_at',
            ]
            && ($record['schema'] ?? null) === OrbitProofCloseout::SCHEMA
            && ($record['state'] ?? null) === 'complete'
            && ($record['issue'] ?? null) === $delivery->external_issue_key
            && is_string($record['attempt_id'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/', $record['attempt_id']) === 1
            && ($record['candidate_sha'] ?? null) === ($merge['candidate_sha'] ?? null)
            && ($record['artifact_sha'] ?? null) === $artifactSha
            && ($record['merge_sha'] ?? null) === ($merge['merge_commit_sha'] ?? null)
            && ($record['main_sha'] ?? null) === ($reconciliation['main_sha'] ?? null)
            && is_string($record['generation_id'] ?? null)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $record['generation_id']) === 1
            && ($record['error'] ?? null) === null
            && is_string($record['recorded_at'] ?? null)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $record['recorded_at']) === 1;
    }

    /**
     * @param  array<string, mixed>  $merge
     */
    private function matchesWorktreeCleanupState(
        Delivery $delivery,
        array $merge,
        mixed $repository,
        mixed $proofCloseout,
        mixed $intent,
        mixed $authorization,
        mixed $cleanup,
        string $stage,
        string $implementationFlow,
        mixed $artifactSha,
    ): bool {
        if (! in_array($stage, ['worktree_cleanup', 'linear_closeout', 'reservation_release', 'completed'], true)) {
            return $intent === null && $authorization === null && $cleanup === null;
        }

        if ($stage === 'worktree_cleanup' && $intent === null) {
            return $authorization === null && $cleanup === null;
        }

        $proofRecord = is_array($proofCloseout) && ($proofCloseout['flow'] ?? null) === 'proof'
            ? $this->associativeArray($proofCloseout['record'] ?? null)
            : null;
        $proofAttemptId = $proofRecord['attempt_id'] ?? null;
        $expectedProofAttemptId = $implementationFlow === 'proof' ? $proofAttemptId : null;

        if (! is_array($intent) || array_is_list($intent)
            || array_keys($intent) !== [
                'schema',
                'attempt_id',
                'repository',
                'issue_key',
                'worktree',
                'branch',
                'candidate_sha',
                'artifact_sha',
                'proof_attempt_id',
                'started_at',
            ]
            || ($intent['schema'] ?? null) !== 1
            || ! is_string($intent['attempt_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $intent['attempt_id']) !== 1
            || ! is_string($intent['repository'] ?? null)
            || $intent['repository'] !== $repository
            || ($intent['issue_key'] ?? null) !== $delivery->external_issue_key
            || ($intent['worktree'] ?? null) !== $delivery->worktree_path
            || ($intent['branch'] ?? null) !== strtolower((string) $delivery->external_issue_key)
            || ($intent['candidate_sha'] ?? null) !== ($merge['candidate_sha'] ?? null)
            || ($intent['artifact_sha'] ?? null) !== $artifactSha
            || ($intent['proof_attempt_id'] ?? null) !== $expectedProofAttemptId
            || ! is_string($intent['started_at'] ?? null)
            || trim($intent['started_at']) === '') {
            return false;
        }

        if ($authorization === null) {
            return $stage === 'worktree_cleanup' && $cleanup === null;
        }

        if (! is_array($authorization) || array_is_list($authorization)) {
            return false;
        }

        try {
            $prepared = PreparedOrbitWorktreeRemoval::fromArray($authorization);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($prepared->repository !== $intent['repository']
            || $prepared->worktree !== $intent['worktree']
            || $prepared->issueKey !== $intent['issue_key']
            || $prepared->branch !== $intent['branch']
            || $prepared->candidateSha !== $intent['candidate_sha']
            || $prepared->artifactSha !== $intent['artifact_sha']
            || $prepared->cleanupAttemptId !== $intent['attempt_id']
            || $prepared->proofAttemptId !== $intent['proof_attempt_id']) {
            return false;
        }

        if ($stage === 'worktree_cleanup') {
            return $cleanup === null;
        }

        $proofAttemptId = is_array($cleanup) ? ($cleanup['proof_attempt_id'] ?? null) : null;
        $evidenceArchives = is_array($cleanup)
            ? $this->stringMap($cleanup['evidence_archives'] ?? null)
            : null;

        if (! is_array($cleanup) || array_is_list($cleanup)
            || array_keys($cleanup) !== [
                'schema',
                'repository',
                'worktree',
                'issue_key',
                'branch',
                'candidate_sha',
                'artifact_ref',
                'artifact_sha',
                'cleanup_attempt_id',
                'proof_attempt_id',
                'evidence_archives',
                'removed_at',
            ]
            || ($cleanup['schema'] ?? null) !== RemovedOrbitWorktree::SCHEMA
            || ! is_string($cleanup['repository'] ?? null)
            || ! is_string($cleanup['worktree'] ?? null)
            || ! is_string($cleanup['issue_key'] ?? null)
            || ! is_string($cleanup['branch'] ?? null)
            || ! is_string($cleanup['candidate_sha'] ?? null)
            || ! is_string($cleanup['artifact_ref'] ?? null)
            || ! is_string($cleanup['artifact_sha'] ?? null)
            || ! is_string($cleanup['cleanup_attempt_id'] ?? null)
            || ($proofAttemptId !== null && ! is_string($proofAttemptId))
            || $evidenceArchives === null
            || ! is_string($cleanup['removed_at'] ?? null)) {
            return false;
        }

        try {
            $removed = new RemovedOrbitWorktree(
                repository: $cleanup['repository'],
                worktree: $cleanup['worktree'],
                issueKey: $cleanup['issue_key'],
                branch: $cleanup['branch'],
                candidateSha: $cleanup['candidate_sha'],
                artifactRef: $cleanup['artifact_ref'],
                artifactSha: $cleanup['artifact_sha'],
                cleanupAttemptId: $cleanup['cleanup_attempt_id'],
                proofAttemptId: $proofAttemptId,
                evidenceArchives: $evidenceArchives,
                removedAt: $cleanup['removed_at'],
            );
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $removed->toArray() === $cleanup
            && $removed->repository === $intent['repository']
            && $removed->worktree === $intent['worktree']
            && $removed->issueKey === $intent['issue_key']
            && $removed->branch === $intent['branch']
            && $removed->candidateSha === $intent['candidate_sha']
            && $removed->artifactSha === $intent['artifact_sha']
            && $removed->cleanupAttemptId === $intent['attempt_id']
            && $removed->proofAttemptId === $intent['proof_attempt_id']
            && $removed->evidenceArchives === $prepared->evidenceArchives;
    }

    private function matchesLinearCloseout(Delivery $delivery, mixed $closeout): bool
    {
        try {
            $expected = $this->preparations->startup($delivery)->snapshot;
        } catch (OrbitPlanningHandoffFailed) {
            return false;
        }

        $state = is_array($closeout) ? ($closeout['state'] ?? null) : null;

        return is_array($closeout) && ! array_is_list($closeout)
            && array_keys($closeout) === [
                'schema',
                'provider',
                'issue_id',
                'issue_key',
                'contract_sha256',
                'state',
                'assignee',
                'delegate',
                'updated_at',
            ]
            && ($closeout['schema'] ?? null) === 1
            && ($closeout['provider'] ?? null) === OrbitIssueSnapshot::PROVIDER
            && ($closeout['issue_id'] ?? null) === $expected->issueId
            && ($closeout['issue_key'] ?? null) === $expected->issueKey
            && ($closeout['contract_sha256'] ?? null) === $expected->contractHash
            && is_array($state) && ! array_is_list($state)
            && array_keys($state) === ['id', 'name', 'type']
            && is_string($state['id'] ?? null)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $state['id']) === 1
            && ($state['name'] ?? null) === 'Done'
            && ($state['type'] ?? null) === 'completed'
            && array_key_exists('assignee', $closeout) && $closeout['assignee'] === null
            && array_key_exists('delegate', $closeout) && $closeout['delegate'] === null
            && is_string($closeout['updated_at'] ?? null)
            && trim($closeout['updated_at']) !== '';
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

        $phase = PhaseRun::query()->findOrFail($phaseId);

        if ($phase->current_block === 'proof_closeout') {
            $delay = $this->closeProofTopology($config, $deliveryId, $phaseId);

            if ($delay !== null) {
                return $delay;
            }
        }

        $phase = PhaseRun::query()->findOrFail($phaseId);

        if ($phase->current_block === 'worktree_cleanup') {
            $this->cleanupWorktree($config, $deliveryId, $phaseId);
        }

        $phase = PhaseRun::query()->findOrFail($phaseId);

        if ($phase->current_block === 'linear_closeout') {
            $this->closeLinearIssue($deliveryId, $phaseId);
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
            'proof_closeout',
            'worktree_cleanup',
            'linear_closeout',
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

        if (in_array($phase->current_block, ['workspace_shutdown', 'proof_closeout', 'worktree_cleanup', 'linear_closeout', 'reservation_release'], true)) {
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

            if ($phase !== null && in_array($phase->current_block, ['workspace_shutdown', 'proof_closeout', 'worktree_cleanup', 'linear_closeout', 'reservation_release'], true)
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

            if ($phase !== null && in_array($phase->current_block, ['proof_closeout', 'worktree_cleanup', 'linear_closeout', 'reservation_release'], true)
                && $this->matchesClosedWorkspace($delivery, $shutdown)) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'workspace_shutdown'
                || ! is_array($output) || ! $this->matchesClosedWorkspace($delivery, $shutdown)) {
                throw new OrbitLandingAdvancementFailed(
                    'The verified Orbit Herdr workspace shutdown no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'proof_closeout';
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

    private function closeProofTopology(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): ?int {
        $delivery = $this->delivery($deliveryId);
        $intent = $this->landingIntent($delivery, $phaseId);
        $phase = $intent[0] ?? null;
        $implementation = $intent[2] ?? null;
        $output = $phase?->output;
        $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
        $verification = $this->associativeArray(
            is_array($output) ? ($output['merge_verification'] ?? null) : null,
        );
        $reconciliation = $this->associativeArray(
            is_array($output) ? ($output['repository_reconciliation'] ?? null) : null,
        );
        $flow = $verification['flow'] ?? null;

        if ($phase === null || $phase->current_block !== 'proof_closeout'
            || $implementation === null || $merge === null || $verification === null
            || $reconciliation === null || ! in_array($flow, ['discovery', 'proof'], true)
            || ($implementation->payload['flow'] ?? null) !== $flow) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout cannot run from its retained landing ledger.',
            );
        }

        if ($flow === 'discovery') {
            $this->recordProofCloseout($deliveryId, $phaseId, [
                'schema' => 1,
                'flow' => 'discovery',
                'required' => false,
            ]);

            return null;
        }

        $worktree = $delivery->worktree_path;
        $issueKey = $delivery->external_issue_key;

        if (! is_string($worktree) || ! is_string($issueKey)) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout has invalid delivery bindings.',
            );
        }

        $closeout = $this->proofTopologies->closeProofTopology(
            $config,
            $worktree,
            $issueKey,
            $this->sha($merge, 'candidate_sha'),
            $this->sha($implementation->payload, 'artifact_sha'),
            $this->sha($merge, 'merge_commit_sha'),
            $this->sha($reconciliation, 'main_sha'),
        );

        if (! $closeout->complete()) {
            $this->markProofCloseoutWait($deliveryId, $phaseId, $closeout);

            return self::MAINTENANCE_RETRY_SECONDS;
        }

        $this->recordProofCloseout($deliveryId, $phaseId, [
            'schema' => 1,
            'flow' => 'proof',
            'required' => true,
            'record' => $closeout->toArray(),
        ]);

        return null;
    }

    /** @param array<string, mixed> $evidence */
    private function recordProofCloseout(int $deliveryId, int $phaseId, array $evidence): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId, $evidence): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $implementation = $intent[2] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $reconciliation = $this->associativeArray(
                is_array($output) ? ($output['repository_reconciliation'] ?? null) : null,
            );
            $verification = $this->associativeArray(
                is_array($output) ? ($output['merge_verification'] ?? null) : null,
            );
            $flow = $verification['flow'] ?? null;
            $artifactSha = $implementation?->payload['artifact_sha'] ?? null;

            $retryRecord = $this->associativeArray($evidence['record'] ?? null);

            if ($retryRecord !== null) {
                $this->assertProofCloseoutRetryIdentity($delivery, $phase, $retryRecord);
            }

            if ($phase !== null && $phase->current_block === 'worktree_cleanup'
                && is_array($output) && ($output['proof_closeout'] ?? null) === $evidence) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'proof_closeout'
                || $implementation === null || ! is_array($output)
                || $merge === null || $reconciliation === null || ! is_string($flow)
                || ! $this->matchesProofCloseout(
                    $delivery,
                    $merge,
                    $reconciliation,
                    $evidence,
                    $flow,
                    $artifactSha,
                )
                || array_key_exists('proof_closeout', $output)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit proof closeout no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'worktree_cleanup';
            $phase->output = [...$output, 'proof_closeout' => $evidence];
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function markProofCloseoutWait(
        int $deliveryId,
        int $phaseId,
        OrbitProofCloseout $closeout,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $closeout): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;

            if ($phase === null || $phase->current_block !== 'proof_closeout') {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit landing ledger changed while recording its proof closeout wait.',
                );
            }

            $this->assertProofCloseoutRetryIdentity($delivery, $phase, $closeout->toArray());

            $delivery->failure_details = [
                'code' => 'landing_proof_closeout_wait',
                'phase_run_id' => $phase->id,
                'record' => $closeout->toArray(),
            ];
            $delivery->save();
        });
    }

    /** @param array<string, mixed> $record */
    private function assertProofCloseoutRetryIdentity(
        Delivery $delivery,
        ?PhaseRun $phase,
        array $record,
    ): void {
        $failure = $delivery->failure_details;

        if (! is_array($failure) || ($failure['code'] ?? null) !== 'landing_proof_closeout_wait') {
            return;
        }

        $retained = $failure['record'] ?? null;
        $identity = [
            'issue',
            'attempt_id',
            'candidate_sha',
            'artifact_sha',
            'merge_sha',
        ];

        if ($phase === null || ($failure['phase_run_id'] ?? null) !== $phase->id
            || ! is_array($retained) || array_is_list($retained)
            || collect($identity)->contains(
                static fn (string $key): bool => ($retained[$key] ?? null) !== ($record[$key] ?? null),
            )) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout retry identity changed.',
            );
        }
    }

    private function cleanupWorktree(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): void {
        $delivery = $this->delivery($deliveryId);
        $landing = $this->landingIntent($delivery, $phaseId);
        $phase = $landing[0] ?? null;
        $implementation = $landing[2] ?? null;
        $output = $phase?->output;
        $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
        $proofEvidence = $this->associativeArray(
            is_array($output) ? ($output['proof_closeout'] ?? null) : null,
        );
        $flow = $implementation?->payload['flow'] ?? null;
        $artifactSha = $implementation?->payload['artifact_sha'] ?? null;

        if ($phase === null || $phase->current_block !== 'worktree_cleanup'
            || $implementation === null || ! is_array($output) || $merge === null
            || $proofEvidence === null || ! is_string($flow) || ! is_string($artifactSha)
            || ! $this->matchesProofCloseout(
                $delivery,
                $merge,
                $this->associativeArray($output['repository_reconciliation'] ?? null),
                $proofEvidence,
                $flow,
                $artifactSha,
            )) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit worktree cleanup cannot run from its retained landing ledger.',
            );
        }

        [$cleanupIntent, $authorization] = $this->worktreeCleanupIntent(
            $config,
            $deliveryId,
            $phaseId,
        );
        $resume = $authorization !== null;
        $proofCloseout = $this->proofCloseoutFromEvidence($proofEvidence);
        $issueKey = $this->string($cleanupIntent, 'issue_key');
        $worktree = $this->string($cleanupIntent, 'worktree');
        $branch = $this->string($cleanupIntent, 'branch');
        $candidateSha = $this->sha($cleanupIntent, 'candidate_sha');
        $cleanupArtifactSha = $this->sha($cleanupIntent, 'artifact_sha');
        $cleanupAttemptId = $this->string($cleanupIntent, 'attempt_id');

        if (! $resume) {
            $authorization = $this->worktreeCleaner->prepareWorktreeRemoval(
                $config,
                $issueKey,
                $worktree,
                $branch,
                $candidateSha,
                $cleanupArtifactSha,
                $proofCloseout,
                $cleanupAttemptId,
            );
            $this->recordWorktreeCleanupAuthorization(
                $deliveryId,
                $phaseId,
                $cleanupIntent,
                $authorization,
            );
        }

        $removed = $this->worktreeCleaner->removeWorktree(
            $config,
            $issueKey,
            $worktree,
            $branch,
            $candidateSha,
            $cleanupArtifactSha,
            $proofCloseout,
            $cleanupAttemptId,
            $authorization,
            $resume,
        );
        $this->recordWorktreeCleanup($deliveryId, $phaseId, $cleanupIntent, $removed);
    }

    /** @return array{array<string, mixed>, ?PreparedOrbitWorktreeRemoval} */
    private function worktreeCleanupIntent(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): array {
        return DB::transaction(function () use ($config, $deliveryId, $phaseId): array {
            $delivery = $this->lockLedger($deliveryId);
            $landing = $this->landingIntent($delivery, $phaseId);
            $phase = $landing[0] ?? null;
            $implementation = $landing[2] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $proofCloseout = $this->associativeArray(
                is_array($output) ? ($output['proof_closeout'] ?? null) : null,
            );
            $existing = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null,
            );
            $authorization = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_authorization'] ?? null) : null,
            );
            $flow = $implementation?->payload['flow'] ?? null;
            $artifactSha = $implementation?->payload['artifact_sha'] ?? null;

            if ($phase === null || $phase->current_block !== 'worktree_cleanup'
                || $implementation === null || ! is_array($output) || $merge === null
                || $proofCloseout === null || ! is_string($flow) || ! is_string($artifactSha)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit worktree cleanup intent cannot be retained from its landing ledger.',
                );
            }

            if ($existing !== null) {
                if (! $this->matchesWorktreeCleanupState(
                    $delivery,
                    $merge,
                    $output['repository'] ?? null,
                    $proofCloseout,
                    $existing,
                    $authorization,
                    null,
                    'worktree_cleanup',
                    $flow,
                    $artifactSha,
                )) {
                    throw new OrbitLandingAdvancementFailed(
                        'The retained Orbit worktree cleanup intent is inconsistent.',
                    );
                }

                return [
                    $existing,
                    $authorization === null
                        ? null
                        : PreparedOrbitWorktreeRemoval::fromArray($authorization),
                ];
            }

            $record = $this->proofCloseoutFromEvidence($proofCloseout);
            $issueKey = $delivery->external_issue_key;
            $worktree = $delivery->worktree_path;

            if (! is_string($issueKey) || ! is_string($worktree)
                || $config->repository !== ($output['repository'] ?? null)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit worktree cleanup has invalid delivery bindings.',
                );
            }

            $intent = [
                'schema' => 1,
                'attempt_id' => bin2hex(random_bytes(16)),
                'repository' => $config->repository,
                'issue_key' => $issueKey,
                'worktree' => $worktree,
                'branch' => strtolower($issueKey),
                'candidate_sha' => $this->sha($merge, 'candidate_sha'),
                'artifact_sha' => $this->sha($implementation->payload, 'artifact_sha'),
                'proof_attempt_id' => $record?->attemptId,
                'started_at' => now()->toISOString(),
            ];

            if (! $this->matchesWorktreeCleanupState(
                $delivery,
                $merge,
                $output['repository'],
                $proofCloseout,
                $intent,
                null,
                null,
                'worktree_cleanup',
                $flow,
                $artifactSha,
            )) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit worktree cleanup intent is inconsistent.',
                );
            }

            $phase->output = [...$output, 'worktree_cleanup_intent' => $intent];
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();

            return [$intent, null];
        });
    }

    /** @param array<string, mixed> $intent */
    private function recordWorktreeCleanupAuthorization(
        int $deliveryId,
        int $phaseId,
        array $intent,
        PreparedOrbitWorktreeRemoval $authorization,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $intent, $authorization): void {
            $delivery = $this->lockLedger($deliveryId);
            $landing = $this->landingIntent($delivery, $phaseId);
            $phase = $landing[0] ?? null;
            $implementation = $landing[2] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $proofCloseout = $this->associativeArray(
                is_array($output) ? ($output['proof_closeout'] ?? null) : null,
            );
            $retainedIntent = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null,
            );
            $existing = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_authorization'] ?? null) : null,
            );
            $evidence = $authorization->toArray();
            $flow = $implementation?->payload['flow'] ?? null;
            $artifactSha = $implementation?->payload['artifact_sha'] ?? null;

            if ($phase === null || $phase->current_block !== 'worktree_cleanup'
                || $implementation === null || ! is_array($output) || $merge === null
                || $proofCloseout === null || $retainedIntent !== $intent
                || ! is_string($flow) || ! is_string($artifactSha)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit worktree cleanup authorization no longer matches its landing ledger.',
                );
            }

            if ($existing !== null) {
                if ($existing === $evidence && $this->matchesWorktreeCleanupState(
                    $delivery,
                    $merge,
                    $output['repository'] ?? null,
                    $proofCloseout,
                    $intent,
                    $existing,
                    null,
                    'worktree_cleanup',
                    $flow,
                    $artifactSha,
                )) {
                    return;
                }

                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit worktree cleanup authorization is inconsistent.',
                );
            }

            if (! $this->matchesWorktreeCleanupState(
                $delivery,
                $merge,
                $output['repository'] ?? null,
                $proofCloseout,
                $intent,
                $evidence,
                null,
                'worktree_cleanup',
                $flow,
                $artifactSha,
            )) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit worktree cleanup authorization is inconsistent.',
                );
            }

            $phase->output = [...$output, 'worktree_cleanup_authorization' => $evidence];
            $phase->save();
        });
    }

    /** @param array<string, mixed> $proofCloseout */
    private function proofCloseoutFromEvidence(array $proofCloseout): ?OrbitProofCloseout
    {
        if (($proofCloseout['flow'] ?? null) === 'discovery') {
            return null;
        }

        $record = $this->associativeArray($proofCloseout['record'] ?? null);

        if ($record === null) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout record is unavailable for worktree cleanup.',
            );
        }

        try {
            $closeout = OrbitProofCloseout::fromArray($record);
        } catch (\InvalidArgumentException $exception) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout record is invalid for worktree cleanup.',
                previous: $exception,
            );
        }

        if (! $closeout->complete()) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit proof closeout is incomplete before worktree cleanup.',
            );
        }

        return $closeout;
    }

    /** @param array<string, mixed> $intent */
    private function recordWorktreeCleanup(
        int $deliveryId,
        int $phaseId,
        array $intent,
        RemovedOrbitWorktree $removed,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $intent, $removed): void {
            $delivery = $this->lockLedger($deliveryId);
            $landing = $this->landingIntent($delivery, $phaseId);
            $phase = $landing[0] ?? null;
            $implementation = $landing[2] ?? null;
            $output = $phase?->output;
            $merge = $this->associativeArray(is_array($output) ? ($output['merge'] ?? null) : null);
            $proofCloseout = $this->associativeArray(
                is_array($output) ? ($output['proof_closeout'] ?? null) : null,
            );
            $retainedIntent = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null,
            );
            $authorization = $this->associativeArray(
                is_array($output) ? ($output['worktree_cleanup_authorization'] ?? null) : null,
            );
            $flow = $implementation?->payload['flow'] ?? null;
            $artifactSha = $implementation?->payload['artifact_sha'] ?? null;
            $evidence = $removed->toArray();

            if ($phase !== null && in_array($phase->current_block, ['linear_closeout', 'reservation_release'], true)
                && is_array($output) && ($output['worktree_cleanup'] ?? null) === $evidence) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'worktree_cleanup'
                || $implementation === null || ! is_array($output) || $merge === null
                || $proofCloseout === null || $retainedIntent !== $intent || $authorization === null
                || ! is_string($flow) || ! is_string($artifactSha)
                || ! $this->matchesWorktreeCleanupState(
                    $delivery,
                    $merge,
                    $output['repository'] ?? null,
                    $proofCloseout,
                    $intent,
                    $authorization,
                    $evidence,
                    'linear_closeout',
                    $flow,
                    $artifactSha,
                )
                || array_key_exists('worktree_cleanup', $output)) {
                throw new OrbitLandingAdvancementFailed(
                    'The removed Orbit worktree no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'linear_closeout';
            $phase->output = [...$output, 'worktree_cleanup' => $evidence];
            $phase->save();
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    private function closeLinearIssue(int $deliveryId, int $phaseId): void
    {
        $delivery = $this->delivery($deliveryId);
        $intent = $this->landingIntent($delivery, $phaseId);
        $phase = $intent[0] ?? null;
        $expected = $this->preparations->startup($delivery)->snapshot;

        if ($phase === null || $phase->current_block !== 'linear_closeout') {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit issue cannot be completed from its retained landing ledger.',
            );
        }

        $completed = $this->issueCompletion->transitionToDone(
            $expected->issueId,
            $expected->issueKey,
            $expected->contractHash,
        );
        $state = $completed->payload['state'] ?? null;

        if (! is_array($state)
            || ! array_key_exists('assignee', $completed->payload)
            || ! array_key_exists('delegate', $completed->payload)) {
            throw new OrbitLandingAdvancementFailed(
                'The completed Orbit issue returned incomplete Linear evidence.',
            );
        }

        $evidence = [
            'schema' => 1,
            'provider' => OrbitIssueSnapshot::PROVIDER,
            'issue_id' => $completed->issueId,
            'issue_key' => $completed->issueKey,
            'contract_sha256' => $completed->contractHash,
            'state' => $state,
            'assignee' => $completed->payload['assignee'],
            'delegate' => $completed->payload['delegate'],
            'updated_at' => $completed->payload['updatedAt'] ?? null,
        ];

        $this->recordLinearCloseout($deliveryId, $phaseId, $evidence);
    }

    /** @param array<string, mixed> $evidence */
    private function recordLinearCloseout(int $deliveryId, int $phaseId, array $evidence): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId, $evidence): void {
            $delivery = $this->lockLedger($deliveryId);
            $intent = $this->landingIntent($delivery, $phaseId);
            $phase = $intent[0] ?? null;
            $output = $phase?->output;

            if ($phase !== null && $phase->current_block === 'reservation_release'
                && is_array($output) && ($output['linear_closeout'] ?? null) === $evidence) {
                return;
            }

            if ($phase === null || $phase->current_block !== 'linear_closeout'
                || ! is_array($output) || array_key_exists('linear_closeout', $output)
                || ! $this->matchesLinearCloseout($delivery, $evidence)) {
                throw new OrbitLandingAdvancementFailed(
                    'The completed Orbit issue no longer matches its landing ledger.',
                );
            }

            $phase->current_block = 'reservation_release';
            $phase->output = [...$output, 'linear_closeout' => $evidence];
            $phase->save();
            $delivery->failure_details = null;
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
            $locked->status = DeliveryStatus::Completed;
            $locked->completed_at = $lockedPhase->finished_at;
            $locked->completion_details = [
                'schema' => 1,
                'landing_phase_run_id' => $lockedPhase->id,
            ];
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
        return $this->matchesCommanderCompletion($delivery, $phase);
    }

    private function matchesCommanderCompletion(Delivery $delivery, PhaseRun $phase): bool
    {
        return $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
            && $delivery->status === DeliveryStatus::Completed
            && $phase->delivery_id === $delivery->id
            && $phase->phase_name === OrbitFeatureWorkflow::LANDING_PHASE
            && $phase->attempt === 1 && $phase->status === PhaseRunStatus::Completed
            && $phase->current_block === null && $phase->output !== null
            && $phase->started_at !== null && $phase->finished_at !== null
            && $delivery->active_issue_key === null
            && $delivery->failure_details === null
            && $delivery->completed_at !== null
            && $delivery->completed_at->equalTo($phase->finished_at)
            && $delivery->completion_details === [
                'schema' => 1,
                'landing_phase_run_id' => $phase->id,
            ];
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

    /** @return array<string, string>|null */
    private function stringMap(mixed $value): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
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
