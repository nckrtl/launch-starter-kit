<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitImplementation;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitImplementationAdvancementFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitImplementation as AdvanceImplementationJob;
use App\Jobs\DispatchOrbitImplementation as DispatchImplementationJob;
use App\Jobs\DispatchOrbitPullRequestReview;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ImplementationAdvancementRepository implements OrbitRepository
{
    public int $transactionLevel = 0;

    public mixed $reservationHandle = null;

    public ?Closure $afterReserve = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve the implementation advancement.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'implementation-advancement.lock');
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

final class ImplementationAdvancementVerifier implements OrbitImplementationRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public bool $mismatch = false;

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
            ->and($reviewedCandidateSha)->toBe($this->expectedReviewedCandidateSha)
            ->and($candidateSha)->toBe($this->expectedCandidateSha)
            ->and($artifactSha)->toBe($this->expectedArtifactSha);
        $this->calls++;

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $this->mismatch ? str_repeat('0', 40) : $candidateSha,
            treeSha: str_repeat('d', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

final class ImplementationAdvancementIssueProvider implements OrbitIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public function __construct(public OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        return $this->snapshot;
    }
}

final class ImplementationAdvancementPullRequests implements OrbitPullRequestPublisher
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?Closure $afterPublish = null;

    public ?bool $mergeable = true;

    public int $number = 42;

    public string $expectedCandidateSha;

    public function __construct()
    {
        $this->expectedCandidateSha = str_repeat('b', 40);
    }

    public function publish(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234')
            ->and($issueTitle)->toBe('Build the feature')
            ->and($candidateSha)->toBe($this->expectedCandidateSha);
        $this->calls++;

        if ($this->afterPublish instanceof Closure) {
            ($this->afterPublish)();
        }

        return new PublishedOrbitPullRequest(
            number: $this->number,
            url: "https://github.com/nckrtl/orbit/pull/{$this->number}",
            candidateSha: $candidateSha,
            bodyHash: hash('sha256', $pullRequestBody),
            mergeable: $this->mergeable,
        );
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/advance-orbit-implementation-'.bin2hex(random_bytes(4)));
    $projects = $this->base.'/projects';
    File::makeDirectory($projects, 0755, true);
    config()->set('commander.projects_path', $projects);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            $this->worktree.'/.loop/issue.json',
        ),
        $this->worktree,
        new CandidateCheck(
            '/home/nckrtl/orbit/.git/orbit-checks/'.str_repeat('a', 40).'/startup/result.json',
            str_repeat('a', 40),
            str_repeat('9', 40),
        ),
    );
    $planning = PhaseRun::sole();
    $planner = advancementDispatch($planning, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'planner');
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('a', 40),
        'handoff_path' => '.loop/runtime/planning.md', 'handoff' => 'Planning ready.',
        'artifact_sha' => str_repeat('e', 40), 'plan_sha256' => str_repeat('1', 64),
    ];
    $planningReceipt = advancementReceipt($planning, 'orbit_planning', $planningPayload);
    advancementComplete($planning, $planningReceipt, 'ready');
    $review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => ['planning_receipt_id' => $planningReceipt->id, 'planning_receipt' => $planningPayload],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $reviewer = advancementDispatch($review, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'reviewer');
    $reviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('a', 40),
        'handoff_path' => '.loop/runtime/review.md', 'handoff' => 'Plan approved.',
        'artifact_sha' => str_repeat('f', 40), 'plan_sha256' => str_repeat('2', 64),
    ];
    $reviewReceipt = advancementReceipt($review, 'orbit_plan_review', $reviewPayload);
    advancementComplete($review, $reviewReceipt, 'pass');
    $this->phase = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'input' => ['plan_review_receipt_id' => $reviewReceipt->id, 'plan_review_receipt' => $reviewPayload],
        'started_at' => now(),
    ]);
    $this->dispatch = advancementDispatch(
        $this->phase,
        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'implementer',
    );
    $this->dispatch->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            1,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_version' => OrbitFeatureWorkflow::IMPLEMENTATION_PROMPT_VERSION,
        'prompt_hash' => str_repeat('4', 64),
        'dispatched_at' => now(),
    ])->save();
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('b', 40),
        'Artifact: '.str_repeat('c', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/gate/result.json)',
    ]);
    $this->implementationPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $this->dispatch->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktree,
        'reviewed_candidate_sha' => str_repeat('a', 40),
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/implementation.md', 'handoff' => 'Implementation ready.',
        'artifact_sha' => str_repeat('c', 40),
        'gate_receipt_path' => '/home/nckrtl/orbit/.git/orbit-checks/gate/result.json',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $this->body,
        'pull_request_body_sha256' => hash('sha256', $this->body),
        'flow' => 'discovery',
    ];
    $this->receipt = advancementReceipt($this->phase, 'orbit_implementation', $this->implementationPayload);
    $this->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $issuePayload = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'identifier' => 'ORB-234',
        'title' => 'Build the feature',
        'state' => ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
    ];
    $this->repository = new ImplementationAdvancementRepository;
    $this->verifier = new ImplementationAdvancementVerifier;
    $this->issues = new ImplementationAdvancementIssueProvider(new OrbitIssueSnapshot(
        $issuePayload['id'], $issuePayload['identifier'], $issuePayload, str_repeat('d', 64),
    ));
    $this->pullRequests = new ImplementationAdvancementPullRequests;
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->verifier->transactionLevel = $transactionLevel;
    $this->issues->transactionLevel = $transactionLevel;
    $this->pullRequests->transactionLevel = $transactionLevel;
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitImplementationRepository::class, $this->verifier);
    app()->instance(OrbitIssueProvider::class, $this->issues);
    app()->instance(OrbitPullRequestPublisher::class, $this->pullRequests);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function advancementDispatch(PhaseRun $phase, string $role, string $key): AgentDispatch
{
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => 'implementation-advancement-'.$key,
        'herdr_agent_name' => 'orb-234-loop-'.$key,
        'prompt_name' => 'orbit_'.$key,
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('3', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @param array<string, mixed> $payload */
function advancementReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
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

function advancementComplete(PhaseRun $phase, Receipt $receipt, string $result): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $receipt->id, 'result' => $result],
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
}

function addImplementationPullRequestAttachment(object $test): void
{
    $factory = app(OrbitIssueSnapshotFactory::class);
    $payload = $test->issues->snapshot->payload;
    $payload['description'] = null;
    $payload['labels'] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
    $payload['attachments'] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
    $expectedContractHash = $factory->contractHash($payload);
    $planning = PhaseRun::query()->oldest('id')->firstOrFail();
    $input = $planning->input;
    $input['issue_snapshot']['contract_sha256'] = $expectedContractHash;
    $planning->forceFill(['input' => $input])->save();
    $payload['attachments']['nodes'] = [[
        'title' => $test->issues->snapshot->issueKey.': '.$payload['title'],
        'url' => $test->delivery->pull_request_url,
    ]];
    $test->issues->snapshot = new OrbitIssueSnapshot(
        $test->issues->snapshot->issueId,
        $test->issues->snapshot->issueKey,
        $payload,
        $factory->contractHash($payload),
    );
}

function promoteImplementationAdvancementToCorrection(object $test, string $result = 'ready'): void
{
    $test->pullRequests->mergeable = false;
    app(AdvanceOrbitImplementation::class)->handle($test->delivery->id);

    $test->correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $test->correctionDispatch = $test->correction->agentDispatches()->sole();
    $test->correctionDispatch->forceFill([
        'prompt_hash' => str_repeat('5', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ])->save();
    $test->correctionBody = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('e', 40),
        'Artifact: '.str_repeat('f', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/correction/result.json)',
    ]);
    $test->correctionPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $test->correctionDispatch->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2, 'result' => $result, 'worktree' => $test->worktree,
        'reviewed_candidate_sha' => str_repeat('b', 40),
        'candidate_sha' => str_repeat('e', 40),
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => $result === 'ready' ? 'Merge conflicts resolved.' : 'Correction is blocked.',
        'artifact_sha' => $result === 'ready' ? str_repeat('f', 40) : null,
        'gate_receipt_path' => $result === 'ready'
            ? '/home/nckrtl/orbit/.git/orbit-checks/correction/result.json'
            : null,
        'pull_request_body_path' => $result === 'ready' ? '.loop/runtime/pull-request-body.md' : null,
        'pull_request_body' => $result === 'ready' ? $test->correctionBody : null,
        'pull_request_body_sha256' => $result === 'ready' ? hash('sha256', $test->correctionBody) : null,
        'flow' => $result === 'ready' ? 'discovery' : null,
    ];
    $test->correctionReceipt = advancementReceipt(
        $test->correction,
        'orbit_implementation',
        $test->correctionPayload,
    );
    $test->correction->forceFill([
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ])->save();
    $test->delivery->refresh()->forceFill([
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $test->verifier->expectedReviewedCandidateSha = str_repeat('b', 40);
    $test->verifier->expectedCandidateSha = str_repeat('e', 40);
    $test->verifier->expectedArtifactSha = str_repeat('f', 40);
    $test->pullRequests->expectedCandidateSha = str_repeat('e', 40);
    $test->pullRequests->mergeable = true;
    $test->verifier->calls = 0;
    $test->issues->calls = 0;
    $test->pullRequests->calls = 0;
}

function promoteImplementationAdvancementFromLateReviewConflict(object $test): void
{
    promoteImplementationAdvancementToCorrection($test);
    $output = $test->phase->refresh()->output;
    $output['mergeable'] = true;
    $test->phase->forceFill(['output' => $output])->save();
    $review = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'input' => [
            'implementation_receipt_id' => $test->receipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'failure_code' => 'pr_review_mergeability_changed',
        'failure_message' => 'The published pull request became unmergeable before independent review.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $review->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE,
            1,
            OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The published pull request became unmergeable before independent review.',
    ]);
}

function promoteImplementationAdvancementToReviewCorrection(object $test): void
{
    app(AdvanceOrbitImplementation::class)->handle($test->delivery->id);
    $test->pullRequestReview = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 1)
        ->sole();
    $test->pullRequestReviewer = $test->pullRequestReview->agentDispatches()->sole();
    $pullRequest = [
        'number' => 42,
        'url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ];
    $reviewPrompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $test->worktree,
        $test->delivery->id,
        $test->pullRequestReview->id,
        $test->pullRequestReviewer->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $test->pullRequestReview->id,
            $test->pullRequestReviewer->id,
        ),
        $test->implementationPayload,
        $pullRequest,
    );
    $test->pullRequestReviewer->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-1',
        'herdr_tab_id' => 'tab-1',
        'herdr_pane_id' => 'pr-review-pane',
        'herdr_terminal_id' => 'pr-review-terminal',
        'herdr_agent_id' => 'pr-review-agent-id',
        'prompt_hash' => hash('sha256', $reviewPrompt),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ])->save();
    $test->pullRequestReviewPayload = [
        'kind' => 'orbit_pr_review', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $test->pullRequestReviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'changes', 'worktree' => $test->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/pr-review.md',
        'handoff' => 'Fix the concrete review finding.',
        'artifact_sha' => str_repeat('c', 40),
        'pull_request_body_path' => null,
        'pull_request_body' => null,
        'pull_request_body_sha256' => null,
    ];
    $test->pullRequestReviewReceipt = advancementReceipt(
        $test->pullRequestReview,
        'orbit_pr_review',
        $test->pullRequestReviewPayload,
    );
    $test->publishedReview = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => str_repeat('b', 40),
        'state' => 'CHANGES_REQUESTED',
        'review_body_sha256' => hash('sha256', 'Fix the concrete review finding.'),
        'pull_request_body_sha256' => hash('sha256', $test->body),
    ];
    $test->pullRequestReview->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => [
            'receipt_id' => $test->pullRequestReviewReceipt->id,
            'result' => 'changes',
            'published_review' => $test->publishedReview,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
    $test->correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'pr_review_receipt_id' => $test->pullRequestReviewReceipt->id,
            'pr_review_receipt' => $test->pullRequestReviewPayload,
            'implementation_receipt_id' => $test->receipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => $pullRequest,
            'published_review' => $test->publishedReview,
        ],
        'started_at' => now(),
    ]);
    $test->correctionDispatch = advancementDispatch(
        $test->correction,
        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'review-correction',
    );
    $test->correctionDispatch->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            2,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_pr_review_correction',
        'prompt_version' => OrbitFeatureWorkflow::IMPLEMENTATION_CORRECTION_PROMPT_VERSION,
        'prompt_hash' => str_repeat('5', 64),
        'dispatched_at' => now(),
    ])->save();
    $test->correctionBody = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('e', 40),
        'Artifact: '.str_repeat('f', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/correction/result.json)',
    ]);
    $test->correctionPayload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $test->correctionDispatch->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2, 'result' => 'ready', 'worktree' => $test->worktree,
        'reviewed_candidate_sha' => str_repeat('b', 40),
        'candidate_sha' => str_repeat('e', 40),
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => 'The review finding was corrected.',
        'artifact_sha' => str_repeat('f', 40),
        'gate_receipt_path' => '/home/nckrtl/orbit/.git/orbit-checks/correction/result.json',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $test->correctionBody,
        'pull_request_body_sha256' => hash('sha256', $test->correctionBody),
        'flow' => 'discovery',
    ];
    $test->correctionReceipt = advancementReceipt(
        $test->correction,
        'orbit_implementation',
        $test->correctionPayload,
    );
    $test->delivery->refresh()->forceFill([
        'candidate_sha' => str_repeat('b', 40),
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $test->verifier->expectedReviewedCandidateSha = str_repeat('b', 40);
    $test->verifier->expectedCandidateSha = str_repeat('e', 40);
    $test->verifier->expectedArtifactSha = str_repeat('f', 40);
    $test->pullRequests->expectedCandidateSha = str_repeat('e', 40);
    $test->verifier->calls = 0;
    $test->issues->calls = 0;
    $test->pullRequests->calls = 0;
}

it('queues the dedicated implementation advancement job', function () {
    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(
        AdvanceImplementationJob::class,
        fn (AdvanceImplementationJob $job): bool => $job->phaseRunId === $this->phase->id,
    );
});

it('routes blocked implementation directly to one resolution intent without external work', function () {
    $payload = [
        ...$this->implementationPayload,
        'result' => 'blocked',
        'artifact_sha' => null,
        'gate_receipt_path' => null,
        'pull_request_body_path' => null,
        'pull_request_body' => null,
        'pull_request_body_sha256' => null,
        'flow' => null,
    ];
    DB::table('receipts')->where('id', $this->receipt->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);

    $action = app(AdvanceOrbitImplementation::class);
    expect($action->handle($this->delivery->id))->toBeFalse();
    $action->handle($this->delivery->id);

    $resolution = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->sole();
    $resolutionDispatch = $resolution->agentDispatches()->sole();
    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phase->fresh()->output)->toBe(['receipt_id' => $this->receipt->id, 'result' => 'blocked'])
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($resolution->input)->toBe([
            'implementation_receipt_id' => $this->receipt->id,
            'implementation_receipt' => $payload,
            'pull_request' => null,
        ])
        ->and($resolutionDispatch->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->verifier->calls)->toBe(0)
        ->and($this->issues->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0)
        ->and(PhaseRun::where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->count())->toBe(1);
});

it('does not route a blocked implementation for a disabled project', function () {
    $payload = [
        ...$this->implementationPayload,
        'result' => 'blocked',
        'artifact_sha' => null,
        'gate_receipt_path' => null,
        'pull_request_body_path' => null,
        'pull_request_body' => null,
        'pull_request_body_sha256' => null,
        'flow' => null,
    ];
    DB::table('receipts')->where('id', $this->receipt->id)->update([
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
    ]);
    $this->delivery->projectOrchestration->forceFill([
        'state' => ProjectOrchestrationState::Paused,
    ])->save();

    expect(fn () => app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'project is not enabled');

    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->verifier->calls)->toBe(0)
        ->and($this->issues->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0)
        ->and(PhaseRun::where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->count())->toBe(0);
});

it('publishes a mergeable candidate and creates one immutable PR-review intent', function () {
    $action = app(AdvanceOrbitImplementation::class);
    expect($action->handle($this->delivery->id))->toBeFalse();
    $action->handle($this->delivery->id);

    $review = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->sole();
    $reviewDispatch = $review->agentDispatches()->sole();
    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->phase->fresh()->output)->toBe([
            'receipt_id' => $this->receipt->id,
            'result' => 'ready',
            'pull_request_number' => 42,
            'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
            'mergeable' => true,
        ])
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('b', 40))
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($review->input)->toBe([
            'implementation_receipt_id' => $this->receipt->id,
            'implementation_receipt' => $this->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ])
        ->and($reviewDispatch->agent_role)->toBe(OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
        ->and($reviewDispatch->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->verifier->calls)->toBe(1)
        ->and($this->issues->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1)
        ->and(PhaseRun::where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->count())->toBe(1);
});

it('rejects changed retained PR-review dispatch metadata', function (string $field, mixed $value) {
    $action = app(AdvanceOrbitImplementation::class);
    $action->handle($this->delivery->id);
    $review = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->sole();
    DB::table('agent_dispatches')->where('phase_run_id', $review->id)->update([$field => $value]);

    expect(fn () => $action->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'post-implementation intent is inconsistent');
})->with([
    'agent name' => ['herdr_agent_name', 'orb-234-loop-other'],
    'prompt name' => ['prompt_name', 'orbit_other'],
    'prompt version' => ['prompt_version', 2],
    'prompt hash' => ['prompt_hash', str_repeat('9', 64)],
]);

it('rejects changed retained implementation publication identity', function (string $field, mixed $value) {
    $action = app(AdvanceOrbitImplementation::class);
    $action->handle($this->delivery->id);
    DB::table('deliveries')->where('id', $this->delivery->id)->update([$field => $value]);

    expect(fn () => $action->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'post-implementation intent is inconsistent');
})->with([
    'candidate' => ['candidate_sha', str_repeat('0', 40)],
    'pull request number' => ['pull_request_number', 43],
    'pull request URL' => ['pull_request_url', 'https://github.com/nckrtl/orbit/pull/99'],
]);

it('persists unresolved mergeability and later advances without another Builder prompt', function () {
    $this->pullRequests->mergeable = null;
    $action = app(AdvanceOrbitImplementation::class);

    expect($action->handle($this->delivery->id))->toBeTrue();

    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->phase->fresh()->current_block)->toBe('mergeability')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('implementation_mergeability_pending');

    $this->pullRequests->mergeable = true;
    expect($action->handle($this->delivery->id))->toBeFalse();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->phase->fresh()->current_block)->toBeNull()
        ->and($this->pullRequests->calls)->toBe(2);
});

it('routes an actual merge conflict to one retained-Builder correction intent', function () {
    $this->pullRequests->mergeable = false;

    expect(app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))->toBeFalse();

    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $correctionDispatch = $correction->agentDispatches()->sole();
    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($correction->input['pull_request']['mergeable'])->toBeFalse()
        ->and($correctionDispatch->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and($correctionDispatch->prompt_name)->toBe('orbit_implementation_correction');

    Queue::fake();
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    Queue::assertPushed(DispatchImplementationJob::class, 1);
});

it('queues advancement for the exact merge-conflict correction phase', function () {
    promoteImplementationAdvancementToCorrection($this);
    Queue::fake();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(
        AdvanceImplementationJob::class,
        fn (AdvanceImplementationJob $job): bool => $job->phaseRunId === $this->correction->id,
    );
});

it('publishes the corrected candidate to the same pull request and creates one PR-review intent', function () {
    promoteImplementationAdvancementToCorrection($this);
    $action = app(AdvanceOrbitImplementation::class);

    expect($action->handle($this->delivery->id))->toBeFalse();
    $action->handle($this->delivery->id);

    $review = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->sole();
    $reviewDispatch = $review->agentDispatches()->sole();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->correction->fresh()->output)->toBe([
            'receipt_id' => $this->correctionReceipt->id,
            'result' => 'ready',
            'pull_request_number' => 42,
            'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
            'mergeable' => true,
        ])
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('e', 40))
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($review->input)->toBe([
            'implementation_receipt_id' => $this->correctionReceipt->id,
            'implementation_receipt' => $this->correctionPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ])
        ->and($reviewDispatch->agent_role)->toBe(OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
        ->and($reviewDispatch->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->verifier->calls)->toBe(1)
        ->and($this->issues->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1)
        ->and(PhaseRun::where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->count())->toBe(1);
});

it('allows the known pull request attachment while advancing a corrected candidate', function () {
    promoteImplementationAdvancementToCorrection($this);
    addImplementationPullRequestAttachment($this);

    expect(app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))->toBeFalse();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->verifier->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1);
});

it('creates review attempt two after a late merge-conflict correction', function () {
    promoteImplementationAdvancementFromLateReviewConflict($this);
    addImplementationPullRequestAttachment($this);

    expect(app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))->toBeFalse();

    $review = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 2)
        ->sole();
    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($review->status)->toBe(PhaseRunStatus::Pending)
        ->and($review->agentDispatches()->sole()->herdr_agent_name)->toBe('orb-234-loop-pr-review-2');
});

it('publishes a review correction and creates the second independent review intent', function () {
    promoteImplementationAdvancementToReviewCorrection($this);

    expect(app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))->toBeFalse();

    $secondReview = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 2)
        ->sole();
    $reviewer = $secondReview->agentDispatches()->sole();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('e', 40))
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($secondReview->input)->toBe([
            'implementation_receipt_id' => $this->correctionReceipt->id,
            'implementation_receipt' => $this->correctionPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ])
        ->and($reviewer->agent_role)->toBe(OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE)
        ->and($reviewer->herdr_agent_name)->toBe('orb-234-loop-pr-review-2')
        ->and($reviewer->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)->count())->toBe(2);

    Queue::fake();
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    Queue::assertPushed(
        DispatchOrbitPullRequestReview::class,
        fn (DispatchOrbitPullRequestReview $job): bool => $job->phaseRunId === $secondReview->id,
    );
});

it('retries unresolved corrected-candidate mergeability without another Builder attempt', function () {
    promoteImplementationAdvancementToCorrection($this);
    $this->pullRequests->mergeable = null;
    $action = app(AdvanceOrbitImplementation::class);

    expect($action->handle($this->delivery->id))->toBeTrue();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->correction->fresh()->current_block)->toBe('mergeability')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('e', 40))
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 3)
            ->doesntExist())->toBeTrue();

    $this->pullRequests->mergeable = true;
    expect($action->handle($this->delivery->id))->toBeFalse();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->correction->fresh()->current_block)->toBeNull()
        ->and($this->pullRequests->calls)->toBe(2);
});

it('routes a repeated merge conflict to one resolution intent without attempt three', function () {
    promoteImplementationAdvancementToCorrection($this);
    $this->pullRequests->mergeable = false;
    $action = app(AdvanceOrbitImplementation::class);

    expect($action->handle($this->delivery->id))->toBeFalse();
    $action->handle($this->delivery->id);

    $resolution = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->sole();
    $resolver = $resolution->agentDispatches()->sole();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->correction->fresh()->output['mergeable'])->toBeFalse()
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('e', 40))
        ->and($resolution->input)->toBe([
            'implementation_receipt_id' => $this->correctionReceipt->id,
            'implementation_receipt' => $this->correctionPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => false,
            ],
        ])
        ->and($resolver->agent_role)->toBe(OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE)
        ->and($resolver->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 3)
            ->doesntExist())->toBeTrue()
        ->and(PhaseRun::where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->count())->toBe(1);
});

it('routes a blocked merge-conflict correction to resolution and preserves the published PR', function () {
    promoteImplementationAdvancementToCorrection($this, 'blocked');
    $action = app(AdvanceOrbitImplementation::class);

    expect($action->handle($this->delivery->id))->toBeFalse();
    $action->handle($this->delivery->id);

    $resolution = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)->sole();

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->correction->fresh()->output)->toBe([
            'receipt_id' => $this->correctionReceipt->id,
            'result' => 'blocked',
        ])
        ->and($this->delivery->fresh()->candidate_sha)->toBe(str_repeat('b', 40))
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42)
        ->and($this->delivery->fresh()->pull_request_url)->toBe('https://github.com/nckrtl/orbit/pull/42')
        ->and($resolution->input)->toBe([
            'implementation_receipt_id' => $this->correctionReceipt->id,
            'implementation_receipt' => $this->correctionPayload,
            'pull_request' => null,
        ])
        ->and($this->verifier->calls)->toBe(0)
        ->and($this->issues->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0);
});

it('rejects a corrected publication that replaces the recorded pull request identity', function () {
    promoteImplementationAdvancementToCorrection($this);
    $this->pullRequests->number = 43;

    expect(fn () => app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'does not match the implementation receipt');

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->pull_request_number)->toBe(42);
});

it('rejects attempt-two ledger races after corrected pull request publication', function (string $race) {
    promoteImplementationAdvancementToCorrection($this);
    $this->pullRequests->afterPublish = function () use ($race): void {
        if ($race === 'config') {
            $project = $this->delivery->projectOrchestration;
            $config = $project->config;
            $config['concurrency'] = 2;
            DB::table('project_orchestrations')->where('id', $project->id)->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
            ]);

            return;
        }

        if ($race === 'source receipt') {
            DB::table('receipts')->where('id', $this->receipt->id)->update([
                'payload_hash' => str_repeat('0', 64),
            ]);

            return;
        }

        if ($race === 'candidate') {
            DB::table('deliveries')->where('id', $this->delivery->id)->update([
                'candidate_sha' => str_repeat('0', 40),
            ]);

            return;
        }

        DB::table('deliveries')->where('id', $this->delivery->id)->update([
            'pull_request_number' => 99,
            'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/99',
        ]);
    };

    expect(fn () => app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'ledger changed during advancement');

    expect($this->correction->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['config', 'source receipt', 'candidate', 'pull request identity']);

it('does not consume the receipt when verified implementation evidence differs', function () {
    $this->verifier->mismatch = true;

    expect(fn () => app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'does not match its receipt');

    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->pullRequests->calls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rejects a config race after pull request publication without consuming the receipt', function () {
    $this->pullRequests->afterPublish = function (): void {
        $project = $this->delivery->projectOrchestration;
        $config = $project->config;
        $config['concurrency'] = 2;
        DB::table('project_orchestrations')->where('id', $project->id)->update([
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
    };

    expect(fn () => app(AdvanceOrbitImplementation::class)->handle($this->delivery->id))
        ->toThrow(OrbitImplementationAdvancementFailed::class, 'ledger changed during advancement');

    expect($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('bounds the implementation advancement job and preserves blocked recovery', function () {
    $job = new AdvanceImplementationJob($this->delivery->id, $this->phase->id);
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => ['code' => 'manual_recovery'],
    ])->save();
    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvanceImplementationJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe(['code' => 'manual_recovery']);
});

it('queues delivery continuation after implementation routing completes', function () {
    (new AdvanceImplementationJob($this->delivery->id, $this->phase->id))
        ->handle(app(AdvanceOrbitImplementation::class));

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE);
    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('continues a completed review correction into its second review', function () {
    promoteImplementationAdvancementToReviewCorrection($this);

    (new AdvanceImplementationJob($this->delivery->id, $this->correction->id))
        ->handle(app(AdvanceOrbitImplementation::class));

    $secondReview = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->where('attempt', 2)
        ->sole();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($secondReview->status)->toBe(PhaseRunStatus::Pending);
    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('fails only an active implementation when advancement retries are exhausted', function () {
    $job = new AdvanceImplementationJob($this->delivery->id, $this->phase->id);
    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'implementation_advancement_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('fails the exact active correction when its advancement retries are exhausted', function () {
    promoteImplementationAdvancementToCorrection($this);
    $job = new AdvanceImplementationJob($this->delivery->id, $this->correction->id);

    $job->failed(new RuntimeException('Correction queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'implementation_advancement_exhausted',
            'message' => 'Correction queue exhausted.',
        ]);
});

it('does not fail a queued retained-Builder correction from a stale advancement job', function () {
    $job = new AdvanceImplementationJob($this->delivery->id, $this->phase->id);
    $this->pullRequests->mergeable = false;
    app(AdvanceOrbitImplementation::class)->handle($this->delivery->id);

    $job->failed(new RuntimeException('Stale queue failure.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Completed);
});

it('does not advance or fail attempt two from a stale attempt-one job', function () {
    $job = (new AdvanceImplementationJob($this->delivery->id, $this->phase->id))
        ->withFakeQueueInteractions();
    promoteImplementationAdvancementToCorrection($this);

    $job->handle(app(AdvanceOrbitImplementation::class));
    $job->failed(new RuntimeException('Stale queue failure.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->correction->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->verifier->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0);
});

it('releases a contended implementation-advancement lock for retry', function () {
    $lock = Cache::lock(
        "delivery:implementation-advance:{$this->delivery->id}",
        AdvanceImplementationJob::LOCK_SECONDS,
    );
    $lock->get();

    try {
        $job = (new AdvanceImplementationJob($this->delivery->id, $this->phase->id))->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitImplementation::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});
