<?php

use App\Delivery\Actions\AdoptOrbitResolution;
use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitImplementation;
use App\Delivery\Actions\AdvanceOrbitPullRequestReview;
use App\Delivery\Actions\AdvanceOrbitResolution;
use App\Delivery\Actions\BindOrbitPullRequestReviewPublicationRecovery;
use App\Delivery\Actions\CaptureOrbitPullRequestReviewReceipt;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitPullRequestResolution;
use App\Delivery\Actions\ReconcileOrbitPullRequestReviewWait;
use App\Delivery\Actions\RecoverExhaustedOrbitPlanningCorrection;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Contracts\OrbitPullRequestReviewPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitResolutionPublisher;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Data\PublishedOrbitPullRequestReview;
use App\Delivery\Data\PublishedOrbitResolution;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewAdvancementFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Exceptions\OrbitResolutionAdoptionFailed;
use App\Delivery\Exceptions\OrbitResolutionPublicationFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitImplementationReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestResolutionReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Jobs\AdoptOrbitResolution as AdoptResolutionJob;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitPullRequestReview as AdvancePullRequestReviewJob;
use App\Jobs\AdvanceOrbitResolution as AdvanceResolutionJob;
use App\Jobs\DispatchOrbitImplementation;
use App\Jobs\DispatchOrbitPullRequestResolution as DispatchResolutionJob;
use App\Jobs\ReconcileDeliveries;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class PullRequestReviewReceiptRepository implements OrbitImplementationRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?string $failure = null;

    public ?Closure $afterVerify = null;

    public string $expectedBody;

    public string $expectedReviewedCandidateSha;

    public string $expectedCandidateSha;

    public string $expectedArtifactSha;

    public function __construct()
    {
        $this->expectedReviewedCandidateSha = str_repeat('a', 40);
        $this->expectedCandidateSha = str_repeat('b', 40);
        $this->expectedArtifactSha = str_repeat('c', 40);
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
            ->and($startupWorktree->headSha)->toBe(str_repeat('a', 40))
            ->and($snapshot->issueKey)->toBe('ORB-234')
            ->and($reviewedCandidateSha)->toBe($this->expectedReviewedCandidateSha)
            ->and($candidateSha)->toBe($this->expectedCandidateSha)
            ->and($artifactSha)->toBe($this->expectedArtifactSha)
            ->and($pullRequestBody)->toBe($this->expectedBody);
        $this->calls++;

        if ($this->failure !== null) {
            throw new OrbitRepositoryFailed($this->failure);
        }

        if ($this->afterVerify instanceof Closure) {
            ($this->afterVerify)();
        }

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: str_repeat('7', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

final class PullRequestReviewReceiptPullRequests implements OrbitPullRequestInspector, OrbitPullRequestPublisher
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?bool $mergeable = true;

    public bool $mismatch = false;

    public string $submittedBody;

    public string $expectedCandidateSha;

    public function __construct()
    {
        $this->expectedCandidateSha = str_repeat('b', 40);
    }

    public function inspect(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234')
            ->and($candidateSha)->toBe($this->expectedCandidateSha)
            ->and($pullRequestBody)->toBe($this->submittedBody);
        $this->calls++;

        return new PublishedOrbitPullRequest(
            number: $this->mismatch ? 43 : 42,
            url: $this->mismatch
                ? 'https://github.com/nckrtl/orbit/pull/43'
                : 'https://github.com/nckrtl/orbit/pull/42',
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            mergeable: $this->mergeable,
        );
    }

    public function publish(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234')
            ->and($issueTitle)->not->toBeEmpty()
            ->and($candidateSha)->toBe($this->expectedCandidateSha)
            ->and($pullRequestBody)->toBe($this->submittedBody);
        $this->calls++;

        return new PublishedOrbitPullRequest(
            number: 42,
            url: 'https://github.com/nckrtl/orbit/pull/42',
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            mergeable: $this->mergeable,
        );
    }
}

final class PullRequestReviewAdvanceRepository implements OrbitRepository
{
    public int $transactionLevel = 0;

    public mixed $reservationHandle = null;

    public ?Closure $afterReserve = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234');
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve review advancement.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'pr-review-advance.lock');
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

final class PullRequestReviewAdvanceIssues implements OrbitActiveIssueProvider, OrbitIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?string $state = 'In Review';

    public mixed $assignee = null;

    public ?Closure $afterFetch = null;

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueId)->toBe('11111111-2222-4333-8444-555555555555')
            ->and($issueKey)->toBe('ORB-234');
        $this->calls++;

        $snapshot = new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            [
                'id' => $issueId,
                'identifier' => $issueKey,
                'title' => 'Review receipt test issue',
                'state' => [
                    'id' => $this->state === 'In Progress'
                        ? '33333333-4444-4555-8666-777777777777'
                        : '22222222-3333-4444-8555-666666666666',
                    'name' => $this->state,
                    'type' => 'started',
                ],
                'assignee' => $this->assignee,
                'delegate' => ['id' => config('commander.hermes.tom_linear_viewer_id')],
            ],
            str_repeat('d', 64),
        );

        if ($this->afterFetch instanceof Closure) {
            ($this->afterFetch)();
        }

        return $snapshot;
    }

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        return $this->fetchActive($issueId, $issueKey);
    }
}

final class PullRequestReviewAdvancePublisher implements OrbitPullRequestReviewPublisher
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public bool $mismatch = false;

    public ?Closure $afterPublish = null;

    public string $approvedBody;

    public string $submittedBody;

    public string $expectedCandidateSha;

    public function __construct()
    {
        $this->expectedCandidateSha = str_repeat('b', 40);
    }

    public function publishReview(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $submittedPullRequestBody,
        string $result,
        string $handoff,
        ?string $approvedPullRequestBody,
    ): PublishedOrbitPullRequestReview {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234')
            ->and($candidateSha)->toBe($this->expectedCandidateSha)
            ->and($submittedPullRequestBody)->toBe($this->submittedBody)
            ->and($handoff)->toBe('All acceptance items passed.')
            ->and($approvedPullRequestBody)->toBe($result === 'approved' ? $this->approvedBody : null);
        $this->calls++;

        if ($this->afterPublish instanceof Closure) {
            ($this->afterPublish)();
        }

        $body = $result === 'approved' ? 'Approved.' : $handoff;
        $pullRequestBody = $result === 'approved' ? $this->approvedBody : $this->submittedBody;

        return new PublishedOrbitPullRequestReview(
            id: 901,
            pullRequestNumber: $this->mismatch ? 43 : $number,
            reviewerLogin: 'tom-nckrtl[bot]',
            candidateSha: $candidateSha,
            state: $result === 'approved' ? 'APPROVED' : 'CHANGES_REQUESTED',
            reviewBodyHash: hash('sha256', $body),
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
        );
    }
}

final class PullRequestResolutionPublisher implements OrbitResolutionPublisher
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?bool $lastAdopted = null;

    public ?Closure $afterPublish = null;

    public function publish(
        OrbitIssueSnapshot $issue,
        int $dispatchId,
        string $handoff,
        bool $adopted,
        string $resumePhase,
    ): PublishedOrbitResolution {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issue->issueKey)->toBe('ORB-234')
            ->and($dispatchId)->toBeGreaterThan(0)
            ->and($handoff)->not->toBeEmpty()
            ->and($resumePhase)->toBe('implementing');
        $this->calls++;
        $this->lastAdopted = $adopted;

        if ($this->afterPublish instanceof Closure) {
            ($this->afterPublish)();
        }

        $marker = "ORBIT-LOOP-RESOLUTION:{$dispatchId}";
        $body = implode("\n\n", [
            $marker,
            $handoff,
            'Commander routing: Needs an explicit decision or recovery action.',
        ]);

        return new PublishedOrbitResolution(
            commentId: '22222222-3333-4444-8555-666666666666',
            marker: $marker,
            bodyHash: hash('sha256', $body),
        );
    }
}

final class PullRequestResolutionTransitioner implements OrbitIssueTransitioner
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $mutations = 0;

    public bool $loseNextResponse = false;

    public ?Closure $afterTransition = null;

    public function __construct(private readonly PullRequestReviewAdvanceIssues $issues) {}

    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($current->issueKey)->toBe('ORB-234')
            ->and($current->payload['state']['name'])->toBeIn(['In Review', 'In Progress'])
            ->and($expectedContractHash)->toBe(str_repeat('d', 64));
        $this->calls++;

        if ($this->issues->state !== 'In Progress') {
            $this->issues->state = 'In Progress';
            $this->mutations++;
        }

        $resumed = $this->issues->fetchActive($current->issueId, $current->issueKey);

        if ($this->afterTransition instanceof Closure) {
            ($this->afterTransition)();
        }

        if ($this->loseNextResponse) {
            $this->loseNextResponse = false;

            throw new OrbitIssueTransitionFailed(
                'The Linear response was lost.',
                ambiguous: true,
            );
        }

        return $resumed;
    }
}

final class PullRequestResolutionBuilderHerdr implements HerdrRuntime
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $prompts = [];

    public function __construct(private readonly string $worktree) {}

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        throw new LogicException('The retained Builder worktree must not be reopened.');
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        throw new LogicException('The retained Builder pane must not be split.');
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        throw new LogicException('The retained Builder agent must not be restarted.');
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->calls[] = 'prompt';
        $this->prompts[] = $prompt;

        return $this->identifiers(42, 'working');
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'get';

        return $this->identifiers(41, 'idle');
    }

    private function identifiers(int $sequence, string $status): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            'workspace',
            'tab',
            'builder-pane',
            'builder-terminal',
            'builder-agent',
            'orb-234-loop-builder',
            $sequence,
            $this->worktree,
            $status,
        );
    }
}

final class PullRequestResolutionHerdr implements HerdrRuntime
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $prompts = [];

    public ?HerdrAgentLaunch $launch = null;

    public string $agentName = 'orb-234-loop-resolution-1';

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        $this->calls[] = 'open';

        return new OpenedHerdrWorktree('resolution-workspace', 'resolution-tab', 'worktree-pane', 'worktree-terminal', true);
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        $this->calls[] = 'split';

        return $this->identifiers(null, 1);
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        $this->calls[] = 'start';
        $this->launch = $launch;

        return $this->identifiers('resolver-agent', 2);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->calls[] = 'prompt';
        $this->prompts[] = $prompt;

        return $this->identifiers('resolver-agent', 3);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'get';

        return $this->identifiers('resolver-agent', 2);
    }

    private function identifiers(?string $agentId, int $sequence): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            'resolution-workspace',
            'resolution-tab',
            'resolver-pane',
            'resolver-terminal',
            $agentId,
            $this->agentName,
            $sequence,
            null,
            null,
        );
    }
}

beforeEach(function () {
    $this->originalDirectory = getcwd();
    $this->base = storage_path('framework/testing/orbit-pr-review-receipt-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->reviewedSha = str_repeat('a', 40);
    $this->candidateSha = str_repeat('b', 40);
    $this->artifactSha = str_repeat('c', 40);
    $this->gatePath = $this->repositoryPath.'/.git/orbit-checks/'.$this->candidateSha.'/review/result.json';
    $this->handoffPath = $this->worktreePath.'/.loop/runtime/pr-review-handoff.md';
    $this->bodyPath = $this->worktreePath.'/.loop/runtime/pull-request-body.md';
    $this->submittedBody = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Flow: discovery',
        'Builder gate: passed ('.$this->gatePath.')',
        'Submitted implementation body.',
    ]);
    $this->approvedBody = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Flow: discovery',
        'Builder gate: passed ('.$this->gatePath.')',
        'Reviewer-authored final body.',
    ]);

    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory(dirname($this->gatePath), 0755, true);
    File::makeDirectory(dirname($this->handoffPath), 0755, true);
    File::put($this->gatePath, "{}\n");
    File::put($this->handoffPath, "All acceptance items passed.\n");
    File::put($this->bodyPath, $this->approvedBody."\n");

    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
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
    $planner = prReviewReceiptDispatch($planning, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'planner');
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/planning.md', 'handoff' => 'Planning ready.',
        'artifact_sha' => str_repeat('e', 40), 'plan_sha256' => str_repeat('1', 64),
    ];
    $planningReceipt = prReviewStoredReceipt($planning, 'orbit_planning', $planningPayload);
    prReviewReceiptComplete($planning, $planningReceipt, [
        'receipt_id' => $planningReceipt->id,
        'result' => 'ready',
    ]);

    $planReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => ['planning_receipt_id' => $planningReceipt->id, 'planning_receipt' => $planningPayload],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $planReviewer = prReviewReceiptDispatch($planReview, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'plan-reviewer');
    $planReviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planReviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktreePath,
        'candidate_sha' => $this->reviewedSha,
        'handoff_path' => '.loop/runtime/plan-review.md', 'handoff' => 'Plan approved.',
        'artifact_sha' => str_repeat('f', 40), 'plan_sha256' => str_repeat('2', 64),
    ];
    $planReviewReceipt = prReviewStoredReceipt($planReview, 'orbit_plan_review', $planReviewPayload);
    prReviewReceiptComplete($planReview, $planReviewReceipt, [
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
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $builder = prReviewReceiptDispatch($implementation, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'builder');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            1,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_hash' => str_repeat('4', 64),
        'herdr_pane_id' => 'builder-pane',
        'herdr_agent_id' => 'builder-agent',
        'dispatched_at' => now(),
    ])->save();
    $this->implementationPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktreePath,
        'reviewed_candidate_sha' => $this->reviewedSha,
        'candidate_sha' => $this->candidateSha,
        'handoff_path' => '.loop/runtime/implementation.md', 'handoff' => 'Implementation ready.',
        'artifact_sha' => $this->artifactSha,
        'gate_receipt_path' => $this->gatePath,
        'pull_request_body_path' => '.loop/runtime/submitted-body.md',
        'pull_request_body' => $this->submittedBody,
        'pull_request_body_sha256' => hash('sha256', $this->submittedBody),
        'flow' => 'discovery',
    ];
    $this->implementationReceipt = prReviewStoredReceipt(
        $implementation,
        'orbit_implementation',
        $this->implementationPayload,
    );
    $implementation->forceFill(['output' => [
        'receipt_id' => $this->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]])->save();

    $this->phaseRun = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $this->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'started_at' => now(),
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->phaseRun->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            1,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Waiting,
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'reviewer-pane',
        'herdr_terminal_id' => 'reviewer-terminal',
        'herdr_agent_id' => 'reviewer-agent',
        'dispatched_at' => now(),
    ]);
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $this->worktreePath,
        $this->delivery->id,
        $this->phaseRun->id,
        $this->dispatch->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $this->phaseRun->id,
            $this->dispatch->id,
        ),
        $this->implementationPayload,
        $this->phaseRun->input['pull_request'],
    );
    $this->dispatch->forceFill(['prompt_hash' => hash('sha256', $prompt)])->save();
    $this->delivery->forceFill([
        'candidate_sha' => $this->candidateSha,
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();

    $this->arguments = [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => (string) $this->dispatch->id,
        '--result' => 'approved',
        '--handoff' => '.loop/runtime/pr-review-handoff.md',
        '--artifact' => $this->artifactSha,
        '--body' => '.loop/runtime/pull-request-body.md',
    ];
    $this->repository = new PullRequestReviewReceiptRepository;
    $this->repository->expectedBody = $this->approvedBody;
    $this->pullRequests = new PullRequestReviewReceiptPullRequests;
    $this->pullRequests->submittedBody = $this->submittedBody;
    $this->advanceRepository = new PullRequestReviewAdvanceRepository;
    $this->advanceIssues = new PullRequestReviewAdvanceIssues;
    $this->reviewPublisher = new PullRequestReviewAdvancePublisher;
    $this->reviewPublisher->approvedBody = $this->approvedBody;
    $this->reviewPublisher->submittedBody = $this->submittedBody;
    $this->resolutionPublisher = new PullRequestResolutionPublisher;
    $this->resolutionTransitioner = new PullRequestResolutionTransitioner($this->advanceIssues);
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->pullRequests->transactionLevel = $transactionLevel;
    $this->advanceRepository->transactionLevel = $transactionLevel;
    $this->advanceIssues->transactionLevel = $transactionLevel;
    $this->reviewPublisher->transactionLevel = $transactionLevel;
    $this->resolutionPublisher->transactionLevel = $transactionLevel;
    $this->resolutionTransitioner->transactionLevel = $transactionLevel;
    app()->instance(OrbitImplementationRepository::class, $this->repository);
    app()->instance(OrbitPullRequestInspector::class, $this->pullRequests);
    app()->instance(OrbitPullRequestPublisher::class, $this->pullRequests);
    app()->instance(OrbitRepository::class, $this->advanceRepository);
    app()->instance(OrbitActiveIssueProvider::class, $this->advanceIssues);
    app()->instance(OrbitIssueProvider::class, $this->advanceIssues);
    app()->instance(OrbitPullRequestReviewPublisher::class, $this->reviewPublisher);
    app()->instance(OrbitResolutionPublisher::class, $this->resolutionPublisher);
    app()->instance(OrbitIssueTransitioner::class, $this->resolutionTransitioner);

    chdir($this->worktreePath);
    Queue::fake();
    Process::fake(fn ($process) => $process->command === ['git', 'rev-parse', 'HEAD']
        ? Process::result(output: $this->candidateSha."\n")
        : throw new RuntimeException('Unexpected pull request review receipt command.'))
        ->preventStrayProcesses();
});

it('publishes a plan-changing proposal as an explicit decision stop', function () {
    [$resolution, $resolver] = activatePullRequestResolution($this);
    $handoff = 'Restart planning with an expanded runtime boundary.';
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'planning',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => ['Clarify loaded runtime state.'],
        'plan_changes' => ['Add a loaded-state attestation.'],
    ];
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', $handoff."\n");
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );
    $this->artisan('delivery:submit-orbit-resolution-receipt', [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ])->assertSuccessful();
    $resolver->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();

    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_decision_required')
        ->and($this->delivery->fresh()->failure_details['requirements'])->toBe([
            'issue_changes: Clarify loaded runtime state.',
            'plan_changes: Add a loaded-state attestation.',
        ])
        ->and($resolution->fresh()->output['automatic_adoption_eligible'])->toBeFalse()
        ->and($this->resolutionPublisher->lastAdopted)->toBeFalse();
});

afterEach(function () {
    Carbon::setTestNow();

    if (is_string($this->originalDirectory)) {
        chdir($this->originalDirectory);
    }

    File::deleteDirectory($this->base);
});

function prReviewReceiptDispatch(PhaseRun $phase, string $role, string $name): AgentDispatch
{
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => "pr-review-receipt-{$name}",
        'herdr_agent_name' => "orb-234-loop-{$name}",
        'prompt_name' => "orbit_{$name}",
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('3', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @param array<string, mixed> $payload */
function prReviewStoredReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
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
function prReviewReceiptComplete(PhaseRun $phase, Receipt $receipt, array $output): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => $output,
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
}

function promotePullRequestReviewReceiptToSecondRound(object $test, ?string $candidateSha = null): void
{
    $reviewedCandidateSha = $test->candidateSha;
    $candidateSha ??= $reviewedCandidateSha;
    $submittedBody = str_replace($reviewedCandidateSha, $candidateSha, $test->submittedBody);
    $approvedBody = str_replace($reviewedCandidateSha, $candidateSha, $test->approvedBody);

    $arguments = [...$test->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $test->repository->expectedBody = $test->submittedBody;
    $test->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceOrbitPullRequestReview::class)->handle($test->delivery->id, $test->phaseRun->id);

    $test->submittedBody = $submittedBody;
    $test->approvedBody = $approvedBody;
    $test->candidateSha = $candidateSha;
    $test->pullRequests->expectedCandidateSha = $candidateSha;
    $test->pullRequests->submittedBody = $submittedBody;
    $test->reviewPublisher->approvedBody = $approvedBody;
    $test->reviewPublisher->submittedBody = $submittedBody;
    $test->reviewPublisher->expectedCandidateSha = $candidateSha;
    $test->gatePath = str_replace($reviewedCandidateSha, $candidateSha, $test->gatePath);
    File::makeDirectory(dirname($test->gatePath), 0755, true, true);
    File::put($test->gatePath, "{}\n");
    File::put($test->bodyPath, $approvedBody."\n");

    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $builder = $correction->agentDispatches()->sole();
    $builder->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
        'prompt_hash' => str_repeat('5', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ])->save();
    $correctionPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2, 'result' => 'ready', 'worktree' => $test->worktreePath,
        'reviewed_candidate_sha' => $reviewedCandidateSha,
        'candidate_sha' => $candidateSha,
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => 'The review finding was corrected.',
        'artifact_sha' => $test->artifactSha,
        'gate_receipt_path' => $test->gatePath,
        'pull_request_body_path' => '.loop/runtime/submitted-body.md',
        'pull_request_body' => $test->submittedBody,
        'pull_request_body_sha256' => hash('sha256', $test->submittedBody),
        'flow' => 'discovery',
    ];
    $correctionReceipt = prReviewStoredReceipt($correction, 'orbit_implementation', $correctionPayload);
    $correction->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => [
            'receipt_id' => $correctionReceipt->id,
            'result' => 'ready',
            'pull_request_number' => 42,
            'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
            'mergeable' => true,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
    $secondReview = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'implementation_receipt_id' => $correctionReceipt->id,
            'implementation_receipt' => $correctionPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'started_at' => now(),
    ]);
    $reviewer = AgentDispatch::query()->create([
        'phase_run_id' => $secondReview->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            2,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-2',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Waiting,
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'reviewer-pane-2',
        'herdr_terminal_id' => 'reviewer-terminal-2',
        'herdr_agent_id' => 'reviewer-agent-2',
        'dispatched_at' => now(),
    ]);
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
    $test->phaseRun = $secondReview;
    $test->dispatch = $reviewer;
    $test->delivery->refresh()->forceFill([
        'candidate_sha' => $candidateSha,
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $test->arguments['phase-run'] = (string) $secondReview->id;
    $test->arguments['dispatch'] = (string) $reviewer->id;
    $test->repository->expectedReviewedCandidateSha = $reviewedCandidateSha;
    $test->repository->expectedCandidateSha = $candidateSha;
    $test->repository->expectedBody = $test->approvedBody;
    $test->repository->calls = 0;
    $test->pullRequests->calls = 0;
    $test->reviewPublisher->calls = 0;
}

function promotePullRequestReviewReceiptToThirdRound(object $test): Receipt
{
    $secondCandidateSha = str_repeat('d', 40);
    promotePullRequestReviewReceiptToSecondRound($test, $secondCandidateSha);
    $sourceReceipt = Receipt::query()->findOrFail($test->phaseRun->input['implementation_receipt_id']);
    $test->phaseRun->forceFill([
        'status' => PhaseRunStatus::Failed,
        'failure_code' => 'pr_review_mergeability_changed',
        'failure_message' => 'The pull request became unmergeable before review.',
        'finished_at' => now(),
    ])->save();
    $test->dispatch->forceFill([
        'herdr_session' => null,
        'herdr_workspace_id' => null,
        'herdr_tab_id' => null,
        'herdr_pane_id' => null,
        'herdr_terminal_id' => null,
        'herdr_agent_id' => null,
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The pull request became unmergeable before review.',
        'dispatched_at' => null,
        'settled_at' => null,
    ])->save();
    $implementation = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 3,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'implementation_receipt_id' => $sourceReceipt->id,
            'implementation_receipt' => $sourceReceipt->payload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => false,
            ],
        ],
        'started_at' => now(),
    ]);
    $builder = AgentDispatch::query()->create([
        'phase_run_id' => $implementation->id,
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            3,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation_correction',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('6', 64),
        'status' => AgentDispatchStatus::Waiting,
        'dispatched_at' => now(),
    ]);
    $test->delivery->refresh()->forceFill([
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
        'failure_details' => null,
    ])->save();
    $thirdCandidateSha = str_repeat('e', 40);
    $test->submittedBody = str_replace($secondCandidateSha, $thirdCandidateSha, $test->submittedBody);
    $test->approvedBody = str_replace($secondCandidateSha, $thirdCandidateSha, $test->approvedBody);
    $test->candidateSha = $thirdCandidateSha;
    $test->pullRequests->expectedCandidateSha = $thirdCandidateSha;
    $test->pullRequests->submittedBody = $test->submittedBody;
    $test->reviewPublisher->approvedBody = $test->approvedBody;
    $test->reviewPublisher->submittedBody = $test->submittedBody;
    $test->reviewPublisher->expectedCandidateSha = $thirdCandidateSha;
    $test->gatePath = str_replace($secondCandidateSha, $thirdCandidateSha, $test->gatePath);
    File::makeDirectory(dirname($test->gatePath), 0755, true, true);
    File::put($test->gatePath, "{}\n");
    File::put(
        $test->worktreePath.'/.loop/runtime/implementation-correction.md',
        "The second mergeability correction was implemented.\n",
    );
    File::put($test->bodyPath, $test->submittedBody."\n");
    $test->repository->expectedReviewedCandidateSha = $secondCandidateSha;
    $test->repository->expectedCandidateSha = $thirdCandidateSha;
    $test->repository->expectedBody = $test->submittedBody;
    $test->artisan('delivery:submit-orbit-implementation-receipt', [
        'phase-run' => (string) $implementation->id,
        'dispatch' => (string) $builder->id,
        '--result' => 'ready',
        '--handoff' => '.loop/runtime/implementation-correction.md',
        '--artifact' => $test->artifactSha,
        '--gate' => $test->gatePath,
        '--body' => '.loop/runtime/pull-request-body.md',
    ])->assertSuccessful();
    $implementationReceipt = $implementation->receipts()->where('kind', 'orbit_implementation')->sole();
    $builder->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $test->advanceIssues->state = 'In Progress';
    app(AdvanceOrbitImplementation::class)->handle($test->delivery->id, $implementation->id);
    $test->advanceIssues->state = 'In Review';

    $review = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 3)
        ->sole();
    $reviewer = $review->agentDispatches()->sole();
    $review->forceFill([
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ])->save();
    $reviewer->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'reviewer-pane-3',
        'herdr_terminal_id' => 'reviewer-terminal-3',
        'herdr_agent_id' => 'reviewer-agent-3',
        'status' => AgentDispatchStatus::Waiting,
        'dispatched_at' => now(),
    ])->save();
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
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
        $implementationReceipt->payload,
        $review->input['pull_request'],
    );
    $reviewer->forceFill(['prompt_hash' => hash('sha256', $prompt)])->save();
    $test->delivery->refresh()->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $test->phaseRun = $review;
    $test->dispatch = $reviewer;
    $test->arguments['phase-run'] = (string) $review->id;
    $test->arguments['dispatch'] = (string) $reviewer->id;
    $test->repository->expectedReviewedCandidateSha = $secondCandidateSha;
    $test->repository->expectedCandidateSha = $thirdCandidateSha;
    $test->repository->expectedBody = $test->approvedBody;
    $test->repository->calls = 0;
    $test->pullRequests->calls = 0;
    $test->reviewPublisher->calls = 0;
    File::put($test->bodyPath, $test->approvedBody."\n");

    return $implementationReceipt;
}

/** @return array{PhaseRun, AgentDispatch} */
function promotePullRequestReviewReceiptToResolution(object $test): array
{
    promotePullRequestReviewReceiptToSecondRound($test);
    $arguments = [...$test->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $test->repository->expectedBody = $test->submittedBody;
    $test->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceOrbitPullRequestReview::class)->handle($test->delivery->id, $test->phaseRun->id);
    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->sole();

    return [$resolution, $resolution->agentDispatches()->sole()];
}

/** @return array{PhaseRun, AgentDispatch} */
function activatePullRequestResolution(object $test): array
{
    [$resolution, $resolver] = promotePullRequestReviewReceiptToResolution($test);
    $resolution->forceFill([
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ])->save();
    $resolver->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'resolution-workspace',
        'herdr_tab_id' => 'resolution-tab',
        'herdr_pane_id' => 'resolver-pane',
        'herdr_terminal_id' => 'resolver-terminal',
        'herdr_agent_id' => 'resolver-agent',
        'state_change_seq' => 2,
        'dispatched_at' => now(),
        'status' => AgentDispatchStatus::Waiting,
    ])->save();
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestResolutionPrompt(
        'ORB-234',
        $test->repositoryPath,
        $test->worktreePath,
        $test->delivery->id,
        $resolution->id,
        $resolver->id,
        sprintf(
            '%s %s delivery:submit-orbit-resolution-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $resolution->id,
            $resolver->id,
        ),
        $resolution->input,
    );
    $resolver->forceFill(['prompt_hash' => hash('sha256', $prompt)])->save();
    $test->delivery->refresh()->forceFill([
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();

    return [$resolution->fresh(), $resolver->fresh()];
}

/** @return array{PhaseRun, AgentDispatch} */
function activateSecondPullRequestResolution(object $test): array
{
    promotePullRequestReviewReceiptToThirdRound($test);
    $priorResolution = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'input' => ['recovered' => true],
        'failure_code' => 'resolution_dispatch_exhausted',
        'failure_message' => 'The earlier resolver attempt was recovered.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $priorResolution->id,
        'agent_role' => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::RESOLUTION_PHASE,
            1,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-resolution-1',
        'prompt_name' => 'orbit_resolution',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('7', 64),
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'resolution_dispatch_exhausted',
        'error_message' => 'The earlier resolver attempt was recovered.',
    ]);
    $arguments = [...$test->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $test->repository->expectedBody = $test->submittedBody;
    $test->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceOrbitPullRequestReview::class)->handle($test->delivery->id, $test->phaseRun->id);

    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $resolver = $resolution->agentDispatches()->sole();
    config()->set('herdr.session', 'orbit');
    $herdr = new PullRequestResolutionHerdr;
    $herdr->agentName = 'orb-234-loop-resolution-2';
    app()->instance(HerdrRuntime::class, $herdr);
    $test->repository->expectedBody = $test->submittedBody;
    app(DispatchOrbitPullRequestResolution::class)->handle($test->delivery->id, $resolution->id);

    return [$resolution->fresh(), $resolver->fresh()];
}

/** @return array{PhaseRun, AgentDispatch, Receipt} */
function prepareResolutionProposalForPublication(object $test): array
{
    [$resolution, $resolver] = activatePullRequestResolution($test);
    $handoff = 'Use the loaded worker configuration as the health authority.';
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];
    File::put($test->worktreePath.'/.loop/runtime/resolution-handoff.md', $handoff."\n");
    File::put(
        $test->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );
    $test->artisan('delivery:submit-orbit-resolution-receipt', [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ])->assertSuccessful();
    $resolver->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceDeliveryAction::class)->handle($test->delivery->id);

    return [
        $resolution->fresh(),
        $resolver->fresh(),
        $resolution->receipts()->where('kind', 'orbit_resolution')->sole(),
    ];
}

/** @return array{PhaseRun, AgentDispatch, Receipt} */
function preparePublishedResolutionAdoption(object $test): array
{
    [$resolution, $resolver, $receipt] = prepareResolutionProposalForPublication($test);
    app(AdvanceOrbitResolution::class)->handle($test->delivery->id, $resolution->id);

    return [$resolution->fresh(), $resolver->fresh(), $receipt->fresh()];
}

function normalizeResolutionCorrectionBuilder(object $test): void
{
    $planning = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
        ->where('attempt', 1)
        ->sole();
    $implementation = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 1)
        ->sole();
    $identity = [
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'dispatched_at' => now(),
    ];

    $planning->agentDispatches()->sole()->forceFill($identity)->save();
    $implementation->agentDispatches()->sole()->forceFill($identity)->save();
}

function exhaustResolutionAdoptionBudget(object $test, PhaseRun $resolution, AgentDispatch $resolver, Receipt $receipt): void
{
    $resolution->forceFill(['attempt' => 3])->save();
    $payload = $receipt->payload;
    $payload['attempt'] = 3;
    DB::table('receipts')->where('id', $receipt->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestResolutionPrompt(
        'ORB-234',
        $test->repositoryPath,
        $test->worktreePath,
        $test->delivery->id,
        $resolution->id,
        $resolver->id,
        sprintf(
            '%s %s delivery:submit-orbit-resolution-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $resolution->id,
            $resolver->id,
        ),
        $resolution->input,
    );
    $resolver->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::RESOLUTION_PHASE,
            3,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-resolution-3',
        'prompt_hash' => hash('sha256', $prompt),
    ])->save();

    foreach ([1, 2] as $attempt) {
        PhaseRun::query()->create([
            'delivery_id' => $test->delivery->id,
            'phase_name' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
            'attempt' => $attempt,
            'status' => PhaseRunStatus::Completed,
            'output' => ['adopted' => true],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}

function blockPullRequestReviewReceiptAsMissing(object $test): void
{
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'state_change_seq' => 37,
        'settled_at' => now(),
    ])->save();
    Carbon::setTestNow(now()->addMinutes(5));

    expect(app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $test->delivery->id,
        $test->phaseRun->id,
        $test->dispatch->id,
        null,
    ))->toBeTrue()
        ->and($test->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($test->phaseRun->fresh()->failure_code)->toBe('pr_review_receipt_missing');
}

it('captures one immutable approved pull request review receipt', function () {
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();

    $receipt = Receipt::query()->where('kind', 'orbit_pr_review')->sole();
    expect($receipt->schema_version)->toBe(1)
        ->and($receipt->candidate_sha)->toBe($this->candidateSha)
        ->and($receipt->validation_status)->toBe(ReceiptValidationStatus::Valid)
        ->and($receipt->payload)->toBe([
            'kind' => 'orbit_pr_review',
            'schema_version' => 1,
            'delivery_id' => $this->delivery->id,
            'dispatch_id' => $this->dispatch->id,
            'issue_key' => 'ORB-234',
            'phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            'attempt' => 1,
            'result' => 'approved',
            'worktree' => $this->worktreePath,
            'candidate_sha' => $this->candidateSha,
            'handoff_path' => '.loop/runtime/pr-review-handoff.md',
            'handoff' => 'All acceptance items passed.',
            'artifact_sha' => $this->artifactSha,
            'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
            'pull_request_body' => $this->approvedBody,
            'pull_request_body_sha256' => hash('sha256', $this->approvedBody),
        ])
        ->and(app(OrbitPullRequestReviewReceiptValidator::class)->matches(
            $this->delivery->fresh(),
            $this->phaseRun->fresh(),
            $this->dispatch->fresh(),
            $receipt,
        ))->toBeTrue()
        ->and($this->repository->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('captures and validates the second independent pull request review receipt', function () {
    promotePullRequestReviewReceiptToSecondRound($this);

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();

    $receipt = Receipt::query()
        ->where('phase_run_id', $this->phaseRun->id)
        ->where('kind', 'orbit_pr_review')
        ->sole();

    expect($receipt->payload['attempt'])->toBe(2)
        ->and($receipt->payload['result'])->toBe('approved')
        ->and(app(OrbitPullRequestReviewReceiptValidator::class)->matches(
            $this->delivery->fresh(),
            $this->phaseRun->fresh(),
            $this->dispatch->fresh(),
            $receipt,
        ))->toBeTrue()
        ->and($this->repository->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1);
});

it('captures attempt three from exact attempt-two provenance and opens review three', function () {
    $receipt = promotePullRequestReviewReceiptToThirdRound($this);
    $implementation = $receipt->phaseRun;
    $sourceReceipt = Receipt::query()->findOrFail($implementation->input['implementation_receipt_id']);
    $review = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 3)
        ->sole();
    $dispatch = $implementation->agentDispatches()->sole();

    expect($implementation->attempt)->toBe(3)
        ->and($implementation->status)->toBe(PhaseRunStatus::Completed)
        ->and($sourceReceipt->phaseRun->attempt)->toBe(2)
        ->and($receipt->payload['attempt'])->toBe(3)
        ->and($receipt->payload['reviewed_candidate_sha'])->toBe($sourceReceipt->candidate_sha)
        ->and($dispatch->idempotency_key)->toBe(IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            3,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value)
        ->and($review->input['implementation_receipt_id'])->toBe($receipt->id)
        ->and($review->agentDispatches()->sole()->idempotency_key)->toBe(IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            3,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value)
        ->and(AgentDispatch::query()->where('idempotency_key', $dispatch->idempotency_key)->count())->toBe(1);
});

it('rejects attempt three when nested implementation provenance is corrupted', function (string $drift) {
    $receipt = promotePullRequestReviewReceiptToThirdRound($this);
    $implementation = $receipt->phaseRun;
    $dispatch = $implementation->agentDispatches()->sole();
    $deliveryAtCapture = $this->delivery->fresh();
    $deliveryAtCapture->candidate_sha = str_repeat('d', 40);
    $validator = app(OrbitImplementationReceiptValidator::class);

    expect($validator->matches(
        $deliveryAtCapture,
        $implementation,
        $dispatch,
        $receipt,
    ))->toBeTrue();

    $sourceReceipt = Receipt::query()->findOrFail($implementation->input['implementation_receipt_id']);
    $sourcePhase = $sourceReceipt->phaseRun;
    $sourceInput = $sourcePhase->input;

    if ($drift === 'candidate') {
        $sourceInput['implementation_receipt']['candidate_sha'] = str_repeat('c', 40);
        $sourcePhase->forceFill(['input' => $sourceInput])->save();
    } else {
        DB::table('receipts')
            ->where('id', $sourceInput['implementation_receipt_id'])
            ->update(['payload_hash' => str_repeat('0', 64)]);
    }

    expect($validator->matches(
        $deliveryAtCapture,
        $implementation->fresh(),
        $dispatch->fresh(),
        $receipt->fresh(),
    ))->toBeFalse();
})->with(['candidate', 'hash']);

it('routes third-round approval to landing and replays without new rows', function () {
    $implementationReceipt = promotePullRequestReviewReceiptToThirdRound($this);
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();
    $reviewReceipt = $this->phaseRun->receipts()->where('kind', 'orbit_pr_review')->sole();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $this->repository->expectedBody = $this->submittedBody;

    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $landing = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)->sole();
    expect($this->phaseRun->fresh()->output)->toBe([
        'receipt_id' => $reviewReceipt->id,
        'result' => 'approved',
        'published_review' => $this->phaseRun->fresh()->output['published_review'],
    ])
        ->and($landing->input['implementation_receipt_id'])->toBe($implementationReceipt->id)
        ->and($landing->input['implementation_receipt']['attempt'])->toBe(3)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and(PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)->count())->toBe(1);
});

it('routes review three to resolution two with exact identity and publication replay', function () {
    promotePullRequestReviewReceiptToThirdRound($this);
    $priorResolution = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'input' => ['recovered' => true],
        'failure_code' => 'resolution_dispatch_exhausted',
        'failure_message' => 'The earlier resolver attempt was recovered.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $priorResolution->id,
        'agent_role' => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::RESOLUTION_PHASE,
            1,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-resolution-1',
        'prompt_name' => 'orbit_resolution',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('7', 64),
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'resolution_dispatch_exhausted',
        'error_message' => 'The earlier resolver attempt was recovered.',
    ]);
    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $resolver = $resolution->agentDispatches()->sole();
    expect($resolution->input['pr_review_receipt']['attempt'])->toBe(3)
        ->and($resolver->idempotency_key)->toBe(IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::RESOLUTION_PHASE,
            2,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        )->value)
        ->and($resolver->herdr_agent_name)->toBe('orb-234-loop-resolution-2')
        ->and(PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->count())->toBe(2);

    config()->set('herdr.session', 'orbit');
    $herdr = new PullRequestResolutionHerdr;
    $herdr->agentName = 'orb-234-loop-resolution-2';
    app()->instance(HerdrRuntime::class, $herdr);
    $this->repository->expectedBody = $this->submittedBody;
    app(DispatchOrbitPullRequestResolution::class)->handle($this->delivery->id, $resolution->id);
    $resolution->refresh();
    $resolver->refresh();
    expect($resolution->status)->toBe(PhaseRunStatus::Running)
        ->and($resolver->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($resolver->herdr_agent_name)->toBe('orb-234-loop-resolution-2')
        ->and($herdr->calls)->toBe(['open', 'split', 'start', 'prompt']);
    $handoff = 'Use the exact second-resolution proposal.';
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', $handoff."\n");
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );
    $receiptArguments = [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ];
    $this->artisan('delivery:submit-orbit-resolution-receipt', $receiptArguments)->assertSuccessful();
    $this->artisan('delivery:submit-orbit-resolution-receipt', $receiptArguments)
        ->expectsOutputToContain('was already captured')
        ->assertSuccessful();
    $receipt = $resolution->receipts()->where('kind', 'orbit_resolution')->sole();
    $resolver->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);
    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);

    expect($receipt->payload['attempt'])->toBe(2)
        ->and($resolution->fresh()->output['receipt_id'])->toBe($receipt->id)
        ->and($resolution->fresh()->output['publication']['marker'])->toBe(
            "ORBIT-LOOP-RESOLUTION:{$resolver->id}",
        )
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_adoption_ready')
        ->and($this->resolutionPublisher->calls)->toBe(1)
        ->and(Receipt::query()
            ->where('phase_run_id', $resolution->id)
            ->where('kind', 'orbit_resolution')
            ->count())->toBe(1);
});

it('rejects attempt-one identity or receipt evidence from resolution attempt two', function (string $drift) {
    [$resolution, $resolver] = activateSecondPullRequestResolution($this);
    $validator = app(OrbitPullRequestResolutionReceiptValidator::class);

    expect($resolution->attempt)->toBe(2)
        ->and($validator->matchesInput($this->delivery->fresh(), $resolution, $resolver))->toBeTrue();

    $handoff = 'Use only the exact second resolver attempt.';
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', $handoff."\n");
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );
    $arguments = [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ];

    if ($drift === 'receipt attempt') {
        $this->artisan('delivery:submit-orbit-resolution-receipt', $arguments)->assertSuccessful();
        $receipt = $resolution->receipts()->where('kind', 'orbit_resolution')->sole();
        $payload = $receipt->payload;
        $payload['attempt'] = 1;
        DB::table('receipts')->where('id', $receipt->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        $resolver->forceFill([
            'status' => AgentDispatchStatus::Settled,
            'settled_at' => now(),
        ])->save();

        app(AdvanceDeliveryAction::class)->handle($this->delivery->id);

        expect($resolution->fresh()->status)->toBe(PhaseRunStatus::Failed)
            ->and($resolution->fresh()->failure_code)->toBe('resolution_receipt_invalid')
            ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_receipt_invalid');

        return;
    }

    $attemptOneKey = IdempotencyKey::forDispatch(
        $this->delivery->id,
        OrbitFeatureWorkflow::RESOLUTION_PHASE,
        1,
        OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
    )->value;

    if ($drift === 'idempotency key') {
        AgentDispatch::query()
            ->where('idempotency_key', $attemptOneKey)
            ->update(['idempotency_key' => str_repeat('f', 64)]);
    }

    $resolver->forceFill($drift === 'idempotency key'
        ? ['idempotency_key' => $attemptOneKey]
        : ['herdr_agent_name' => 'orb-234-loop-resolution-1'])
        ->save();

    expect($validator->matchesInput(
        $this->delivery->fresh(),
        $resolution->fresh(),
        $resolver->fresh(),
    ))->toBeFalse();
    $this->artisan('delivery:submit-orbit-resolution-receipt', $arguments)
        ->expectsOutputToContain('no longer matches its immutable input')
        ->assertFailed();
    expect($resolution->receipts()->where('kind', 'orbit_resolution')->doesntExist())->toBeTrue();
})->with(['idempotency key', 'agent name', 'receipt attempt']);

it('captures the second review without protocol agent ids', function () {
    promotePullRequestReviewReceiptToSecondRound($this);
    AgentDispatch::query()
        ->whereHas('phaseRun', fn ($query) => $query
            ->where('delivery_id', $this->delivery->id)
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE))
        ->update(['herdr_agent_id' => null]);
    $this->dispatch->forceFill(['herdr_agent_id' => null])->save();

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::query()
        ->where('phase_run_id', $this->phaseRun->id)
        ->where('kind', 'orbit_pr_review')
        ->sole()
        ->payload['result'])->toBe('approved');
});

it('recovers an exact late second-review receipt after the receipt grace timeout', function () {
    promotePullRequestReviewReceiptToSecondRound($this);
    $this->dispatch->forceFill(['herdr_agent_id' => null])->save();
    blockPullRequestReviewReceiptAsMissing($this);
    Queue::fake();
    File::put($this->handoffPath, "The loaded PHP-FPM worker identity can drift from the restored files.\n");
    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;

    $blockedDelivery = $this->delivery->fresh();
    $failedPhase = $this->phaseRun->fresh();
    $settledDispatch = $this->dispatch->fresh();
    expect(app(CaptureOrbitPullRequestReviewReceipt::class)->canRecoverLateReceipt(
        $blockedDelivery,
        $failedPhase,
        $settledDispatch,
        1,
        false,
    ))->toBeTrue();

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();

    $receipt = Receipt::query()
        ->where('phase_run_id', $this->phaseRun->id)
        ->where('kind', 'orbit_pr_review')
        ->sole();
    $phase = $this->phaseRun->fresh();
    $delivery = $this->delivery->fresh();
    $dispatch = $this->dispatch->fresh();

    expect($receipt->payload['result'])->toBe('changes')
        ->and($receipt->payload['attempt'])->toBe(2)
        ->and($phase->status)->toBe(PhaseRunStatus::Running)
        ->and($phase->failure_code)->toBeNull()
        ->and($phase->failure_message)->toBeNull()
        ->and($phase->failure_details)->toBeNull()
        ->and($phase->finished_at)->toBeNull()
        ->and($delivery->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($delivery->failure_details)->toBeNull()
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->state_change_seq)->toBe(37)
        ->and($dispatch->settled_at)->not->toBeNull();
    Queue::assertPushed(AdvanceDelivery::class, 1);

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)
        ->expectsOutputToContain('was already captured')
        ->assertSuccessful();
    expect(Receipt::query()
        ->where('phase_run_id', $this->phaseRun->id)
        ->where('kind', 'orbit_pr_review')
        ->count())->toBe(1);
});

it('rejects malformed late-review recovery ledgers before repository verification', function (string $drift) {
    promotePullRequestReviewReceiptToSecondRound($this);
    blockPullRequestReviewReceiptAsMissing($this);

    match ($drift) {
        'delivery evidence' => $this->delivery->refresh()->forceFill([
            'failure_details' => [...$this->delivery->failure_details, 'dispatch_id' => -1],
        ])->save(),
        'phase code' => $this->phaseRun->refresh()->forceFill([
            'failure_code' => 'pr_review_wait_timeout',
        ])->save(),
        'phase output' => $this->phaseRun->refresh()->forceFill([
            'output' => ['result' => 'changes'],
        ])->save(),
        'dispatch status' => $this->dispatch->forceFill([
            'status' => AgentDispatchStatus::Waiting,
        ])->save(),
        'dispatch sequence' => $this->dispatch->forceFill([
            'state_change_seq' => 38,
        ])->save(),
    };
    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)
        ->expectsOutput('The pull request review phase run and dispatch do not match an active reviewer.')
        ->assertFailed();

    expect($this->repository->calls)->toBe(0)
        ->and(Receipt::query()
            ->where('phase_run_id', $this->phaseRun->id)
            ->where('kind', 'orbit_pr_review')
            ->doesntExist())->toBeTrue();
})->with([
    'delivery evidence',
    'phase code',
    'phase output',
    'dispatch status',
    'dispatch sequence',
]);

it('rejects a late-review ledger race after external verification', function () {
    promotePullRequestReviewReceiptToSecondRound($this);
    blockPullRequestReviewReceiptAsMissing($this);
    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;
    $this->repository->afterVerify = function (): void {
        $this->dispatch->forceFill(['state_change_seq' => 38])->save();
    };

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)
        ->expectsOutput('The pull request review receipt no longer matches the active dispatch.')
        ->assertFailed();

    expect($this->repository->calls)->toBe(1)
        ->and(Receipt::query()
            ->where('phase_run_id', $this->phaseRun->id)
            ->where('kind', 'orbit_pr_review')
            ->doesntExist())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked);
});

it('routes second-round approval to landing', function () {
    promotePullRequestReviewReceiptToSecondRound($this);
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $this->repository->expectedBody = $this->submittedBody;

    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $landing = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)->sole();
    $reviewReceipt = $this->phaseRun->receipts()->where('kind', 'orbit_pr_review')->sole();

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phaseRun->fresh()->output['result'])->toBe('approved')
        ->and($landing->input['pr_review_receipt_id'])->toBe($reviewReceipt->id)
        ->and($landing->input['implementation_receipt']['attempt'])->toBe(2)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 3)
            ->doesntExist())->toBeTrue();
});

it('routes repeated pull request findings to resolution without a third Builder attempt', function () {
    promotePullRequestReviewReceiptToSecondRound($this);
    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();

    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $resolution = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->sole();

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phaseRun->fresh()->output['result'])->toBe('changes')
        ->and($resolution->input['pr_review_receipt']['attempt'])->toBe(2)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 3)
            ->doesntExist())->toBeTrue();
});

it('captures changes and blocked without a replacement pull request body', function (string $result) {
    $arguments = [...$this->arguments, '--result' => $result];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();

    $payload = Receipt::query()->where('kind', 'orbit_pr_review')->sole()->payload;
    expect($payload['result'])->toBe($result)
        ->and($payload['artifact_sha'])->toBe($this->artifactSha)
        ->and($payload['pull_request_body_path'])->toBeNull()
        ->and($payload['pull_request_body'])->toBeNull()
        ->and($payload['pull_request_body_sha256'])->toBeNull();
})->with(['changes', 'blocked']);

it('returns the identical receipt but rejects a different second receipt', function () {
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)
        ->expectsOutputToContain('was already captured')
        ->assertSuccessful();

    $arguments = [...$this->arguments, '--result' => 'changes'];
    unset($arguments['--body']);
    $this->repository->expectedBody = $this->submittedBody;
    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)
        ->expectsOutput('A different pull request review receipt was already captured for this phase.')
        ->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->count())->toBe(1);
});

it('accepts a receipt that proves the prompt arrived before its RPC returned', function () {
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::Preparing])->save();

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->count())->toBe(1);
});

it('rejects invalid result, artifact, and body combinations', function (array $changes, string $message) {
    $arguments = [...$this->arguments, ...$changes];

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)
        ->expectsOutput($message)
        ->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
})->with([
    'result' => [['--result' => 'pass'], 'The pull request review result must be approved, changes, or blocked.'],
    'artifact missing' => [['--artifact' => null], 'Every pull request review result requires the exact submitted artifact SHA.'],
    'approved body missing' => [['--body' => null], 'Approved requires one final body; changes and blocked must not include a body.'],
    'changes with body' => [['--result' => 'changes'], 'Approved requires one final body; changes and blocked must not include a body.'],
]);

it('rejects unsafe or empty review files', function (string $file, string $value, string $message) {
    if ($value === 'outside') {
        $outside = $this->base.'/outside.md';
        File::put($outside, "Outside.\n");
        $this->arguments[$file] = $outside;
    } else {
        File::put($file === '--handoff' ? $this->handoffPath : $this->bodyPath, "\n");
    }

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)
        ->expectsOutput($message)
        ->assertFailed();
})->with([
    'outside handoff' => ['--handoff', 'outside', "Review handoff must be a regular file inside the issue's .loop directory."],
    'empty handoff' => ['--handoff', 'empty', 'Review handoff is empty.'],
    'outside body' => ['--body', 'outside', "Pull request body must be a regular file inside the issue's .loop directory."],
    'empty body' => ['--body', 'empty', 'Pull request body is empty.'],
]);

it('rejects candidate, repository, and published pull request drift', function (string $drift, string $message) {
    if ($drift === 'candidate') {
        Process::fake(fn () => Process::result(output: str_repeat('0', 40)."\n"));
    } elseif ($drift === 'repository') {
        $this->repository->failure = 'The pushed candidate changed.';
    } elseif ($drift === 'pull request') {
        $this->pullRequests->mismatch = true;
    } else {
        $this->pullRequests->mergeable = null;
    }

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)
        ->expectsOutput($message)
        ->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
    Queue::assertNothingPushed();
})->with([
    'candidate' => ['candidate', 'The reviewer changed the candidate or reviewed another head.'],
    'repository' => ['repository', 'The pushed candidate changed.'],
    'pull request' => ['pull request', 'The verified review evidence no longer matches the published pull request.'],
    'mergeability' => ['mergeability', 'The verified review evidence no longer matches the published pull request.'],
]);

it('rejects active ledger and immutable source drift', function (string $drift) {
    match ($drift) {
        'dispatch' => $this->dispatch->forceFill(['prompt_hash' => str_repeat('0', 64)])->save(),
        'source' => DB::table('receipts')->where('id', $this->implementationReceipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]),
        'project' => $this->delivery->projectOrchestration->forceFill([
            'state' => ProjectOrchestrationState::Paused,
        ])->save(),
    };

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
})->with(['dispatch', 'source', 'project']);

it('rejects changed reviewer dispatch identity before repository verification', function (string $field, mixed $value) {
    DB::table('agent_dispatches')->where('id', $this->dispatch->id)->update([$field => $value]);

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)
        ->expectsOutput('The pull request review dispatch no longer matches its immutable prompt.')
        ->assertFailed();

    expect($this->repository->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0)
        ->and(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
})->with([
    'idempotency key' => ['idempotency_key', 'changed'],
    'agent name' => ['herdr_agent_name', 'orb-234-loop-other'],
    'prompt name' => ['prompt_name', 'orbit_other'],
    'prompt version' => ['prompt_version', 2],
    'prompt hash' => ['prompt_hash', str_repeat('0', 64)],
    'session' => ['herdr_session', 'other'],
    'Builder pane' => ['herdr_pane_id', 'builder-pane'],
    'Builder agent' => ['herdr_agent_id', 'builder-agent'],
    'missing dispatch time' => ['dispatched_at', null],
]);

it('rejects an artifact substitution and an approved body missing its Builder gate', function (string $drift) {
    if ($drift === 'artifact') {
        $this->arguments['--artifact'] = str_repeat('d', 40);
    } else {
        $body = implode("\n", [
            'Issue: ORB-234',
            'Candidate: '.$this->candidateSha,
            'Artifact: '.$this->artifactSha,
            'Flow: discovery',
        ]);
        File::put($this->bodyPath, $body."\n");
        $this->repository->expectedBody = $body;
    }

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
})->with(['artifact', 'body']);

it('rejects a live config race after external verification', function () {
    $this->repository->afterVerify = function (): void {
        $project = $this->delivery->projectOrchestration;
        $config = $project->config;
        $config['concurrency'] = 2;
        DB::table('project_orchestrations')->where('id', $project->id)->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
    };

    $this->artisan('delivery:submit-orbit-pr-review-receipt', $this->arguments)
        ->expectsOutput('The pull request review receipt no longer matches the active dispatch.')
        ->assertFailed();

    expect(Receipt::query()->where('kind', 'orbit_pr_review')->doesntExist())->toBeTrue();
});

function capturePullRequestReviewForAdvancement(object $test, string $result): Receipt
{
    $arguments = [...$test->arguments, '--result' => $result];

    if ($result !== 'approved') {
        unset($arguments['--body']);
        $test->repository->expectedBody = $test->submittedBody;
    }

    $test->artisan('delivery:submit-orbit-pr-review-receipt', $arguments)->assertSuccessful();
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $test->repository->expectedBody = $test->submittedBody;

    return Receipt::query()->where('kind', 'orbit_pr_review')->sole();
}

it('queues advancement for the exact settled pull request review phase', function () {
    capturePullRequestReviewForAdvancement($this, 'approved');

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(
        AdvancePullRequestReviewJob::class,
        fn (AdvancePullRequestReviewJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->phaseRun->id,
    );
});

it('publishes approval once and creates one system-owned landing intent', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'approved');
    $action = app(AdvanceOrbitPullRequestReview::class);

    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $landing = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)
        ->sole();
    $published = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => $this->candidateSha,
        'state' => 'APPROVED',
        'review_body_sha256' => hash('sha256', 'Approved.'),
        'pull_request_body_sha256' => hash('sha256', $this->approvedBody),
    ];

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phaseRun->fresh()->output)->toBe([
            'receipt_id' => $receipt->id,
            'result' => 'approved',
            'published_review' => $published,
        ])
        ->and($landing->status)->toBe(PhaseRunStatus::Pending)
        ->and($landing->input['pr_review_receipt_id'])->toBe($receipt->id)
        ->and($landing->input['implementation_receipt_id'])->toBe($this->implementationReceipt->id)
        ->and($landing->input['published_review'])->toBe($published)
        ->and($landing->agentDispatches()->doesntExist())->toBeTrue()
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and($this->advanceIssues->calls)->toBe(2)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
});

it('publishes changes and queues the retained Builder correction', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'changes');

    app(AdvanceOrbitPullRequestReview::class)->handle($this->delivery->id, $this->phaseRun->id);

    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $builder = $correction->agentDispatches()->sole();

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($correction->status)->toBe(PhaseRunStatus::Pending)
        ->and($correction->input['pr_review_receipt_id'])->toBe($receipt->id)
        ->and($correction->input['implementation_receipt_id'])->toBe($this->implementationReceipt->id)
        ->and($correction->input['published_review']['state'])->toBe('CHANGES_REQUESTED')
        ->and($builder->agent_role)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE)
        ->and($builder->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and($builder->prompt_name)->toBe('orbit_pr_review_correction')
        ->and($builder->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->reviewPublisher->calls)->toBe(1);

    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    Queue::assertPushed(
        DispatchOrbitImplementation::class,
        fn (DispatchOrbitImplementation $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('routes blocked review to resolution without any GitHub publication', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'blocked');
    $action = app(AdvanceOrbitPullRequestReview::class);

    $action->handle($this->delivery->id, $this->phaseRun->id);
    $action->handle($this->delivery->id, $this->phaseRun->id);

    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->sole();
    $resolver = $resolution->agentDispatches()->sole();

    expect($this->phaseRun->fresh()->output)->toBe([
        'receipt_id' => $receipt->id,
        'result' => 'blocked',
        'published_review' => null,
    ])
        ->and($resolution->status)->toBe(PhaseRunStatus::Pending)
        ->and($resolution->input['pr_review_receipt_id'])->toBe($receipt->id)
        ->and($resolution->input['published_review'])->toBeNull()
        ->and($resolver->agent_role)->toBe(OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE)
        ->and($resolver->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->reviewPublisher->calls)->toBe(0)
        ->and($this->advanceIssues->calls)->toBe(1)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();

    config()->set('herdr.session', 'orbit');
    $herdr = new PullRequestResolutionHerdr;
    app()->instance(HerdrRuntime::class, $herdr);
    $this->repository->expectedBody = $this->submittedBody;

    $dispatched = app(DispatchOrbitPullRequestResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    );

    expect($dispatched->id)->toBe($resolver->id)
        ->and($dispatched->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($resolution->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($herdr->calls)->toBe(['open', 'split', 'start', 'prompt']);
});

it('rejects changed In Review ownership before publication', function () {
    capturePullRequestReviewForAdvancement($this, 'approved');
    $this->advanceIssues->assignee = ['id' => config('commander.hermes.nick_linear_user_id')];

    expect(fn () => app(AdvanceOrbitPullRequestReview::class)->handle(
        $this->delivery->id,
        $this->phaseRun->id,
    ))->toThrow(OrbitIssueContractChanged::class, 'changed before pull request review advancement');

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->reviewPublisher->calls)->toBe(0)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
});

it('does not consume a review when published evidence differs', function () {
    capturePullRequestReviewForAdvancement($this, 'changes');
    $this->reviewPublisher->mismatch = true;

    expect(fn () => app(AdvanceOrbitPullRequestReview::class)->handle(
        $this->delivery->id,
        $this->phaseRun->id,
    ))->toThrow(OrbitPullRequestReviewAdvancementFailed::class, 'does not match');

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
});

it('revalidates the complete review ledger before publication', function (string $record) {
    $receipt = capturePullRequestReviewForAdvancement($this, 'approved');
    $this->repository->afterVerify = function () use ($record, $receipt): void {
        if ($record === 'review dispatch') {
            DB::table('agent_dispatches')->where('id', $this->dispatch->id)->update([
                'prompt_hash' => str_repeat('0', 64),
            ]);
        } elseif ($record === 'review receipt') {
            DB::table('receipts')->where('id', $receipt->id)->update([
                'payload_hash' => str_repeat('0', 64),
            ]);
        } else {
            DB::table('receipts')->where('id', $this->implementationReceipt->id)->update([
                'payload_hash' => str_repeat('0', 64),
            ]);
        }
    };

    expect(fn () => app(AdvanceOrbitPullRequestReview::class)->handle(
        $this->delivery->id,
        $this->phaseRun->id,
    ))->toThrow(OrbitPullRequestReviewAdvancementFailed::class, 'ledger changed before publication');

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->phaseRun->fresh()->current_block)->toBeNull()
        ->and($this->reviewPublisher->calls)->toBe(0)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
})->with(['review dispatch', 'review receipt', 'source receipt']);

it('detects a live config race after review publication without consuming the receipt', function () {
    capturePullRequestReviewForAdvancement($this, 'approved');
    $this->reviewPublisher->afterPublish = function (): void {
        $project = $this->delivery->projectOrchestration;
        $config = $project->config;
        $config['concurrency'] = 2;
        DB::table('project_orchestrations')->where('id', $project->id)->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
    };

    expect(fn () => app(AdvanceOrbitPullRequestReview::class)->handle(
        $this->delivery->id,
        $this->phaseRun->id,
    ))->toThrow(OrbitPullRequestReviewAdvancementFailed::class, 'ledger changed during advancement');

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->phaseRun->fresh()->current_block)->toBe('review_publication')
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();

    $job = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);
    $job->failed(new RuntimeException('Retries exhausted after publication.'));
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])
        ->toBe('pr_review_publication_reconciliation_required');
});

it('detects a Linear race after review publication without consuming the receipt', function () {
    capturePullRequestReviewForAdvancement($this, 'changes');
    $this->reviewPublisher->afterPublish = function (): void {
        $this->advanceIssues->state = 'In Progress';
    };

    expect(fn () => app(AdvanceOrbitPullRequestReview::class)->handle(
        $this->delivery->id,
        $this->phaseRun->id,
    ))->toThrow(OrbitIssueContractChanged::class, 'changed before pull request review advancement');

    expect($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->phaseRun->fresh()->current_block)->toBe('review_publication')
        ->and($this->advanceIssues->calls)->toBe(2)
        ->and($this->reviewPublisher->calls)->toBe(1)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
});

it('rejects corrupted retained correction identity during replay', function (string $field, mixed $value) {
    capturePullRequestReviewForAdvancement($this, 'changes');
    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $builder = $correction->agentDispatches()->sole();
    DB::table('agent_dispatches')->where('id', $builder->id)->update([$field => $value]);

    expect(fn () => $action->handle($this->delivery->id, $this->phaseRun->id))
        ->toThrow(OrbitPullRequestReviewAdvancementFailed::class, 'retained pull request review transition');

    expect($this->reviewPublisher->calls)->toBe(1);
})->with([
    'role' => ['agent_role', OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE],
    'agent' => ['herdr_agent_name', 'orb-234-loop-other'],
    'prompt' => ['prompt_name', 'orbit_implementation_correction'],
]);

it('rejects corrupted completed review identity during replay', function (string $field, mixed $value) {
    capturePullRequestReviewForAdvancement($this, 'changes');
    $action = app(AdvanceOrbitPullRequestReview::class);
    $action->handle($this->delivery->id, $this->phaseRun->id);
    DB::table('agent_dispatches')->where('id', $this->dispatch->id)->update([$field => $value]);

    expect(fn () => $action->handle($this->delivery->id, $this->phaseRun->id))
        ->toThrow(OrbitPullRequestReviewAdvancementFailed::class, 'retained pull request review transition');

    expect($this->reviewPublisher->calls)->toBe(1);
})->with([
    'idempotency key' => ['idempotency_key', 'tampered-review-dispatch'],
    'agent' => ['herdr_agent_name', 'orb-234-loop-builder'],
    'prompt' => ['prompt_name', 'orbit_other_review'],
    'prompt hash' => ['prompt_hash', str_repeat('0', 64)],
    'Herdr session' => ['herdr_session', 'other-session'],
    'Herdr workspace' => ['herdr_workspace_id', null],
    'Herdr pane' => ['herdr_pane_id', 'builder-pane'],
    'status' => ['status', AgentDispatchStatus::Failed->value],
    'settled timestamp' => ['settled_at', null],
]);

it('bounds review advancement and blocks exhausted publication for reconciliation', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'approved');
    $this->phaseRun->forceFill(['current_block' => 'review_publication'])->save();
    $job = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);
    $job->failed(new RuntimeException('Worker exhausted.'));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvancePullRequestReviewJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'pr_review_publication_reconciliation_required',
            'phase_run_id' => $this->phaseRun->id,
            'dispatch_id' => $this->dispatch->id,
            'receipt_id' => $receipt->id,
            'message' => 'Worker exhausted.',
        ]);
});

it('recovers an exhausted pull request review publication through authoritative replay', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'approved');
    $this->reviewPublisher->afterPublish = static function (): void {
        throw new RuntimeException('The review publication response was lost.');
    };
    $job = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);

    expect(fn () => $job->handle(app(AdvanceOrbitPullRequestReview::class)))
        ->toThrow(RuntimeException::class, 'review publication response was lost');

    expect($this->phaseRun->fresh()->current_block)->toBe('review_publication')
        ->and($this->reviewPublisher->calls)->toBe(1);

    $job->failed(new RuntimeException('Review publication retries exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'pr_review_publication_reconciliation_required',
            'phase_run_id' => $this->phaseRun->id,
            'dispatch_id' => $this->dispatch->id,
            'receipt_id' => $receipt->id,
            'message' => 'Review publication retries exhausted.',
        ]);

    $this->delivery->forceFill(['failure_details' => [
        'code' => 'pr_review_publication_reconciliation_required',
        'message' => 'Legacy publication recovery evidence.',
    ]])->save();
    (new ReconcileDeliveries)->handle(
        app(RecoverExhaustedOrbitPlanningCorrection::class),
        app(BindOrbitPullRequestReviewPublicationRecovery::class),
    );

    expect($this->delivery->fresh()->failure_details)->toBe([
        'code' => 'pr_review_publication_reconciliation_required',
        'phase_run_id' => $this->phaseRun->id,
        'dispatch_id' => $this->dispatch->id,
        'receipt_id' => $receipt->id,
        'message' => 'Legacy publication recovery evidence.',
    ]);
    Queue::assertPushed(
        AdvancePullRequestReviewJob::class,
        fn (AdvancePullRequestReviewJob $queued): bool => $queued->deliveryId === $this->delivery->id
            && $queued->phaseRunId === $this->phaseRun->id,
    );

    $this->reviewPublisher->afterPublish = null;
    $job->handle(app(AdvanceOrbitPullRequestReview::class));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phaseRun->fresh()->current_block)->toBeNull()
        ->and($this->reviewPublisher->calls)->toBe(2)
        ->and(PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)->count())->toBe(1);
    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $queued): bool => $queued->deliveryId === $this->delivery->id,
    );
});

it('keeps an attempted review publication blocked when its evidence is not safe to bind', function () {
    $receipt = capturePullRequestReviewForAdvancement($this, 'approved');
    $this->phaseRun->forceFill(['current_block' => 'review_publication'])->save();
    $receipt->forceFill([
        'validation_status' => ReceiptValidationStatus::Invalid,
        'validation_errors' => ['The retained receipt is invalid.'],
    ])->save();
    $job = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);

    $job->failed(new RuntimeException('Unsafe publication evidence.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failed_at)->toBeNull()
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'pr_review_publication_reconciliation_required',
            'message' => 'Unsafe publication evidence.',
        ])
        ->and(app(BindOrbitPullRequestReviewPublicationRecovery::class)->handle(
            $this->delivery->id,
        ))->toBeNull();
});

it('queues delivery continuation after pull request review routing completes', function () {
    capturePullRequestReviewForAdvancement($this, 'approved');

    (new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id))
        ->handle(app(AdvanceOrbitPullRequestReview::class));

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE);
    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('fails only the exact active review when advancement retries are exhausted', function () {
    $stale = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id + 1);
    $stale->failed(new RuntimeException('Stale queue failure.'));
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);

    $active = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);
    $active->failed(new RuntimeException('Queue exhausted.'));
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'pr_review_advancement_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('releases a contended phase-scoped review advancement lock for retry', function () {
    $lock = Cache::lock(
        "delivery:pr-review-advance:{$this->delivery->id}:{$this->phaseRun->id}",
        AdvancePullRequestReviewJob::LOCK_SECONDS,
    );
    expect($lock->get())->toBeTrue();

    try {
        $job = (new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id))
            ->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitPullRequestReview::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('queues the exact retained pull request resolution dispatch', function () {
    [$resolution] = promotePullRequestReviewReceiptToResolution($this);

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(
        DispatchResolutionJob::class,
        fn (DispatchResolutionJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $resolution->id,
    );
});

it('dispatches one independent advisory resolver with the legacy proposal contract', function () {
    [$resolution, $resolver] = promotePullRequestReviewReceiptToResolution($this);
    config()->set('herdr.session', 'orbit');
    $herdr = new PullRequestResolutionHerdr;
    app()->instance(HerdrRuntime::class, $herdr);
    $this->repository->expectedBody = $this->submittedBody;
    $this->repository->calls = 0;
    $this->pullRequests->calls = 0;
    $this->advanceIssues->calls = 0;

    $result = app(DispatchOrbitPullRequestResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    );

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($result->id)->toBe($resolver->id)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($resolution->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($herdr->calls)->toBe(['open', 'split', 'start', 'prompt'])
        ->and($herdr->launch?->kind)->toBe('codex')
        ->and($herdr->launch?->arguments)->toContain(
            'gpt-5.6-sol',
            'model_reasoning_effort="high"',
            'features.multi_agent=false',
            'mcp_servers.context7.enabled=false',
            'mcp_servers.solo_nick.enabled=false',
            'mcp_servers.solo_mini.enabled=false',
        )
        ->and($herdr->prompts)->toHaveCount(1)
        ->and($herdr->prompts[0])->toContain(
            $this->repositoryPath.'/.agents/skills/resolve-pipeline-issues/SKILL.md',
            'This is advisory resolution only.',
            '--result=proposal',
            '--resolution=.loop/runtime/resolution.json',
            '"resume_phase":"implementing"',
        )
        ->and($herdr->prompts[0])->not->toContain('--result=implementation')
        ->and($this->repository->calls)->toBe(2)
        ->and($this->pullRequests->calls)->toBe(2)
        ->and($this->advanceIssues->calls)->toBe(2)
        ->and($this->advanceRepository->reservationIsHeld())->toBeFalse();
});

it('publishes and classifies one immutable structured resolution proposal', function () {
    [$resolution, $resolver] = activatePullRequestResolution($this);
    $handoff = 'Use the loaded worker configuration as the health authority.';
    $proposal = [
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ];
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', $handoff."\n");
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );
    $arguments = [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ];

    $this->artisan('delivery:submit-orbit-resolution-receipt', $arguments)->assertSuccessful();
    $this->artisan('delivery:submit-orbit-resolution-receipt', $arguments)
        ->expectsOutputToContain('was already captured')
        ->assertSuccessful();
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', "Different proposal.\n");
    $this->artisan('delivery:submit-orbit-resolution-receipt', $arguments)
        ->expectsOutputToContain('different resolution receipt')
        ->assertFailed();

    $receipt = $resolution->receipts()->where('kind', 'orbit_resolution')->sole();
    expect($receipt->payload['result'])->toBe('proposal')
        ->and($receipt->payload['handoff'])->toBe($handoff)
        ->and($receipt->payload['resolution'])->toBe($proposal)
        ->and($receipt->payload['resolution_sha256'])->toBe(
            hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)),
        )
        ->and($resolution->receipts()->count())->toBe(1);

    $resolver->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);

    expect($resolution->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($resolution->fresh()->output['receipt_id'])->toBe($receipt->id)
        ->and($resolution->fresh()->output['result'])->toBe('proposal')
        ->and($resolution->fresh()->output['publication'])->toBe([
            'comment_id' => '22222222-3333-4444-8555-666666666666',
            'marker' => "ORBIT-LOOP-RESOLUTION:{$resolver->id}",
            'body_sha256' => hash(
                'sha256',
                "ORBIT-LOOP-RESOLUTION:{$resolver->id}\n\n{$handoff}\n\n".
                'Commander routing: Needs an explicit decision or recovery action.',
            ),
        ])
        ->and($resolution->fresh()->output['adopted'])->toBeFalse()
        ->and($resolution->fresh()->output['automatic_adoption_eligible'])->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_adoption_ready')
        ->and($this->resolutionPublisher->calls)->toBe(1)
        ->and($this->resolutionPublisher->lastAdopted)->toBeFalse();

    Queue::assertPushed(
        AdoptResolutionJob::class,
        fn (AdoptResolutionJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $resolution->id,
    );

    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);

    expect($this->resolutionPublisher->calls)->toBe(1);
});

it('adopts one requirement-free resolution into one exact Builder correction and replays safely', function () {
    [$resolution, $resolver, $receipt] = preparePublishedResolutionAdoption($this);
    $publication = $resolution->output['publication'];
    $sourceReceipt = Receipt::query()->findOrFail($resolution->input['implementation_receipt_id']);
    $action = app(AdoptOrbitResolution::class);

    $action->handle($this->delivery->id, $resolution->id);
    $action->handle($this->delivery->id, $resolution->id);

    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 3)
        ->sole();
    $dispatch = $correction->agentDispatches()->sole();
    $output = $resolution->fresh()->output;

    expect($this->resolutionTransitioner->calls)->toBe(1)
        ->and($this->resolutionTransitioner->mutations)->toBe(1)
        ->and($this->advanceIssues->state)->toBe('In Progress')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($resolution->fresh()->current_block)->toBeNull()
        ->and($output)->toBe([
            'receipt_id' => $receipt->id,
            'result' => 'proposal',
            'publication' => $publication,
            'adopted' => true,
            'automatic_adoption_eligible' => true,
            'expected_resume_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            'requirements' => [],
            'reason' => 'Eligible for automatic adoption into implementing; adoption is pending.',
            'adoption' => [
                'implementation_phase_run_id' => $correction->id,
                'implementation_attempt' => 3,
                'linear_state_id' => '33333333-4444-4555-8666-777777777777',
                'linear_state' => 'In Progress',
                'contract_sha256' => str_repeat('d', 64),
            ],
        ])
        ->and($correction->status)->toBe(PhaseRunStatus::Pending)
        ->and($correction->input)->toBe([
            'resolution_phase_run_id' => $resolution->id,
            'resolution_dispatch_id' => $resolver->id,
            'resolution_receipt_id' => $receipt->id,
            'resolution_receipt' => $receipt->payload,
            'resolution_publication' => $publication,
            'implementation_receipt_id' => $sourceReceipt->id,
            'implementation_receipt' => $sourceReceipt->payload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ])
        ->and($dispatch->agent_role)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE)
        ->and($dispatch->idempotency_key)->toBe(IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            3,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value)
        ->and($dispatch->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and($dispatch->prompt_name)->toBe('orbit_resolution_correction')
        ->and($dispatch->prompt_version)->toBe(OrbitFeatureWorkflow::RESOLUTION_CORRECTION_PROMPT_VERSION)
        ->and($dispatch->prompt_hash)->toBe(str_repeat('0', 64))
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->count())->toBe(3);

    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('dispatches an adopted resolution with its distinct exact correction prompt', function () {
    [$resolution] = preparePublishedResolutionAdoption($this);
    app(AdoptOrbitResolution::class)->handle($this->delivery->id, $resolution->id);
    normalizeResolutionCorrectionBuilder($this);
    config()->set('herdr.session', 'orbit');
    $herdr = new PullRequestResolutionBuilderHerdr($this->worktreePath);
    app()->instance(HerdrRuntime::class, $herdr);
    $this->repository->expectedBody = $this->submittedBody;

    $dispatch = app(App\Delivery\Actions\DispatchOrbitImplementation::class)
        ->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->prompt_name)->toBe('orbit_resolution_correction')
        ->and($herdr->calls)->toBe(['get', 'prompt'])
        ->and($herdr->prompts)->toHaveCount(1)
        ->and($herdr->prompts[0])->toContain('Apply the automatically adopted resolution for ORB-234')
        ->and($herdr->prompts[0])->toContain('Use the loaded worker configuration as the health authority.')
        ->and($herdr->prompts[0])->toContain('ORBIT-LOOP-RESOLUTION:')
        ->and($herdr->prompts[0])->not->toContain('actual merge conflicts')
        ->and($herdr->prompts[0])->not->toContain('independent pull request review finding');
});

it('rejects corrupted resolution adoption evidence before changing Linear', function (string $drift) {
    [$resolution] = preparePublishedResolutionAdoption($this);

    if ($drift === 'publication hash') {
        $output = $resolution->output;
        $output['publication']['body_sha256'] = str_repeat('0', 64);
        DB::table('phase_runs')->where('id', $resolution->id)->update([
            'output' => json_encode($output, JSON_THROW_ON_ERROR),
        ]);
    } else {
        $failure = $this->delivery->fresh()->failure_details;

        if ($drift === 'publication identity') {
            $failure['publication']['comment_id'] = '99999999-8888-4777-8666-555555555555';
        } else {
            $failure['receipt_id']++;
        }

        DB::table('deliveries')->where('id', $this->delivery->id)->update([
            'failure_details' => json_encode($failure, JSON_THROW_ON_ERROR),
        ]);
    }

    expect(fn () => app(AdoptOrbitResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    ))->toThrow(OrbitResolutionAdoptionFailed::class, 'retained resolution adoption is inconsistent');

    expect($this->resolutionTransitioner->calls)->toBe(0)
        ->and($this->resolutionTransitioner->mutations)->toBe(0)
        ->and($this->advanceIssues->state)->toBe('In Review')
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->count())->toBe(2);
})->with(['publication hash', 'publication identity', 'receipt identity']);

it('revalidates the automatic adoption budget before changing Linear', function () {
    [$resolution, $resolver, $receipt] = preparePublishedResolutionAdoption($this);
    exhaustResolutionAdoptionBudget($this, $resolution, $resolver, $receipt);

    expect(fn () => app(AdoptOrbitResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    ))->toThrow(OrbitResolutionAdoptionFailed::class, 'retained resolution adoption is inconsistent');

    expect($this->resolutionTransitioner->calls)->toBe(0)
        ->and($this->advanceIssues->state)->toBe('In Review')
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->count())->toBe(2);
});

it('recovers a lost Linear adoption response without a second effective transition', function () {
    [$resolution, $resolver, $receipt] = preparePublishedResolutionAdoption($this);
    $this->resolutionTransitioner->loseNextResponse = true;
    $job = new AdoptResolutionJob($this->delivery->id, $resolution->id);

    expect(fn () => $job->handle(app(AdoptOrbitResolution::class)))
        ->toThrow(OrbitResolutionAdoptionFailed::class, 'could not verify Linear In Progress');

    expect($this->advanceIssues->state)->toBe('In Progress')
        ->and($resolution->fresh()->current_block)->toBe('resolution_adoption')
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_adoption_ready')
        ->and($this->resolutionTransitioner->calls)->toBe(1)
        ->and($this->resolutionTransitioner->mutations)->toBe(1);

    $job->failed(new RuntimeException('Resolution adoption retries exhausted.'));

    expect($this->delivery->fresh()->failure_details)->toBe([
        'code' => 'resolution_adoption_reconciliation_required',
        'phase_run_id' => $resolution->id,
        'dispatch_id' => $resolver->id,
        'receipt_id' => $receipt->id,
        'publication' => $resolution->fresh()->output['publication'],
        'expected_resume_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'requirements' => [],
        'reason' => 'Eligible for automatic adoption into implementing; adoption is pending.',
        'message' => 'Resolution adoption retries exhausted.',
    ]);

    $job->handle(app(AdoptOrbitResolution::class));

    expect($this->resolutionTransitioner->calls)->toBe(2)
        ->and($this->resolutionTransitioner->mutations)->toBe(1)
        ->and($resolution->fresh()->current_block)->toBeNull()
        ->and($resolution->fresh()->output['adopted'])->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 3)
            ->count())->toBe(1)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->uniqueId())->toBe("delivery:resolution-adopt:{$this->delivery->id}:{$resolution->id}")
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdoptResolutionJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('recovers a resolution publication whose response was lost without creating a new intent', function () {
    [$resolution, $resolver, $receipt] = prepareResolutionProposalForPublication($this);
    $this->resolutionPublisher->afterPublish = static function (): void {
        throw new RuntimeException('The publication response was lost.');
    };
    $job = new AdvanceResolutionJob($this->delivery->id, $resolution->id);

    expect(fn () => $job->handle(app(AdvanceOrbitResolution::class)))
        ->toThrow(RuntimeException::class, 'publication response was lost');

    expect($resolution->fresh()->current_block)->toBe('resolution_publication')
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'resolution_proposal_ready',
            'phase_run_id' => $resolution->id,
            'dispatch_id' => $resolver->id,
            'receipt_id' => $receipt->id,
        ])
        ->and($this->resolutionPublisher->calls)->toBe(1);

    $job->failed(new RuntimeException('Resolution publication retries exhausted.'));

    expect($this->delivery->fresh()->failure_details)->toBe([
        'code' => 'resolution_publication_reconciliation_required',
        'phase_run_id' => $resolution->id,
        'dispatch_id' => $resolver->id,
        'receipt_id' => $receipt->id,
        'message' => 'Resolution publication retries exhausted.',
    ]);

    $this->resolutionPublisher->afterPublish = null;
    $job->handle(app(AdvanceOrbitResolution::class));

    expect($resolution->fresh()->current_block)->toBeNull()
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_adoption_ready')
        ->and($this->resolutionPublisher->calls)->toBe(2);
});

it('revalidates the immutable resolution ledger immediately before publication', function () {
    [$resolution, , $receipt] = prepareResolutionProposalForPublication($this);
    $this->advanceIssues->calls = 0;
    $this->advanceIssues->afterFetch = function () use ($receipt): void {
        DB::table('receipts')->where('id', $receipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]);
    };

    expect(fn () => app(AdvanceOrbitResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    ))->toThrow(OrbitResolutionPublicationFailed::class, 'ledger changed before publication');

    expect($resolution->fresh()->current_block)->toBe('resolution_publication')
        ->and($this->resolutionPublisher->calls)->toBe(0);
});

it('revalidates exact resolution identities after external publication', function () {
    [$resolution, $resolver] = prepareResolutionProposalForPublication($this);
    $this->resolutionPublisher->afterPublish = function () use ($resolver): void {
        $delivery = $this->delivery->fresh();
        $failure = $delivery->failure_details;
        $failure['dispatch_id'] = $resolver->id + 1;
        DB::table('deliveries')->where('id', $delivery->id)->update([
            'failure_details' => json_encode($failure, JSON_THROW_ON_ERROR),
        ]);
    };

    expect(fn () => app(AdvanceOrbitResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    ))->toThrow(OrbitResolutionPublicationFailed::class, 'ledger changed during publication');

    expect($resolution->fresh()->current_block)->toBe('resolution_publication')
        ->and($this->resolutionPublisher->calls)->toBe(1);
});

it('rejects a corrupted committed resolution publication instead of publishing again', function () {
    [$resolution] = prepareResolutionProposalForPublication($this);
    app(AdvanceOrbitResolution::class)->handle($this->delivery->id, $resolution->id);
    $output = $resolution->fresh()->output;
    $output['automatic_adoption_eligible'] = false;
    DB::table('phase_runs')->where('id', $resolution->id)->update([
        'output' => json_encode($output, JSON_THROW_ON_ERROR),
    ]);

    expect(fn () => app(AdvanceOrbitResolution::class)->handle(
        $this->delivery->id,
        $resolution->id,
    ))->toThrow(OrbitResolutionPublicationFailed::class, 'retained resolution publication is inconsistent');

    expect($this->resolutionPublisher->calls)->toBe(1);
});

it('rejects a malformed structured resolution proposal', function (array $proposal) {
    [$resolution, $resolver] = activatePullRequestResolution($this);
    File::put($this->worktreePath.'/.loop/runtime/resolution-handoff.md', "Complete proposal.\n");
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution.json',
        json_encode($proposal, JSON_THROW_ON_ERROR)."\n",
    );

    $this->artisan('delivery:submit-orbit-resolution-receipt', [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'proposal',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
        '--resolution' => '.loop/runtime/resolution.json',
    ])->expectsOutputToContain('no longer matches')->assertFailed();

    expect($resolution->receipts()->where('kind', 'orbit_resolution')->doesntExist())->toBeTrue();
})->with([
    'missing required array' => [[
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
    ]],
    'invalid resume phase' => [[
        'schema' => 1,
        'resume_phase' => 'resolution',
        'required_adrs' => [],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ]],
    'empty requirement' => [[
        'schema' => 1,
        'resume_phase' => 'implementing',
        'required_adrs' => [''],
        'human_decisions' => [],
        'issue_changes' => [],
        'plan_changes' => [],
    ]],
]);

it('captures a blocked resolver result without a proposal document', function () {
    [$resolution, $resolver] = activatePullRequestResolution($this);
    File::put(
        $this->worktreePath.'/.loop/runtime/resolution-handoff.md',
        "The resolver could not produce a safe proposal.\n",
    );

    $this->artisan('delivery:submit-orbit-resolution-receipt', [
        'phase-run' => (string) $resolution->id,
        'dispatch' => (string) $resolver->id,
        '--result' => 'blocked',
        '--handoff' => '.loop/runtime/resolution-handoff.md',
    ])->assertSuccessful();

    $receipt = $resolution->receipts()->where('kind', 'orbit_resolution')->sole();
    expect($receipt->payload['resolution'])->toBeNull()
        ->and($receipt->payload['resolution_path'])->toBeNull()
        ->and($receipt->payload['resolution_sha256'])->toBeNull();

    $resolver->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);

    expect($resolution->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('resolution_blocked');
});
