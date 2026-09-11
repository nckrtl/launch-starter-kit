<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitPullRequestReview;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitPullRequestReviewPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Data\PublishedOrbitPullRequestReview;
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
use App\Delivery\Exceptions\OrbitPullRequestReviewAdvancementFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewPublicationFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitPullRequestReview as AdvancePullRequestReviewJob;
use App\Jobs\DispatchOrbitImplementation;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->and($reviewedCandidateSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('c', 40))
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

final class PullRequestReviewReceiptPullRequests implements OrbitPullRequestInspector
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?bool $mergeable = true;

    public bool $mismatch = false;

    public string $submittedBody;

    public function inspect(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234')
            ->and($candidateSha)->toBe(str_repeat('b', 40))
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

final class PullRequestReviewAdvanceIssues implements OrbitActiveIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?string $state = 'In Review';

    public mixed $assignee = null;

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueId)->toBe('11111111-2222-4333-8444-555555555555')
            ->and($issueKey)->toBe('ORB-234');
        $this->calls++;

        return new OrbitIssueSnapshot(
            $issueId,
            $issueKey,
            [
                'id' => $issueId,
                'identifier' => $issueKey,
                'state' => ['id' => 'state-review', 'name' => $this->state, 'type' => 'started'],
                'assignee' => $this->assignee,
                'delegate' => ['id' => config('commander.hermes.tom_linear_viewer_id')],
            ],
            str_repeat('d', 64),
        );
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
            ->and($candidateSha)->toBe(str_repeat('b', 40))
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
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->pullRequests->transactionLevel = $transactionLevel;
    $this->advanceRepository->transactionLevel = $transactionLevel;
    $this->advanceIssues->transactionLevel = $transactionLevel;
    $this->reviewPublisher->transactionLevel = $transactionLevel;
    app()->instance(OrbitImplementationRepository::class, $this->repository);
    app()->instance(OrbitPullRequestInspector::class, $this->pullRequests);
    app()->instance(OrbitRepository::class, $this->advanceRepository);
    app()->instance(OrbitActiveIssueProvider::class, $this->advanceIssues);
    app()->instance(OrbitPullRequestReviewPublisher::class, $this->reviewPublisher);

    chdir($this->worktreePath);
    Queue::fake();
    Process::fake(fn ($process) => $process->command === ['git', 'rev-parse', 'HEAD']
        ? Process::result(output: $this->candidateSha."\n")
        : throw new RuntimeException('Unexpected pull request review receipt command.'))
        ->preventStrayProcesses();
});

afterEach(function () {
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

it('publishes changes and creates an inert retained-Builder correction intent', function () {
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
    Queue::assertNotPushed(DispatchOrbitImplementation::class);
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
    $job = new AdvancePullRequestReviewJob($this->delivery->id, $this->phaseRun->id);
    $job->failed(new RuntimeException(
        'Worker exhausted.',
        0,
        new OrbitPullRequestReviewPublicationFailed('Publication outcome is unresolved.'),
    ));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvancePullRequestReviewJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'pr_review_publication_reconciliation_required',
            'message' => 'Worker exhausted.',
        ]);
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
