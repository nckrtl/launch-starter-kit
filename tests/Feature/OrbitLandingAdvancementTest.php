<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitLanding;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\RunOrbitMainCacheRefresh;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueCompletionTransitioner;
use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitProofTopologyCloser;
use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitWorktreeCleaner;
use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrForegroundProcess;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitLandingReservation;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedOrbitWorktreeRemoval;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;
use App\Delivery\Data\RemovedOrbitWorktree;
use App\Delivery\Data\RequestedOrbitMainCacheRefresh;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitMergeLineage;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\MaintenanceRunStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Exceptions\OrbitPullRequestLandingFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceOrbitLanding as AdvanceLandingJob;
use App\Jobs\RunOrbitMainCacheRefresh as RunMainCacheRefreshJob;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\MaintenanceRun;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class LandingRepository implements OrbitRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public mixed $reservationHandle = null;

    public ?Closure $afterReserve = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234');
        $this->calls++;
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve landing advancement.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'landing-advance.lock');
    }

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree
    {
        throw new LogicException('Not used by this test.');
    }

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck
    {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningHandoff(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        CandidateCheck $candidate,
        PreparedIssueSnapshot $snapshot,
    ): VerifiedOrbitPlanningRepository {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningArtifact(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        string $issueKey,
        string $artifactSha,
        string $expectedVerdict,
    ): VerifiedOrbitPlanningArtifact {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        throw new LogicException('Not used by this test.');
    }

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot {
        throw new LogicException('Not used by this test.');
    }

    public function verifyIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $snapshot,
    ): void {
        throw new LogicException('Not used by this test.');
    }

    public function reservationIsHeld(): bool
    {
        return is_resource($this->reservationHandle);
    }
}

final class LandingImplementationRepository implements OrbitImplementationRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public string $expectedReviewedCandidateSha;

    public string $flow = 'discovery';

    public function __construct()
    {
        $this->expectedReviewedCandidateSha = str_repeat('a', 40);
    }

    public function verifyImplementationOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $reviewedCandidateSha,
        string $candidateSha,
        string $artifactSha,
        string $gateReceiptPath,
        string $pullRequestBody,
    ): VerifiedOrbitImplementationOutcome {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($snapshot->issueKey)->toBe('ORB-234')
            ->and($reviewedCandidateSha)->toBe($this->expectedReviewedCandidateSha)
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('c', 40));
        $this->calls++;

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: str_repeat('7', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: $this->flow,
        );
    }
}

final class LandingCacheRefreshRequester implements OrbitMainCacheRefreshRequester
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $failures = 0;

    public string $disposition = 'queued';

    public function request(string $repository): RequestedOrbitMainCacheRefresh
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        if ($this->failures > 0) {
            $this->failures--;

            throw new OrbitRepositoryFailed('The cache request was not accepted.');
        }

        return new RequestedOrbitMainCacheRefresh(
            repository: $repository,
            disposition: $this->disposition,
            message: 'Main cache refresh queued (pid 123); log: /tmp/refresh.log',
        );
    }
}

final class LandingMergeLineageVerifier implements OrbitMergeLineageVerifier
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $failures = 0;

    public string $flow = 'discovery';

    public function verifyMergeLineage(
        OrbitProjectConfig $config,
        string $worktree,
        string $candidateSha,
        string $mergeCommitSha,
    ): VerifiedOrbitMergeLineage {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($worktree)->toBe(test()->worktreePath)
            ->and($candidateSha)->toBe(test()->candidateSha)
            ->and($mergeCommitSha)->toBe(str_repeat('f', 40))
            ->and(test()->repository->reservationIsHeld())->toBeTrue();
        $this->calls++;

        if ($this->failures > 0) {
            $this->failures--;

            throw new OrbitRepositoryFailed('The confirmed merge is not yet published.');
        }

        return new VerifiedOrbitMergeLineage(
            flow: $this->flow,
            candidateSha: $candidateSha,
            mergeCommitSha: $mergeCommitSha,
            treeSha: str_repeat('7', 40),
        );
    }
}

final class LandingPrimaryCheckoutReconciler implements OrbitPrimaryCheckoutReconciler
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $failures = 0;

    public function reconcilePrimaryCheckout(
        OrbitProjectConfig $config,
        string $mergeCommitSha,
    ): ReconciledOrbitPrimaryCheckout {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($config->repository)->toBe(test()->repositoryPath)
            ->and($mergeCommitSha)->toBe(str_repeat('f', 40))
            ->and(test()->repository->reservationIsHeld())->toBeTrue();
        $this->calls++;

        if ($this->failures > 0) {
            $this->failures--;

            throw new OrbitRepositoryFailed('The primary checkout is not ready.');
        }

        return new ReconciledOrbitPrimaryCheckout(
            repository: $config->repository,
            mergeCommitSha: $mergeCommitSha,
            mainSha: str_repeat('8', 40),
            originMainSha: str_repeat('8', 40),
        );
    }
}

final class LandingProofTopologyCloser implements OrbitProofTopologyCloser
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $failures = 0;

    public string $state = 'complete';

    public string $attemptId = '66666666666666666666666666666666';

    public ?string $generationId = 'generation-42';

    public function closeProofTopology(
        OrbitProjectConfig $config,
        string $worktree,
        string $issueKey,
        string $candidateSha,
        string $artifactSha,
        string $mergeCommitSha,
        string $mainSha,
    ): OrbitProofCloseout {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($config->repository)->toBe(test()->repositoryPath)
            ->and($worktree)->toBe(test()->worktreePath)
            ->and($issueKey)->toBe('ORB-234')
            ->and($candidateSha)->toBe(test()->candidateSha)
            ->and($artifactSha)->toBe(test()->artifactSha)
            ->and($mergeCommitSha)->toBe(str_repeat('f', 40))
            ->and($mainSha)->toBe(str_repeat('8', 40))
            ->and(test()->repository->reservationIsHeld())->toBeTrue();
        $this->calls++;

        if ($this->failures > 0) {
            $this->failures--;

            throw new OrbitRepositoryFailed('The proof closeout command was interrupted.');
        }

        $complete = $this->state === 'complete';

        return new OrbitProofCloseout(
            state: $this->state,
            issueKey: $issueKey,
            attemptId: $this->attemptId,
            candidateSha: $candidateSha,
            artifactSha: $artifactSha,
            mergeCommitSha: $mergeCommitSha,
            mainSha: $mainSha,
            generationId: $complete ? $this->generationId : null,
            error: $complete ? null : 'Snapshot refresh remains pending.',
            recordedAt: '2026-09-11T15:00:00Z',
        );
    }
}

final class LandingWorktreeCleaner implements OrbitWorktreeCleaner
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $prepareCalls = 0;

    public int $prepareFailures = 0;

    public int $failures = 0;

    /** @var list<bool> */
    public array $resumes = [];

    /** @var list<string> */
    public array $attemptIds = [];

    /** @var list<string|null> */
    public array $proofAttemptIds = [];

    public bool $returnMismatchedEvidence = false;

    public function prepareWorktreeRemoval(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
    ): PreparedOrbitWorktreeRemoval {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($config->repository)->toBe(test()->repositoryPath)
            ->and($issueKey)->toBe('ORB-234')
            ->and($worktree)->toBe(test()->worktreePath)
            ->and($branch)->toBe('orb-234')
            ->and($candidateSha)->toBe(test()->candidateSha)
            ->and($artifactSha)->toBe(test()->artifactSha)
            ->and($cleanupAttemptId)->toMatch('/^[a-f0-9]{32}$/')
            ->and(test()->repository->reservationIsHeld())->toBeTrue()
            ->and(test()->herdrWorkspace->closed)->toBeTrue();
        $this->prepareCalls++;

        if ($this->prepareFailures > 0) {
            $this->prepareFailures--;

            throw new OrbitRepositoryFailed('The worktree cleanup preflight was interrupted.');
        }

        if ($proofCloseout !== null) {
            expect($proofCloseout->attemptId)->toMatch('/^[a-f0-9]{32}$/');
        }

        $archives = $proofCloseout === null ? [] : [
            ".e2e/proof-evidence/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('1', 64),
            ".e2e/proof-review/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('2', 64),
            ".e2e/proof-review-evaluation/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('3', 64),
            ".e2e/proof-closeout/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('4', 64),
        ];

        return new PreparedOrbitWorktreeRemoval(
            repository: $config->repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            artifactRef: "refs/tags/loop/{$branch}/{$candidateSha}",
            artifactSha: $artifactSha,
            cleanupAttemptId: $cleanupAttemptId,
            proofAttemptId: $proofCloseout?->attemptId,
            protectedWorktrees: [[
                'worktree' => $config->repository,
                'head' => str_repeat('8', 40),
                'branch' => 'refs/heads/main',
                'prunable' => false,
            ]],
            protectedBranches: ['refs/heads/main' => str_repeat('8', 40)],
            evidenceArchives: $archives,
            authorizedAt: '2026-09-11T16:00:00Z',
        );
    }

    public function removeWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
        PreparedOrbitWorktreeRemoval $authorization,
        bool $resume,
    ): RemovedOrbitWorktree {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($config->repository)->toBe(test()->repositoryPath)
            ->and($issueKey)->toBe('ORB-234')
            ->and($worktree)->toBe(test()->worktreePath)
            ->and($branch)->toBe('orb-234')
            ->and($candidateSha)->toBe(test()->candidateSha)
            ->and($artifactSha)->toBe(test()->artifactSha)
            ->and($cleanupAttemptId)->toMatch('/^[a-f0-9]{32}$/')
            ->and($authorization->cleanupAttemptId)->toBe($cleanupAttemptId)
            ->and(test()->repository->reservationIsHeld())->toBeTrue()
            ->and(test()->herdrWorkspace->closed)->toBeTrue();
        $this->calls++;
        $this->resumes[] = $resume;
        $this->attemptIds[] = $cleanupAttemptId;
        $this->proofAttemptIds[] = $proofCloseout?->attemptId;

        if ($this->failures > 0) {
            $this->failures--;

            throw new OrbitRepositoryFailed('The worktree cleanup command was interrupted.');
        }

        $archives = $proofCloseout === null ? [] : [
            ".e2e/proof-evidence/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('1', 64),
            ".e2e/proof-review/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('2', 64),
            ".e2e/proof-review-evaluation/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('3', 64),
            ".e2e/proof-closeout/{$issueKey}/{$proofCloseout->attemptId}.json" => str_repeat('4', 64),
        ];

        return new RemovedOrbitWorktree(
            repository: $config->repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            artifactRef: "refs/tags/loop/{$branch}/{$candidateSha}",
            artifactSha: $artifactSha,
            cleanupAttemptId: $this->returnMismatchedEvidence
                ? str_repeat('9', 32)
                : $cleanupAttemptId,
            proofAttemptId: $proofCloseout?->attemptId,
            evidenceArchives: $archives,
            removedAt: '2026-09-11T16:00:00Z',
        );
    }
}

final class LandingIssues implements OrbitActiveIssueProvider, OrbitCloseoutIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $activeCalls = 0;

    public int $closeoutCalls = 0;

    public string $state = 'In Review';

    public mixed $assignee = null;

    public string $closeoutState = 'In Review';

    public mixed $closeoutAssignee = null;

    public mixed $closeoutDelegate = ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'];

    public bool $completeAfterCleanup = false;

    /** @var array<string, mixed>|null */
    public ?array $contractPayload = null;

    public bool $attachPullRequestAtCloseout = false;

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;
        $this->activeCalls++;

        if ($this->completeAfterCleanup && test()->worktreeCleaner->calls > 0) {
            return $this->closeoutSnapshot($issueId, $issueKey);
        }

        $payload = $this->contractPayload === null
            ? []
            : $this->contractPayload;
        $payload = [
            ...$payload,
            ...[
                'id' => $issueId,
                'identifier' => $issueKey,
                'state' => ['id' => 'review', 'name' => $this->state, 'type' => 'started'],
                'assignee' => $this->assignee,
                'delegate' => ['id' => config('commander.hermes.tom_linear_viewer_id')],
            ],
        ];

        return new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            $payload,
            $this->contractHash($payload),
        );
    }

    public function fetchForCloseout(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;
        $this->closeoutCalls++;

        return $this->closeoutSnapshot($issueId, $issueKey);
    }

    private function closeoutSnapshot(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $completed = $this->closeoutState === 'Done';
        $payload = $this->contractPayload === null
            ? []
            : $this->contractPayload;

        if ($this->attachPullRequestAtCloseout) {
            $payload['attachments'] = ['nodes' => [[
                'title' => $issueKey.': '.($payload['title'] ?? ''),
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
            ]]];
        }

        $payload = [
            ...$payload,
            ...[
                'id' => $issueId,
                'identifier' => $issueKey,
                'state' => [
                    'id' => $completed ? '77777777-8888-4999-8aaa-bbbbbbbbbbbb' : 'review',
                    'name' => $this->closeoutState,
                    'type' => $completed ? 'completed' : 'started',
                ],
                'assignee' => $this->closeoutAssignee,
                'delegate' => $this->closeoutDelegate,
            ],
        ];

        return new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            $payload,
            $this->contractHash($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    private function contractHash(array $payload): string
    {
        return $this->contractPayload === null
            ? str_repeat('d', 64)
            : app(OrbitIssueSnapshotFactory::class)->contractHash($payload);
    }
}

final class LandingIssueCompletion implements OrbitIssueCompletionTransitioner
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $failures = 0;

    public int $mutationCalls = 0;

    public bool $completed = false;

    public ?string $completedContractHash = null;

    public string $expectedContractHash = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function transitionToDone(
        string $issueId,
        string $issueKey,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueId)->toBe('11111111-2222-4333-8444-555555555555')
            ->and($issueKey)->toBe('ORB-234')
            ->and($expectedContractHash)->toBe($this->expectedContractHash)
            ->and(test()->repository->reservationIsHeld())->toBeTrue()
            ->and(test()->worktreeCleaner->calls)->toBeGreaterThanOrEqual(1);
        $this->calls++;
        $this->completedContractHash = $expectedContractHash;

        if (! $this->completed) {
            $this->mutationCalls++;
            $this->completed = true;

            if ($this->failures > 0) {
                $this->failures--;

                throw new OrbitIssueTransitionFailed('The Linear completion response is unresolved.', ambiguous: true);
            }
        }

        return new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            [
                'id' => $issueId,
                'identifier' => $issueKey,
                'updatedAt' => '2026-09-11T17:00:00.000Z',
                'state' => [
                    'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
                    'name' => 'Done',
                    'type' => 'completed',
                ],
                'assignee' => null,
                'delegate' => null,
            ],
            $expectedContractHash,
        );
    }
}

final class LandingMain implements OrbitMainCorrectnessInspector
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    /** @var array<string, mixed> */
    public array $failures = [];

    public function inspectMainCorrectness(OrbitProjectConfig $config): OrbitMainCorrectness
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        return new OrbitMainCorrectness(str_repeat('e', 40), $this->failures);
    }
}

final class LandingGateway implements OrbitPullRequestLandingGateway
{
    public int $transactionLevel = 0;

    public int $reserveCalls = 0;

    public int $inspectCalls = 0;

    public int $mergeCalls = 0;

    public int $releaseCalls = 0;

    public bool $owned = true;

    public ?bool $mergeable = true;

    public bool $approvalMismatch = false;

    public int $expectedReviewId = 901;

    public int $mergeFailures = 0;

    public int $releaseFailures = 0;

    public function reserve(string $issueId, string $pullRequestUrl): OrbitLandingReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->reserveCalls++;

        return new OrbitLandingReservation(
            owned: $this->owned,
            issueId: $this->owned ? $issueId : '22222222-3333-4444-8555-666666666666',
            pullRequestUrl: $this->owned ? $pullRequestUrl : 'https://github.com/nckrtl/orbit/pull/41',
            reservedAt: '2026-09-11T12:00:00Z',
        );
    }

    public function inspectApproved(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
        int $publishedReviewId,
    ): ApprovedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234')
            ->and($publishedReviewId)->toBe($this->expectedReviewId);
        $this->inspectCalls++;

        return new ApprovedOrbitPullRequest(
            number: $this->approvalMismatch ? 43 : $number,
            url: $this->approvalMismatch
                ? 'https://github.com/nckrtl/orbit/pull/43'
                : 'https://github.com/nckrtl/orbit/pull/42',
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            reviewId: $publishedReviewId,
            reviewerLogin: 'tom-nckrtl[bot]',
            mergeable: $this->mergeable,
        );
    }

    public function merge(int $number, string $candidateSha): MergedOrbitPullRequest
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->mergeCalls++;

        if ($this->mergeFailures > 0) {
            $this->mergeFailures--;

            throw new OrbitPullRequestLandingFailed('The merge outcome is unresolved.');
        }

        return new MergedOrbitPullRequest(
            number: $number,
            url: "https://github.com/nckrtl/orbit/pull/{$number}",
            candidateSha: $candidateSha,
            mergeCommitSha: str_repeat('f', 40),
        );
    }

    public function release(string $issueId, string $pullRequestUrl): void
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->releaseCalls++;

        if ($this->releaseFailures > 0) {
            $this->releaseFailures--;

            throw new OrbitPullRequestLandingFailed('The reservation release is unresolved.');
        }
    }
}

final class LandingHerdrWorkspace implements HerdrWorkspaceRuntime
{
    public int $snapshotCalls = 0;

    public int $closeCalls = 0;

    public bool $closed = false;

    public bool $agentsRemain = false;

    /** @var list<array{name: string, keys: list<string>}> */
    public array $sentKeys = [];

    public function __construct(
        private readonly string $repository,
        private readonly string $worktree,
    ) {}

    public function snapshot(): HerdrSessionSnapshot
    {
        $this->snapshotCalls++;
        $canonical = new HerdrSnapshotWorkspace('canonical-workspace', null, null, null);
        $canonicalPane = new HerdrSnapshotPane(
            'canonical-workspace',
            'canonical-tab',
            'canonical-pane',
            'canonical-terminal',
            $this->repository,
        );

        if ($this->closed) {
            return new HerdrSessionSnapshot('0.9.0', 22, [$canonical], [$canonicalPane], []);
        }

        return new HerdrSessionSnapshot(
            '0.9.0',
            22,
            [
                $canonical,
                new HerdrSnapshotWorkspace(
                    'issue-workspace',
                    $this->repository,
                    $this->worktree,
                    true,
                ),
            ],
            [
                $canonicalPane,
                new HerdrSnapshotPane(
                    'issue-workspace',
                    'issue-tab',
                    'issue-shell',
                    'issue-shell-terminal',
                    $this->worktree,
                ),
            ],
            $this->agentsRemain ? [new HerdrSnapshotAgent(
                'issue-workspace',
                'issue-tab',
                'issue-shell',
                'issue-agent-terminal',
                'codex',
                'orb-234-loop-builder',
                'idle',
                $this->worktree,
            )] : [],
        );
    }

    public function readAgent(string $name): HerdrAgentOutput
    {
        return new HerdrAgentOutput(
            'issue-workspace',
            'issue-tab',
            'issue-shell',
            'The agent remains idle.',
        );
    }

    public function sendAgentKeys(string $name, array $keys): void
    {
        $this->sentKeys[] = ['name' => $name, 'keys' => $keys];
    }

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo
    {
        expect($paneId)->toBe('issue-shell');

        return new HerdrPaneProcessInfo(
            $paneId,
            100,
            100,
            [new HerdrForegroundProcess(100, 'zsh')],
        );
    }

    public function closeWorkspace(string $workspaceId, int $protocol): void
    {
        expect($workspaceId)->toBe('issue-workspace');
        expect($protocol)->toBe(22);
        $this->closeCalls++;
        $this->closed = true;
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-landing-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->reviewedSha = str_repeat('a', 40);
    $this->candidateSha = str_repeat('b', 40);
    $this->artifactSha = str_repeat('c', 40);
    $this->gatePath = $this->repositoryPath.'/.git/orbit-checks/'.$this->candidateSha.'/review/result.json';
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Flow: discovery',
        'Builder gate: passed ('.$this->gatePath.')',
        'Approved body.',
    ]);

    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('herdr.session', 'orbit');
    app(SharedKnowledgeProjectRepository::class)->create('orbit', [
        'name' => 'Orbit',
        'status' => 'active',
    ]);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => $this->repositoryPath,
        'worktreeRoot' => $this->worktreeRoot,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            $this->worktreePath.'/.loop/issue.json',
        ),
        $this->worktreePath,
        new CandidateCheck(
            $this->repositoryPath.'/.git/orbit-checks/'.$this->reviewedSha.'/startup/result.json',
            $this->reviewedSha,
            str_repeat('9', 40),
        ),
    );

    $planning = PhaseRun::sole();
    $planner = landingDispatch($planning, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'planner');
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/planning.md', 'handoff' => 'Planning ready.',
        'artifact_sha' => str_repeat('1', 40), 'plan_sha256' => str_repeat('2', 64),
    ];
    $planningReceipt = landingReceipt($planning, 'orbit_planning', $planningPayload);
    landingComplete($planning, [
        'receipt_id' => $planningReceipt->id,
        'result' => 'ready',
    ]);

    $planReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $planReviewer = landingDispatch($planReview, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'plan-reviewer');
    $planReviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planReviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/plan-review.md', 'handoff' => 'Plan approved.',
        'artifact_sha' => str_repeat('3', 40), 'plan_sha256' => str_repeat('4', 64),
    ];
    $planReviewReceipt = landingReceipt($planReview, 'orbit_plan_review', $planReviewPayload);
    landingComplete($planReview, [
        'receipt_id' => $planReviewReceipt->id,
        'result' => 'pass',
    ]);

    $implementation = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'plan_review_receipt_id' => $planReviewReceipt->id,
            'plan_review_receipt' => $planReviewPayload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $builder = landingDispatch($implementation, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'builder');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            1,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_hash' => str_repeat('5', 64),
        'dispatched_at' => now(),
    ])->save();
    $implementationPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'reviewed_candidate_sha' => $this->reviewedSha,
        'candidate_sha' => $this->candidateSha,
        'handoff_path' => '.loop/runtime/implementation.md', 'handoff' => 'Implementation ready.',
        'artifact_sha' => $this->artifactSha,
        'gate_receipt_path' => $this->gatePath,
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $this->body,
        'pull_request_body_sha256' => hash('sha256', $this->body),
        'flow' => 'discovery',
    ];
    $this->implementationReceipt = landingReceipt(
        $implementation,
        'orbit_implementation',
        $implementationPayload,
    );
    landingComplete($implementation, [
        'receipt_id' => $this->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]);

    $review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $reviewer = landingDispatch($review, OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE, 'pr-reviewer');
    $reviewer->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            1,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'issue-workspace',
        'herdr_tab_id' => 'issue-tab',
        'herdr_pane_id' => 'issue-pr-reviewer-pane',
        'herdr_terminal_id' => 'issue-pr-reviewer-terminal',
        'herdr_agent_id' => 'review-agent',
        'dispatched_at' => now(),
    ])->save();
    $reviewPrompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $this->worktreePath,
        $this->delivery->id,
        $review->id,
        $reviewer->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $review->id,
            $reviewer->id,
        ),
        $implementationPayload,
        $review->input['pull_request'],
    );
    $reviewer->forceFill(['prompt_hash' => hash('sha256', $reviewPrompt)])->save();
    $reviewPayload = [
        'kind' => 'orbit_pr_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'approved', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->candidateSha,
        'handoff_path' => '.loop/runtime/pr-review.md', 'handoff' => 'Approved.',
        'artifact_sha' => $this->artifactSha,
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $this->body,
        'pull_request_body_sha256' => hash('sha256', $this->body),
    ];
    $this->reviewReceipt = landingReceipt($review, 'orbit_pr_review', $reviewPayload);
    $this->published = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => $this->candidateSha,
        'state' => 'APPROVED',
        'review_body_sha256' => hash('sha256', 'Approved.'),
        'pull_request_body_sha256' => hash('sha256', $this->body),
    ];
    landingComplete($review, [
        'receipt_id' => $this->reviewReceipt->id,
        'result' => 'approved',
        'published_review' => $this->published,
    ]);

    $this->landing = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::LANDING_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'pr_review_receipt_id' => $this->reviewReceipt->id,
            'pr_review_receipt' => $reviewPayload,
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $implementationPayload,
            'pull_request' => $review->input['pull_request'],
            'published_review' => $this->published,
        ],
    ]);
    $this->delivery->forceFill([
        'candidate_sha' => $this->candidateSha,
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::LANDING_PHASE,
        'status' => DeliveryStatus::ReadyToMerge,
    ])->save();

    $this->repository = new LandingRepository;
    $this->implementations = new LandingImplementationRepository;
    $this->issues = new LandingIssues;
    $this->issueCompletion = new LandingIssueCompletion;
    $this->main = new LandingMain;
    $this->gateway = new LandingGateway;
    $this->merges = new LandingMergeLineageVerifier;
    $this->primaryCheckout = new LandingPrimaryCheckoutReconciler;
    $this->proofTopologies = new LandingProofTopologyCloser;
    $this->worktreeCleaner = new LandingWorktreeCleaner;
    $this->herdrWorkspace = new LandingHerdrWorkspace($this->repositoryPath, $this->worktreePath);
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->implementations->transactionLevel = $transactionLevel;
    $this->issues->transactionLevel = $transactionLevel;
    $this->issueCompletion->transactionLevel = $transactionLevel;
    $this->main->transactionLevel = $transactionLevel;
    $this->gateway->transactionLevel = $transactionLevel;
    $this->merges->transactionLevel = $transactionLevel;
    $this->primaryCheckout->transactionLevel = $transactionLevel;
    $this->proofTopologies->transactionLevel = $transactionLevel;
    $this->worktreeCleaner->transactionLevel = $transactionLevel;
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitImplementationRepository::class, $this->implementations);
    app()->instance(OrbitActiveIssueProvider::class, $this->issues);
    app()->instance(OrbitCloseoutIssueProvider::class, $this->issues);
    app()->instance(OrbitIssueCompletionTransitioner::class, $this->issueCompletion);
    app()->instance(OrbitMainCorrectnessInspector::class, $this->main);
    app()->instance(OrbitPullRequestLandingGateway::class, $this->gateway);
    app()->instance(OrbitMergeLineageVerifier::class, $this->merges);
    app()->instance(OrbitPrimaryCheckoutReconciler::class, $this->primaryCheckout);
    app()->instance(OrbitProofTopologyCloser::class, $this->proofTopologies);
    app()->instance(OrbitWorktreeCleaner::class, $this->worktreeCleaner);
    app()->instance(HerdrWorkspaceRuntime::class, $this->herdrWorkspace);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function landingDispatch(PhaseRun $phase, string $role, string $name): AgentDispatch
{
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => "landing-{$name}",
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'issue-workspace',
        'herdr_tab_id' => 'issue-tab',
        'herdr_pane_id' => "issue-{$name}-pane",
        'herdr_terminal_id' => "issue-{$name}-terminal",
        'herdr_agent_id' => 'codex',
        'herdr_agent_name' => "orb-234-loop-{$name}",
        'prompt_name' => "orbit_{$name}",
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('6', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @param array<string, mixed> $payload */
function landingReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
{
    return Receipt::query()->create([
        'phase_run_id' => $phase->id,
        'kind' => $kind,
        'schema_version' => 1,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => $payload['candidate_sha'],
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
}

/** @param array<string, mixed> $output */
function landingComplete(PhaseRun $phase, array $output): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => $output,
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
}

function promoteLandingToSecondPullRequestReview(object $test): void
{
    $firstReview = $test->reviewReceipt->phaseRun;
    $firstReviewPayload = [
        ...$test->reviewReceipt->payload,
        'result' => 'changes',
        'handoff' => 'Fix the concrete review finding.',
        'pull_request_body_path' => null,
        'pull_request_body' => null,
        'pull_request_body_sha256' => null,
    ];
    DB::table('receipts')->where('id', $test->reviewReceipt->id)->update([
        'payload' => json_encode($firstReviewPayload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($firstReviewPayload, JSON_THROW_ON_ERROR)),
    ]);
    $test->reviewReceipt->refresh();
    $firstPublished = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => $test->candidateSha,
        'state' => 'CHANGES_REQUESTED',
        'review_body_sha256' => hash('sha256', 'Fix the concrete review finding.'),
        'pull_request_body_sha256' => hash('sha256', $test->body),
    ];
    landingComplete($firstReview, [
        'receipt_id' => $test->reviewReceipt->id,
        'result' => 'changes',
        'published_review' => $firstPublished,
    ]);
    $sourcePayload = $test->implementationReceipt->payload;
    $correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'pr_review_receipt_id' => $test->reviewReceipt->id,
            'pr_review_receipt' => $firstReviewPayload,
            'implementation_receipt_id' => $test->implementationReceipt->id,
            'implementation_receipt' => $sourcePayload,
            'pull_request' => $firstReview->input['pull_request'],
            'published_review' => $firstPublished,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $builder = landingDispatch($correction, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'review-correction');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            2,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_pr_review_correction',
        'prompt_hash' => str_repeat('7', 64),
        'dispatched_at' => now(),
    ])->save();
    $correctionPayload = [
        ...$sourcePayload,
        'dispatch_id' => $builder->id,
        'attempt' => 2,
        'reviewed_candidate_sha' => $test->candidateSha,
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => 'The review finding was corrected.',
    ];
    $correctionReceipt = landingReceipt($correction, 'orbit_implementation', $correctionPayload);
    landingComplete($correction, [
        'receipt_id' => $correctionReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]);
    $secondReview = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'implementation_receipt_id' => $correctionReceipt->id,
            'implementation_receipt' => $correctionPayload,
            'pull_request' => $firstReview->input['pull_request'],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $reviewer = landingDispatch($secondReview, OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE, 'pr-reviewer-2');
    $reviewer->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            2,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-2',
        'prompt_name' => 'orbit_pr_review',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'issue-workspace',
        'herdr_tab_id' => 'issue-tab',
        'herdr_pane_id' => 'issue-pr-reviewer-2-pane',
        'herdr_terminal_id' => 'issue-pr-reviewer-2-terminal',
        'herdr_agent_id' => 'review-agent-2',
        'dispatched_at' => now(),
    ])->save();
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $test->worktreePath,
        $test->delivery->id,
        $secondReview->id,
        $reviewer->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $secondReview->id,
            $reviewer->id,
        ),
        $correctionPayload,
        $secondReview->input['pull_request'],
    );
    $reviewer->forceFill(['prompt_hash' => hash('sha256', $prompt)])->save();
    $secondReviewPayload = [
        ...$firstReviewPayload,
        'dispatch_id' => $reviewer->id,
        'attempt' => 2,
        'result' => 'approved',
        'handoff' => 'Approved.',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $test->body,
        'pull_request_body_sha256' => hash('sha256', $test->body),
    ];
    $secondReviewReceipt = landingReceipt($secondReview, 'orbit_pr_review', $secondReviewPayload);
    $test->published = [
        'id' => 902,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => $test->candidateSha,
        'state' => 'APPROVED',
        'review_body_sha256' => hash('sha256', 'Approved.'),
        'pull_request_body_sha256' => hash('sha256', $test->body),
    ];
    landingComplete($secondReview, [
        'receipt_id' => $secondReviewReceipt->id,
        'result' => 'approved',
        'published_review' => $test->published,
    ]);
    $test->implementationReceipt = $correctionReceipt;
    $test->reviewReceipt = $secondReviewReceipt;
    $test->landing->forceFill(['input' => [
        'pr_review_receipt_id' => $secondReviewReceipt->id,
        'pr_review_receipt' => $secondReviewPayload,
        'implementation_receipt_id' => $correctionReceipt->id,
        'implementation_receipt' => $correctionPayload,
        'pull_request' => $secondReview->input['pull_request'],
        'published_review' => $test->published,
    ]])->save();
    $test->implementations->expectedReviewedCandidateSha = $test->candidateSha;
    $test->gateway->expectedReviewId = 902;
}

function useProofLandingFlow(object $test): void
{
    $planning = $test->delivery->phaseRuns()
        ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
        ->where('attempt', 1)
        ->sole();
    $planningInput = $planning->input;
    $planningInput['flow'] = 'proof';
    $planning->forceFill(['input' => $planningInput])->save();

    $proofBody = str_replace('Flow: discovery', 'Flow: proof', $test->body);
    $implementationPayload = $test->implementationReceipt->payload;
    $implementationPayload['pull_request_body'] = $proofBody;
    $implementationPayload['pull_request_body_sha256'] = hash('sha256', $proofBody);
    $implementationPayload['flow'] = 'proof';
    DB::table('receipts')->where('id', $test->implementationReceipt->id)->update([
        'payload' => json_encode($implementationPayload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($implementationPayload, JSON_THROW_ON_ERROR)),
    ]);
    $test->implementationReceipt = Receipt::query()->findOrFail($test->implementationReceipt->id);

    $review = $test->reviewReceipt->phaseRun()->firstOrFail();
    $reviewInput = $review->input;
    $reviewInput['implementation_receipt'] = $implementationPayload;
    $review->forceFill(['input' => $reviewInput])->save();
    $reviewer = $review->agentDispatches()->sole();
    $reviewPrompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $test->worktreePath,
        $test->delivery->id,
        $review->id,
        $reviewer->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $review->id,
            $reviewer->id,
        ),
        $implementationPayload,
        $reviewInput['pull_request'],
    );
    $reviewer->forceFill(['prompt_hash' => hash('sha256', $reviewPrompt)])->save();

    $reviewPayload = $test->reviewReceipt->payload;
    $reviewPayload['pull_request_body'] = $proofBody;
    $reviewPayload['pull_request_body_sha256'] = hash('sha256', $proofBody);
    DB::table('receipts')->where('id', $test->reviewReceipt->id)->update([
        'payload' => json_encode($reviewPayload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR)),
    ]);
    $test->reviewReceipt = Receipt::query()->findOrFail($test->reviewReceipt->id);

    $published = $test->published;
    $published['pull_request_body_sha256'] = hash('sha256', $proofBody);
    $review->forceFill(['output' => [
        'receipt_id' => $test->reviewReceipt->id,
        'result' => 'approved',
        'published_review' => $published,
    ]])->save();
    $landingInput = $test->landing->input;
    $landingInput['pr_review_receipt'] = $reviewPayload;
    $landingInput['implementation_receipt'] = $implementationPayload;
    $landingInput['published_review'] = $published;
    $test->landing->forceFill(['input' => $landingInput])->save();

    $test->body = $proofBody;
    $test->published = $published;
    $test->implementations->flow = 'proof';
    $test->merges->flow = 'proof';
}

it('lands one exact approved Orbit candidate and replays as a no-op', function () {
    $action = app(AdvanceOrbitLanding::class);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull();

    $delivery = $this->delivery->fresh();
    $landing = $this->landing->fresh();

    expect($delivery->status)->toBe(DeliveryStatus::Completed)
        ->and($delivery->active_issue_key)->toBeNull()
        ->and($delivery->completed_at)->not->toBeNull()
        ->and($delivery->completed_at?->equalTo($landing->finished_at))->toBeTrue()
        ->and($delivery->completion_details)->toBe([
            'schema' => 1,
            'landing_phase_run_id' => $landing->id,
        ])
        ->and($landing->status)->toBe(PhaseRunStatus::Completed)
        ->and($landing->current_block)->toBeNull()
        ->and($landing->output['main_sha'])->toBe(str_repeat('e', 40))
        ->and($landing->output['repository'])->toBe($this->repositoryPath)
        ->and($landing->output['merge']['candidate_sha'])->toBe($this->candidateSha)
        ->and($landing->output['merge']['merge_commit_sha'])->toBe(str_repeat('f', 40))
        ->and($landing->output['merge_verification'])->toBe([
            'flow' => 'discovery',
            'candidate_sha' => $this->candidateSha,
            'merge_commit_sha' => str_repeat('f', 40),
            'tree_sha' => str_repeat('7', 40),
        ])
        ->and($landing->output['repository_reconciliation'])->toBe([
            'repository' => $this->repositoryPath,
            'merge_commit_sha' => str_repeat('f', 40),
            'main_sha' => str_repeat('8', 40),
            'origin_main_sha' => str_repeat('8', 40),
        ])
        ->and($landing->output['workspace_shutdown']['closed'])->toMatchArray([
            'session' => 'orbit',
            'workspace_id' => 'issue-workspace',
            'worktree_path' => $this->worktreePath,
        ])
        ->and($landing->output['proof_closeout'])->toBe([
            'schema' => 1,
            'flow' => 'discovery',
            'required' => false,
        ])
        ->and($landing->output['worktree_cleanup_intent'])->toMatchArray([
            'schema' => 1,
            'repository' => $this->repositoryPath,
            'issue_key' => 'ORB-234',
            'worktree' => $this->worktreePath,
            'branch' => 'orb-234',
            'candidate_sha' => $this->candidateSha,
            'artifact_sha' => $this->artifactSha,
            'proof_attempt_id' => null,
        ])
        ->and($landing->output['worktree_cleanup_authorization'])->toMatchArray([
            'schema' => 1,
            'cleanup_attempt_id' => $landing->output['worktree_cleanup_intent']['attempt_id'],
        ])
        ->and($landing->output['worktree_cleanup'])->toMatchArray([
            'schema' => 1,
            'repository' => $this->repositoryPath,
            'issue_key' => 'ORB-234',
            'worktree' => $this->worktreePath,
            'branch' => 'orb-234',
            'candidate_sha' => $this->candidateSha,
            'artifact_sha' => $this->artifactSha,
            'proof_attempt_id' => null,
            'evidence_archives' => [],
        ])
        ->and($landing->output['linear_closeout'])->toBe([
            'schema' => 1,
            'provider' => 'linear',
            'issue_id' => '11111111-2222-4333-8444-555555555555',
            'issue_key' => 'ORB-234',
            'contract_sha256' => str_repeat('d', 64),
            'state' => [
                'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
                'name' => 'Done',
                'type' => 'completed',
            ],
            'assignee' => null,
            'delegate' => null,
            'updated_at' => '2026-09-11T17:00:00.000Z',
        ])
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->implementations->calls)->toBe(1)
        ->and($this->issues->calls)->toBe(2)
        ->and($this->main->calls)->toBe(2)
        ->and($this->gateway->reserveCalls)->toBe(1)
        ->and($this->gateway->inspectCalls)->toBe(1)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->proofTopologies->calls)->toBe(0)
        ->and($this->worktreeCleaner->prepareCalls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(1)
        ->and($this->worktreeCleaner->resumes)->toBe([false])
        ->and($this->herdrWorkspace->closeCalls)->toBe(1);

    $maintenance = MaintenanceRun::sole();
    expect($maintenance->status)->toBe(MaintenanceRunStatus::Pending)
        ->and($maintenance->attempt)->toBe(1)
        ->and($maintenance->input)->toBe([
            'schema' => 1,
            'landing_phase_run_id' => $landing->id,
            'repository' => $this->repositoryPath,
            'candidate_sha' => $this->candidateSha,
            'merge_commit_sha' => str_repeat('f', 40),
            'pre_merge_main_sha' => str_repeat('e', 40),
        ]);
    Queue::assertPushed(
        RunMainCacheRefreshJob::class,
        fn (RunMainCacheRefreshJob $job): bool => $job->maintenanceRunId === $maintenance->id,
    );

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(1)
        ->and(MaintenanceRun::count())->toBe(1);
    Queue::assertPushedTimes(RunMainCacheRefreshJob::class, 1);

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertNotPushed(AdvanceLandingJob::class);
});

it('rejects terminal ledger drift without replaying landing mutations', function (string $drift) {
    $action = app(AdvanceOrbitLanding::class);
    $action->handle($this->delivery->id, $this->landing->id);

    if ($drift === 'completion metadata') {
        $delivery = $this->delivery->fresh();
        $delivery->completion_details = [
            'schema' => 2,
            'landing_phase_run_id' => $this->landing->id,
        ];
        $delivery->save();
    } elseif ($drift === 'failure metadata') {
        $delivery = $this->delivery->fresh();
        $delivery->failure_details = ['code' => 'stale_failure'];
        $delivery->save();
    } else {
        DB::table('deliveries')->where('id', $this->delivery->id)->update([
            'active_issue_key' => str_repeat('a', 64),
        ]);
    }

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'landing intent is inconsistent');
    expect($this->gateway->mergeCalls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);
})->with([
    'completion metadata',
    'failure metadata',
    'active issue key',
]);

it('rolls back phase completion when delivery completion cannot be stored', function () {
    $rejectCompletion = true;
    Delivery::saving(function (Delivery $delivery) use (&$rejectCompletion): void {
        if ($rejectCompletion && $delivery->status === DeliveryStatus::Completed) {
            $rejectCompletion = false;

            throw new RuntimeException('completion storage failed');
        }
    });

    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(RuntimeException::class, 'completion storage failed');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->delivery->fresh()->completed_at)->toBeNull()
        ->and($this->delivery->fresh()->completion_details)->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->landing->fresh()->current_block)->toBe('reservation_release')
        ->and($this->landing->fresh()->finished_at)->toBeNull();

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->gateway->releaseCalls)->toBe(2);
});

it('closes a retained proof topology before releasing the merge reservation', function () {
    useProofLandingFlow($this);

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBeNull();

    $landing = $this->landing->fresh();
    expect($landing->status)->toBe(PhaseRunStatus::Completed)
        ->and($landing->output['merge_verification']['flow'])->toBe('proof')
        ->and($landing->output['proof_closeout'])->toBe([
            'schema' => 1,
            'flow' => 'proof',
            'required' => true,
            'record' => [
                'schema' => 1,
                'state' => 'complete',
                'issue' => 'ORB-234',
                'attempt_id' => str_repeat('6', 32),
                'candidate_sha' => $this->candidateSha,
                'artifact_sha' => $this->artifactSha,
                'merge_sha' => str_repeat('f', 40),
                'main_sha' => str_repeat('8', 40),
                'generation_id' => 'generation-42',
                'error' => null,
                'recorded_at' => '2026-09-11T15:00:00Z',
            ],
        ])
        ->and($this->proofTopologies->calls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->worktreeCleaner->proofAttemptIds)->toBe([str_repeat('6', 32)])
        ->and($landing->output['worktree_cleanup']['proof_attempt_id'])->toBe(str_repeat('6', 32))
        ->and($landing->output['worktree_cleanup']['evidence_archives'])->toHaveCount(4)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('retains the merge reservation while a structured proof closeout failure retries', function () {
    useProofLandingFlow($this);
    $this->proofTopologies->state = 'refresh-failed';
    $action = app(AdvanceOrbitLanding::class);

    expect($action->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'landing_proof_closeout_wait',
            'phase_run_id' => $this->landing->id,
            'record' => [
                'schema' => 1,
                'state' => 'refresh-failed',
                'issue' => 'ORB-234',
                'attempt_id' => str_repeat('6', 32),
                'candidate_sha' => $this->candidateSha,
                'artifact_sha' => $this->artifactSha,
                'merge_sha' => str_repeat('f', 40),
                'main_sha' => str_repeat('8', 40),
                'generation_id' => null,
                'error' => 'Snapshot refresh remains pending.',
                'recorded_at' => '2026-09-11T15:00:00Z',
            ],
        ])
        ->and($this->landing->fresh()->current_block)->toBe('proof_closeout')
        ->and($this->landing->fresh()->output)->not->toHaveKey('proof_closeout')
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and($this->proofTopologies->calls)->toBe(1);

    $this->proofTopologies->state = 'complete';

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->proofTopologies->calls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('retains proof closeout identity across structured retries', function () {
    useProofLandingFlow($this);
    $this->proofTopologies->state = 'refresh-failed';
    $action = app(AdvanceOrbitLanding::class);

    expect($action->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS);

    $this->proofTopologies->attemptId = str_repeat('7', 32);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'retry identity changed');
    expect($this->landing->fresh()->current_block)->toBe('proof_closeout')
        ->and($this->gateway->releaseCalls)->toBe(0);
});

it('recovers an interrupted proof closeout without repeating earlier landing mutations', function () {
    useProofLandingFlow($this);
    $this->proofTopologies->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'interrupted');
    expect($this->landing->fresh()->current_block)->toBe('proof_closeout')
        ->and($this->landing->fresh()->output)->not->toHaveKey('proof_closeout')
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->proofTopologies->calls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('retains cleanup intent and resumes an interrupted worktree removal without repeating landing mutations', function () {
    $this->worktreeCleaner->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'cleanup command was interrupted');

    $landing = $this->landing->fresh();
    $attemptId = $landing->output['worktree_cleanup_intent']['attempt_id'];
    expect($landing->current_block)->toBe('worktree_cleanup')
        ->and($landing->output)->toHaveKeys([
            'workspace_shutdown',
            'proof_closeout',
            'worktree_cleanup_intent',
            'worktree_cleanup_authorization',
        ])
        ->and($landing->output)->not->toHaveKey('worktree_cleanup')
        ->and($attemptId)->toMatch('/^[a-f0-9]{32}$/')
        ->and($landing->output['worktree_cleanup_authorization']['cleanup_attempt_id'])->toBe($attemptId)
        ->and($this->worktreeCleaner->prepareCalls)->toBe(1)
        ->and($this->worktreeCleaner->attemptIds)->toBe([$attemptId])
        ->and($this->worktreeCleaner->resumes)->toBe([false])
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->worktreeCleaner->attemptIds)->toBe([$attemptId, $attemptId])
        ->and($this->worktreeCleaner->resumes)->toBe([false, true])
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('does not enable cleanup resume semantics until exact-target preflight is retained', function () {
    $this->worktreeCleaner->prepareFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'cleanup preflight was interrupted');

    $landing = $this->landing->fresh();
    expect($landing->current_block)->toBe('worktree_cleanup')
        ->and($landing->output)->toHaveKey('worktree_cleanup_intent')
        ->and($landing->output)->not->toHaveKey('worktree_cleanup_authorization')
        ->and($this->worktreeCleaner->prepareCalls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->worktreeCleaner->prepareCalls)->toBe(2)
        ->and($this->worktreeCleaner->resumes)->toBe([false])
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('rejects a tampered cleanup intent before retrying worktree removal', function () {
    $this->worktreeCleaner->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'cleanup command was interrupted');

    $landing = $this->landing->fresh();
    $output = $landing->output;
    $output['worktree_cleanup_intent']['repository'] = '/tmp/other-repository';
    $landing->output = $output;
    $landing->save();

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'landing intent is inconsistent');

    expect($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and($this->landing->fresh()->current_block)->toBe('worktree_cleanup');
});

it('does not release the merge reservation without cleanup evidence matching its retained intent', function () {
    $this->worktreeCleaner->returnMismatchedEvidence = true;

    expect(fn () => app(AdvanceOrbitLanding::class)->handle(
        $this->delivery->id,
        $this->landing->id,
    ))->toThrow(OrbitLandingAdvancementFailed::class, 'no longer matches its landing ledger');

    expect($this->landing->fresh()->current_block)->toBe('worktree_cleanup')
        ->and($this->landing->fresh()->output)->toHaveKey('worktree_cleanup_intent')
        ->and($this->landing->fresh()->output)->not->toHaveKey('worktree_cleanup')
        ->and($this->gateway->releaseCalls)->toBe(0);
});

it('retains cleanup evidence and the merge reservation while Linear closeout retries', function () {
    $this->issueCompletion->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitIssueTransitionFailed::class, 'completion response is unresolved');

    $landing = $this->landing->fresh();
    expect($landing->current_block)->toBe('linear_closeout')
        ->and($landing->output)->toHaveKey('worktree_cleanup')
        ->and($landing->output)->not->toHaveKey('linear_closeout')
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(1)
        ->and($this->issueCompletion->mutationCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(1)
        ->and($this->worktreeCleaner->calls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(2)
        ->and($this->issueCompletion->mutationCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('records closeout when Linear already completed the merged issue', function () {
    $this->issues->closeoutState = 'Done';
    $this->issues->closeoutDelegate = null;
    $this->issues->completeAfterCleanup = true;
    $this->issueCompletion->completed = true;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBeNull();

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->landing->fresh()->output['linear_closeout']['state'])->toBe([
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ])
        ->and($this->issues->activeCalls)->toBe(1)
        ->and($this->issues->closeoutCalls)->toBe(1)
        ->and($this->issueCompletion->calls)->toBe(1)
        ->and($this->issueCompletion->mutationCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('records the retained contract when Linear adds the merged pull request attachment', function () {
    $payload = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'identifier' => 'ORB-234',
        'title' => 'Test issue',
        'description' => 'Deliver the tested change.',
        'labels' => ['nodes' => []],
        'attachments' => ['nodes' => []],
    ];
    $contractHash = app(OrbitIssueSnapshotFactory::class)->contractHash($payload);
    $planning = $this->delivery->phaseRuns()->oldest('id')->firstOrFail();
    $input = $planning->input;
    $input['issue_snapshot']['contract_sha256'] = $contractHash;
    $planning->input = $input;
    $planning->save();
    $this->delivery->active_issue_key = $contractHash;
    $this->delivery->save();
    $this->issues->contractPayload = $payload;
    $this->issues->attachPullRequestAtCloseout = true;
    $attachedPayload = $payload;
    $attachedPayload['attachments'] = ['nodes' => [[
        'title' => 'ORB-234: Test issue',
        'url' => 'https://github.com/nckrtl/orbit/pull/42',
    ]]];
    $this->issueCompletion->expectedContractHash = app(OrbitIssueSnapshotFactory::class)
        ->contractHash($attachedPayload);

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBeNull();

    expect($this->landing->fresh()->output['linear_closeout']['contract_sha256'])
        ->toBe($contractHash)
        ->not->toBe($this->issueCompletion->completedContractHash)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('runs queued main cache maintenance while proof closeout is waiting', function () {
    useProofLandingFlow($this);
    $this->proofTopologies->state = 'refresh-failed';

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS);

    $run = MaintenanceRun::sole();
    $requests = new LandingCacheRefreshRequester;
    $requests->transactionLevel = DB::transactionLevel();
    app()->instance(OrbitMainCacheRefreshRequester::class, $requests);
    app(RunOrbitMainCacheRefresh::class)->handle($run->id);

    expect($run->fresh()->status)->toBe(MaintenanceRunStatus::Completed)
        ->and($this->landing->fresh()->current_block)->toBe('proof_closeout')
        ->and($this->gateway->releaseCalls)->toBe(0);
});

it('retains the merge reservation while Commander waits for owned Herdr agents to exit', function () {
    $this->herdrWorkspace->agentsRemain = true;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('landing_workspace_shutdown_wait')
        ->and($this->landing->fresh()->current_block)->toBe('workspace_shutdown')
        ->and($this->landing->fresh()->output)->toHaveKeys([
            'merge_verification',
            'repository_reconciliation',
            'workspace_shutdown',
        ])
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->closeCalls)->toBe(0)
        ->and($this->herdrWorkspace->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
        ])
        ->and(MaintenanceRun::count())->toBe(1);

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::RETRY_SECONDS)
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->herdrWorkspace->sentKeys)->toHaveCount(3);
});

it('lands the exact candidate approved by the second pull request review', function () {
    promoteLandingToSecondPullRequestReview($this);

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->gateway->inspectCalls)->toBe(1)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('waits without reserving a merge while Orbit main has correctness failures', function () {
    $this->main->failures = ['apps/gateway' => ['tool' => 'phpstan', 'exit_code' => 1]];

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('landing_main_correctness_hold')
        ->and($this->gateway->reserveCalls)->toBe(0)
        ->and($this->gateway->mergeCalls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('waits when another delivery owns the shared merge reservation', function () {
    $this->gateway->owned = false;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe(AdvanceOrbitLanding::RETRY_SECONDS)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('landing_merge_reservation_wait')
        ->and($this->gateway->inspectCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(0);
});

it('releases the reservation for pending or conflicting mergeability', function (?bool $mergeable, ?int $delay, DeliveryStatus $status) {
    $this->gateway->mergeable = $mergeable;

    expect(app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toBe($delay)
        ->and($this->delivery->fresh()->status)->toBe($status)
        ->and($this->gateway->mergeCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(1);
})->with([
    'pending' => [null, AdvanceOrbitLanding::RETRY_SECONDS, DeliveryStatus::ReadyToMerge],
    'conflict' => [false, null, DeliveryStatus::Blocked],
]);

it('does not block a merge conflict until its reservation is released', function () {
    $this->gateway->mergeable = false;
    $this->gateway->releaseFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'release is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->gateway->releaseCalls)->toBe(2);
});

it('retains and resumes an unresolved merge without repeating pre-merge verification', function () {
    $this->gateway->mergeFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'merge outcome is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Merging)
        ->and($this->landing->fresh()->current_block)->toBe('merge')
        ->and($this->gateway->releaseCalls)->toBe(0);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->implementations->calls)->toBe(1)
        ->and($this->main->calls)->toBe(2)
        ->and($this->gateway->reserveCalls)->toBe(2)
        ->and($this->gateway->mergeCalls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1);
});

it('retries merge verification without releasing or reconciling the confirmed merge', function () {
    $this->merges->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'not yet published');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->landing->fresh()->current_block)->toBe('merge_verification')
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and($this->primaryCheckout->calls)->toBe(0)
        ->and(MaintenanceRun::count())->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->merges->calls)->toBe(2)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->gateway->reserveCalls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1)
        ->and(MaintenanceRun::count())->toBe(1);
});

it('rejects merge verification under a flow different from the approved implementation', function () {
    $this->merges->flow = 'proof';

    expect(fn () => app(AdvanceOrbitLanding::class)->handle(
        $this->delivery->id,
        $this->landing->id,
    ))->toThrow(OrbitLandingAdvancementFailed::class, 'no longer matches its landing ledger');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->landing->fresh()->current_block)->toBe('merge_verification')
        ->and($this->landing->fresh()->output)->not->toHaveKey('merge_verification')
        ->and($this->primaryCheckout->calls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and(MaintenanceRun::count())->toBe(0);
});

it('retains verified merge evidence and queued maintenance while primary checkout reconciliation retries', function () {
    $this->primaryCheckout->failures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitRepositoryFailed::class, 'not ready');
    $landing = $this->landing->fresh();
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($landing->current_block)->toBe('repository_reconciliation')
        ->and($landing->output['merge_verification']['merge_commit_sha'])->toBe(str_repeat('f', 40))
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(0)
        ->and(MaintenanceRun::count())->toBe(1);
    Queue::assertPushedTimes(RunMainCacheRefreshJob::class, 1);

    $run = MaintenanceRun::sole();
    $requests = new LandingCacheRefreshRequester;
    $requests->transactionLevel = DB::transactionLevel();
    app()->instance(OrbitMainCacheRefreshRequester::class, $requests);
    app(RunOrbitMainCacheRefresh::class)->handle($run->id);
    expect($run->fresh()->status)->toBe(MaintenanceRunStatus::Completed)
        ->and($requests->calls)->toBe(1);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->merges->calls)->toBe(1)
        ->and($this->primaryCheckout->calls)->toBe(2)
        ->and($this->gateway->reserveCalls)->toBe(2)
        ->and($this->gateway->releaseCalls)->toBe(1)
        ->and(MaintenanceRun::count())->toBe(1);
    Queue::assertPushedTimes(RunMainCacheRefreshJob::class, 1);
});

it('preserves a landed merge while reservation release is retried', function () {
    $this->gateway->releaseFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'release is unresolved');
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Landed)
        ->and($this->landing->fresh()->current_block)->toBe('reservation_release')
        ->and($this->delivery->fresh()->failure_details['code'])
        ->toBe('landing_reservation_release_required')
        ->and(MaintenanceRun::count())->toBe(1);
    Queue::assertPushedTimes(RunMainCacheRefreshJob::class, 1);

    expect($action->handle($this->delivery->id, $this->landing->id))->toBeNull()
        ->and($this->landing->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->gateway->mergeCalls)->toBe(1)
        ->and($this->gateway->releaseCalls)->toBe(2)
        ->and(MaintenanceRun::count())->toBe(1);
    Queue::assertPushedTimes(RunMainCacheRefreshJob::class, 1);
});

it('rejects tampered Linear closeout evidence before releasing the reservation', function () {
    $this->gateway->releaseFailures = 1;
    $action = app(AdvanceOrbitLanding::class);

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'release is unresolved');

    $landing = $this->landing->fresh();
    $output = $landing->output;
    $output['linear_closeout']['state']['type'] = 'started';
    $landing->output = $output;
    $landing->save();

    expect(fn () => $action->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'landing intent is inconsistent');
    expect($this->gateway->releaseCalls)->toBe(1)
        ->and($this->landing->fresh()->current_block)->toBe('reservation_release');
});

it('rejects issue and configuration drift before merge', function (string $drift) {
    if ($drift === 'issue') {
        $this->issues->state = 'In Progress';
    } else {
        $this->repository->afterReserve = function (): void {
            $config = $this->delivery->projectOrchestration->config;
            $config['concurrency'] = 2;
            DB::table('project_orchestrations')
                ->where('id', $this->delivery->project_orchestration_id)
                ->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        };
    }

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow($drift === 'issue' ? OrbitIssueContractChanged::class : OrbitLandingAdvancementFailed::class);
    expect($this->gateway->mergeCalls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['issue', 'config']);

it('rejects retained receipt drift before any external landing work', function (string $receipt) {
    $source = $receipt === 'review' ? $this->reviewReceipt : $this->implementationReceipt;
    $payload = $source->payload;
    $payload['handoff'] = 'Tampered after approval.';
    DB::table('receipts')->where('id', $source->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'landing intent is inconsistent');
    expect($this->repository->calls)->toBe(0)
        ->and($this->gateway->reserveCalls)->toBe(0)
        ->and($this->gateway->mergeCalls)->toBe(0);
})->with(['review', 'implementation']);

it('releases a merge reservation when approval evidence drifts', function () {
    $this->gateway->approvalMismatch = true;

    expect(fn () => app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'no longer matches');
    expect($this->gateway->mergeCalls)->toBe(0)
        ->and($this->gateway->releaseCalls)->toBe(1);
});

it('queues the exact landing phase and honors action wait delays', function () {
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    Queue::assertPushed(
        AdvanceLandingJob::class,
        fn (AdvanceLandingJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->landing->id,
    );

    $this->main->failures = ['packages/php-sdk' => ['tool' => 'pest', 'exit_code' => 1]];
    $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->withFakeQueueInteractions();
    $job->handle(app(AdvanceOrbitLanding::class));
    $job->assertReleased(AdvanceOrbitLanding::MAINTENANCE_RETRY_SECONDS);

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvanceLandingJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('queues incomplete landing recovery states', function (DeliveryStatus $status, string $block) {
    $this->delivery->forceFill(['status' => $status])->save();
    $this->landing->forceFill([
        'status' => PhaseRunStatus::Running,
        'current_block' => $block,
        'started_at' => now(),
    ])->save();

    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);

    Queue::assertPushed(
        AdvanceLandingJob::class,
        fn (AdvanceLandingJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->landing->id,
    );
})->with([
    'merge read-back' => [DeliveryStatus::Merging, 'merge'],
    'merge verification' => [DeliveryStatus::Landed, 'merge_verification'],
    'repository reconciliation' => [DeliveryStatus::Landed, 'repository_reconciliation'],
    'workspace shutdown' => [DeliveryStatus::Landed, 'workspace_shutdown'],
    'proof closeout' => [DeliveryStatus::Landed, 'proof_closeout'],
    'worktree cleanup' => [DeliveryStatus::Landed, 'worktree_cleanup'],
    'Linear closeout' => [DeliveryStatus::Landed, 'linear_closeout'],
    'reservation release' => [DeliveryStatus::Landed, 'reservation_release'],
]);

it('releases the landing job for the shared reservation wait', function () {
    $this->gateway->owned = false;
    $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->withFakeQueueInteractions();

    $job->handle(app(AdvanceOrbitLanding::class));

    $job->assertReleased(AdvanceOrbitLanding::RETRY_SECONDS);
});

it('releases a contended phase-scoped landing lock for retry', function () {
    $lock = Cache::lock(
        "delivery:landing-advance:{$this->delivery->id}:{$this->landing->id}",
        AdvanceLandingJob::LOCK_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $job = (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
            ->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitLanding::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('guards exhausted landing jobs and preserves recoverable external states', function (DeliveryStatus $status, PhaseRunStatus $phaseStatus, ?string $block, string $code) {
    $this->delivery->forceFill(['status' => $status])->save();
    $this->landing->forceFill([
        'status' => $phaseStatus,
        'current_block' => $block,
        'started_at' => $phaseStatus === PhaseRunStatus::Running ? now() : null,
    ])->save();

    (new AdvanceLandingJob($this->delivery->id, $this->landing->id))
        ->failed(new RuntimeException('Landing retries exhausted.'));

    $delivery = $this->delivery->fresh();
    expect($delivery->status)->toBe($status === DeliveryStatus::ReadyToMerge ? DeliveryStatus::Failed : $status)
        ->and($delivery->failure_details)->toBe([
            'code' => $code,
            'phase_run_id' => $this->landing->id,
            'message' => 'Landing retries exhausted.',
        ]);
})->with([
    'before merge' => [
        DeliveryStatus::ReadyToMerge,
        PhaseRunStatus::Pending,
        null,
        'landing_advancement_exhausted',
    ],
    'ambiguous merge' => [
        DeliveryStatus::Merging,
        PhaseRunStatus::Running,
        'merge',
        'landing_merge_reconciliation_required',
    ],
    'post-merge release' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'reservation_release',
        'landing_reservation_release_required',
    ],
    'post-merge verification' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'merge_verification',
        'landing_merge_verification_required',
    ],
    'post-merge repository reconciliation' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'repository_reconciliation',
        'landing_repository_reconciliation_required',
    ],
    'post-merge workspace shutdown' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'workspace_shutdown',
        'landing_workspace_shutdown_required',
    ],
    'post-merge proof closeout' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'proof_closeout',
        'landing_proof_closeout_required',
    ],
    'post-merge worktree cleanup' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'worktree_cleanup',
        'landing_worktree_cleanup_required',
    ],
    'post-merge Linear closeout' => [
        DeliveryStatus::Landed,
        PhaseRunStatus::Running,
        'linear_closeout',
        'landing_linear_closeout_required',
    ],
]);

it('completes the delivery-bound main cache refresh request without changing the landed result', function () {
    app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id);
    $run = MaintenanceRun::sole();
    $requests = new LandingCacheRefreshRequester;
    $requests->transactionLevel = DB::transactionLevel();
    $requests->disposition = 'coalesced';
    app()->instance(OrbitMainCacheRefreshRequester::class, $requests);
    $job = new RunMainCacheRefreshJob($run->id);

    $job->handle(app(RunOrbitMainCacheRefresh::class));

    $run->refresh();
    expect($run->status)->toBe(MaintenanceRunStatus::Completed)
        ->and($run->attempt)->toBe(1)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->result)->toBe([
            'repository' => $this->repositoryPath,
            'disposition' => 'coalesced',
            'message' => 'Main cache refresh queued (pid 123); log: /tmp/refresh.log',
        ])
        ->and($requests->calls)->toBe(1)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->delivery->fresh()->failure_details)->toBeNull();

    $job->handle(app(RunOrbitMainCacheRefresh::class));
    expect($requests->calls)->toBe(1)
        ->and($run->fresh()->attempt)->toBe(1);
});

it('retries an interrupted main cache refresh request from its durable ledger', function () {
    app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id);
    $run = MaintenanceRun::sole();
    $requests = new LandingCacheRefreshRequester;
    $requests->transactionLevel = DB::transactionLevel();
    $requests->failures = 1;
    app()->instance(OrbitMainCacheRefreshRequester::class, $requests);
    $action = app(RunOrbitMainCacheRefresh::class);

    expect(fn () => $action->handle($run->id))
        ->toThrow(OrbitRepositoryFailed::class, 'not accepted');
    expect($run->fresh()->status)->toBe(MaintenanceRunStatus::Running)
        ->and($run->fresh()->attempt)->toBe(1)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed);

    $action->handle($run->id);

    expect($run->fresh()->status)->toBe(MaintenanceRunStatus::Completed)
        ->and($run->fresh()->attempt)->toBe(2)
        ->and($requests->calls)->toBe(2)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed);
});

it('fails only an exhausted maintenance run and keeps the confirmed merge landed', function () {
    app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id);
    $run = MaintenanceRun::sole();
    $job = new RunMainCacheRefreshJob($run->id);

    $job->failed(new RuntimeException('Maintenance retries exhausted.'));

    expect($run->fresh()->status)->toBe(MaintenanceRunStatus::Failed)
        ->and($run->fresh()->failure_code)->toBe('orbit_main_cache_refresh_enqueue_failed')
        ->and($run->fresh()->failure_message)->toBe('Maintenance retries exhausted.')
        ->and($run->fresh()->finished_at)->not->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Completed)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($job->tries)->toBe(0)
        ->and($job->backoff)->toBe([1, 5, 15, 30])
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(RunMainCacheRefreshJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('releases a contended maintenance-run lock without making an external request', function () {
    app(AdvanceOrbitLanding::class)->handle($this->delivery->id, $this->landing->id);
    $run = MaintenanceRun::sole();
    $requests = new LandingCacheRefreshRequester;
    $requests->transactionLevel = DB::transactionLevel();
    app()->instance(OrbitMainCacheRefreshRequester::class, $requests);
    $lock = Cache::lock(
        "maintenance:orbit-main-cache-refresh:{$run->id}",
        RunMainCacheRefreshJob::LOCK_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $job = (new RunMainCacheRefreshJob($run->id))->withFakeQueueInteractions();
        $job->handle(app(RunOrbitMainCacheRefresh::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }

    expect($requests->calls)->toBe(0)
        ->and($run->fresh()->status)->toBe(MaintenanceRunStatus::Pending);
});
