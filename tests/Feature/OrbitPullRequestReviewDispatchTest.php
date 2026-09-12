<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitPullRequestReview;
use App\Delivery\Actions\RecoverOrbitPullRequestReviewTransition;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
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
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitImplementationReceiptValidator;
use App\Jobs\DispatchOrbitImplementation as DispatchImplementationJob;
use App\Jobs\DispatchOrbitPullRequestReview as DispatchPullRequestReviewJob;
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

final class PullRequestReviewDispatchRepository implements OrbitRepository
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
            throw new RuntimeException('Could not reserve the review dispatch.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'pr-review-dispatch.lock');
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

final class PullRequestReviewDispatchVerifier implements OrbitImplementationRepository
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public bool $mismatch = false;

    public ?Closure $afterVerify = null;

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
            ->and($startupWorktree->headSha)->toBe(str_repeat('a', 40));
        $this->calls++;

        if ($this->afterVerify instanceof Closure) {
            ($this->afterVerify)();
        }

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $this->mismatch ? str_repeat('0', 40) : $candidateSha,
            treeSha: str_repeat('7', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

final class PullRequestReviewDispatchIssues implements OrbitActiveIssueProvider
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public function __construct(public OrbitIssueSnapshot $snapshot) {}

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls++;

        return $this->snapshot;
    }
}

final class PullRequestReviewDispatchTransitioner implements OrbitIssueTransitioner, OrbitReviewIssueTransitioner
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public int $progressCalls = 0;

    public bool $fail = false;

    public ?Closure $afterTransition = null;

    public ?Closure $afterProgressTransition = null;

    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        expect($expectedContractHash)->toBe($current->contractHash);
        $this->progressCalls++;
        $payload = $current->payload;
        $payload['state'] = ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started'];

        $transitioned = new OrbitIssueSnapshot(
            $current->issueId,
            $current->issueKey,
            $payload,
            $current->contractHash,
        );

        if ($this->afterProgressTransition instanceof Closure) {
            ($this->afterProgressTransition)($transitioned);
            $this->afterProgressTransition = null;
        }

        return $transitioned;
    }

    public function transitionToInReview(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($expectedContractHash)->toBe($current->contractHash);
        $this->calls++;

        if ($this->fail) {
            throw new OrbitIssueTransitionFailed('Linear outcome is unknown.');
        }

        if ($this->afterTransition instanceof Closure) {
            ($this->afterTransition)();
            $this->afterTransition = null;
        }

        $payload = $current->payload;
        $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
        $payload['assignee'] = null;

        return new OrbitIssueSnapshot(
            $current->issueId,
            $current->issueKey,
            $payload,
            $current->contractHash,
        );
    }
}

final class PullRequestReviewDispatchPullRequests implements OrbitPullRequestInspector
{
    public int $transactionLevel = 0;

    public int $calls = 0;

    public ?bool $mergeable = true;

    public int $number = 42;

    public string $candidateSha;

    public string $body;

    public function inspect(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($number)->toBe(42)
            ->and($issueKey)->toBe('ORB-234');
        $this->calls++;

        return new PublishedOrbitPullRequest(
            number: $this->number,
            url: "https://github.com/nckrtl/orbit/pull/{$this->number}",
            candidateSha: $this->candidateSha,
            bodyHash: hash('sha256', $this->body),
            mergeable: $this->mergeable,
        );
    }
}

final class PullRequestReviewDispatchHerdr implements HerdrRuntime
{
    public int $transactionLevel = 0;

    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $prompts = [];

    public ?HerdrAgentLaunch $launch = null;

    public ?string $failure = null;

    public ?Closure $beforePromptReturn = null;

    public bool $reuseBuilder = false;

    public string $agentName = 'orb-234-loop-pr-review-1';

    public ?string $agentId = 'reviewer-agent';

    public int $agentSequence = 2;

    public string $workingDirectory = '/fast/worktrees/orbit/orb-234';

    public string $agentStatus = 'idle';

    public function openWorktree(string $repositoryPath, string $worktreePath, ?string $label = null): OpenedHerdrWorktree
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($repositoryPath)->toBe('/home/nckrtl/orbit')
            ->and($worktreePath)->toBe('/fast/worktrees/orbit/orb-234')
            ->and($label)->toBe('ORB-234');
        $this->calls[] = 'open';

        if ($this->failure === 'open') {
            throw new RuntimeException('Open outcome is unknown.');
        }

        return new OpenedHerdrWorktree('workspace', 'tab', 'worktree-pane', 'worktree-terminal', true);
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls[] = 'split';

        if ($this->failure === 'split') {
            throw new RuntimeException('Split outcome is unknown.');
        }

        return $this->identifiers(null, 1);
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls[] = 'start';
        $this->launch = $launch;

        if ($this->failure === 'start') {
            throw new RuntimeException('Start outcome is unknown.');
        }

        return $this->identifiers($this->agentId, $this->agentSequence);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        expect(DB::transactionLevel())->toBe($this->transactionLevel);
        $this->calls[] = 'prompt';
        $this->prompts[] = $prompt;

        if ($this->failure === 'prompt') {
            throw new RuntimeException('Prompt outcome is unknown.');
        }

        if ($this->beforePromptReturn instanceof Closure) {
            ($this->beforePromptReturn)();
        }

        return $this->identifiers('reviewer-agent', 3);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->calls[] = 'get';

        if ($this->failure === 'start') {
            throw new RuntimeException('Agent recovery failed.');
        }

        return $this->identifiers($this->agentId, $this->agentSequence);
    }

    private function identifiers(?string $agentId, int $sequence): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            'workspace',
            'tab',
            $this->reuseBuilder ? 'builder-pane' : 'reviewer-pane',
            $this->reuseBuilder ? 'builder-terminal' : 'reviewer-terminal',
            $this->reuseBuilder ? 'builder-agent' : $agentId,
            $this->agentName,
            $sequence,
            $this->workingDirectory,
            $this->agentStatus,
        );
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-pr-review-dispatch-'.bin2hex(random_bytes(4)));
    $projects = $this->base.'/projects';
    File::makeDirectory($projects, 0755, true);
    config()->set('commander.projects_path', $projects);
    config()->set('herdr.session', 'orbit');
    config()->set('commander.hermes.tom_linear_viewer_id', '4fa61558-9052-45f7-8a7c-49e0b891d4bf');
    config()->set('commander.hermes.nick_linear_user_id', '691cb14c-60d5-415a-a5c7-a7c19fe83424');
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
    $planner = prReviewDispatch($planning, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'planner');
    $planningPayload = [
        'kind' => 'orbit_planning', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1, 'result' => 'ready', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('a', 40),
        'handoff_path' => '.loop/runtime/planning.md', 'handoff' => 'Planning ready.',
        'artifact_sha' => str_repeat('e', 40), 'plan_sha256' => str_repeat('1', 64),
    ];
    $planningReceipt = prReviewReceipt($planning, 'orbit_planning', $planningPayload);
    prReviewComplete($planning, $planningReceipt, ['receipt_id' => $planningReceipt->id, 'result' => 'ready']);

    $planReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => ['planning_receipt_id' => $planningReceipt->id, 'planning_receipt' => $planningPayload],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $planReviewer = prReviewDispatch($planReview, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'plan-reviewer');
    $planReviewPayload = [
        'kind' => 'orbit_plan_review', 'schema_version' => 1,
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $planReviewer->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1, 'result' => 'pass', 'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('a', 40),
        'handoff_path' => '.loop/runtime/review.md', 'handoff' => 'Plan approved.',
        'artifact_sha' => str_repeat('f', 40), 'plan_sha256' => str_repeat('2', 64),
    ];
    $planReviewReceipt = prReviewReceipt($planReview, 'orbit_plan_review', $planReviewPayload);
    prReviewComplete($planReview, $planReviewReceipt, ['receipt_id' => $planReviewReceipt->id, 'result' => 'pass']);

    $this->implementation = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => ['plan_review_receipt_id' => $planReviewReceipt->id, 'plan_review_receipt' => $planReviewPayload],
        'started_at' => now(), 'finished_at' => now(),
    ]);
    $this->builder = prReviewDispatch($this->implementation, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'builder');
    $this->builder->forceFill([
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
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
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
        'delivery_id' => $this->delivery->id, 'dispatch_id' => $this->builder->id,
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
    $this->implementationReceipt = prReviewReceipt(
        $this->implementation,
        'orbit_implementation',
        $this->implementationPayload,
    );
    $this->implementation->forceFill(['output' => [
        'receipt_id' => $this->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]])->save();
    $this->review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'implementation_receipt_id' => $this->implementationReceipt->id,
            'implementation_receipt' => $this->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->review->id,
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
        'status' => AgentDispatchStatus::Pending,
    ]);
    $this->delivery->forceFill([
        'candidate_sha' => str_repeat('b', 40),
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::Queued,
    ])->save();

    $issuePayload = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'identifier' => 'ORB-234',
        'title' => 'Build the feature',
        'state' => ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started'],
        'assignee' => ['id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424'],
        'delegate' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
    ];
    $this->repository = new PullRequestReviewDispatchRepository;
    $this->verifier = new PullRequestReviewDispatchVerifier;
    $this->issues = new PullRequestReviewDispatchIssues(new OrbitIssueSnapshot(
        $issuePayload['id'], $issuePayload['identifier'], $issuePayload, str_repeat('d', 64),
    ));
    $this->transitions = new PullRequestReviewDispatchTransitioner;
    $this->pullRequests = new PullRequestReviewDispatchPullRequests;
    $this->pullRequests->candidateSha = str_repeat('b', 40);
    $this->pullRequests->body = $this->body;
    $this->herdr = new PullRequestReviewDispatchHerdr;
    $transactionLevel = DB::transactionLevel();
    $this->repository->transactionLevel = $transactionLevel;
    $this->verifier->transactionLevel = $transactionLevel;
    $this->issues->transactionLevel = $transactionLevel;
    $this->transitions->transactionLevel = $transactionLevel;
    $this->pullRequests->transactionLevel = $transactionLevel;
    $this->herdr->transactionLevel = $transactionLevel;
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitImplementationRepository::class, $this->verifier);
    app()->instance(OrbitActiveIssueProvider::class, $this->issues);
    app()->instance(OrbitReviewIssueTransitioner::class, $this->transitions);
    app()->instance(OrbitIssueTransitioner::class, $this->transitions);
    app()->instance(OrbitPullRequestInspector::class, $this->pullRequests);
    app()->instance(HerdrRuntime::class, $this->herdr);
});

afterEach(fn () => File::deleteDirectory($this->base));

function prReviewDispatch(PhaseRun $phase, string $role, string $name): AgentDispatch
{
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => "pr-review-fixture-{$name}",
        'herdr_agent_name' => "orb-234-loop-{$name}",
        'prompt_name' => "orbit_{$name}",
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('3', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @param array<string, mixed> $payload */
function prReviewReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
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
function prReviewComplete(PhaseRun $phase, Receipt $receipt, array $output): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => $output,
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
}

/** @param list<array{title: string, url: string}> $extraAttachments */
function addKnownPullRequestAttachment(object $test, array $extraAttachments = []): void
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
    $payload['attachments']['nodes'] = [
        [
            'title' => $test->issues->snapshot->issueKey.': '.$payload['title'],
            'url' => $test->delivery->pull_request_url,
        ],
        ...$extraAttachments,
    ];
    $test->issues->snapshot = new OrbitIssueSnapshot(
        $test->issues->snapshot->issueId,
        $test->issues->snapshot->issueKey,
        $payload,
        $factory->contractHash($payload),
    );
}

function interruptPullRequestReviewBeforePrompt(object $test): void
{
    $test->herdr->agentId = null;
    $test->herdr->agentSequence = 0;
    addKnownPullRequestAttachment($test);
    $payload = $test->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $payload['assignee'] = null;
    $test->issues->snapshot = new OrbitIssueSnapshot(
        $test->issues->snapshot->issueId,
        $test->issues->snapshot->issueKey,
        $payload,
        $test->issues->snapshot->contractHash,
    );
    $test->review->forceFill([
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ])->save();
    $prompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $test->worktree,
        $test->delivery->id,
        $test->review->id,
        $test->dispatch->id,
        sprintf(
            "'%s' '%s' delivery:submit-orbit-pr-review-receipt %d %d",
            PHP_BINARY,
            base_path('artisan'),
            $test->review->id,
            $test->dispatch->id,
        ),
        $test->implementationPayload,
        $test->review->input['pull_request'],
    );
    $test->dispatch->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'reviewer-pane',
        'herdr_terminal_id' => 'reviewer-terminal',
        'herdr_agent_id' => null,
        'state_change_seq' => 0,
        'prompt_hash' => hash('sha256', $prompt),
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'pr_review_final_verification',
        'dispatched_at' => now(),
    ])->save();
    $test->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'pr_review_dispatch_interrupted',
            'dispatch_id' => $test->dispatch->id,
            'stage' => 'pr_review_final_verification',
        ],
    ])->save();
}

function promotePullRequestReviewSourceToCorrection(object $test): void
{
    $test->implementation->forceFill(['output' => [
        'receipt_id' => $test->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => false,
    ]])->save();
    $correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'implementation_receipt_id' => $test->implementationReceipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => false,
            ],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $builder = prReviewDispatch($correction, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'correction');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            2,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation_correction',
        'prompt_hash' => str_repeat('5', 64),
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
        'dispatched_at' => now(),
    ])->save();
    $body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('e', 40),
        'Artifact: '.str_repeat('f', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/correction/result.json)',
    ]);
    $payload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2, 'result' => 'ready', 'worktree' => $test->worktree,
        'reviewed_candidate_sha' => str_repeat('b', 40),
        'candidate_sha' => str_repeat('e', 40),
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => 'Merge conflicts resolved.',
        'artifact_sha' => str_repeat('f', 40),
        'gate_receipt_path' => '/home/nckrtl/orbit/.git/orbit-checks/correction/result.json',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $body,
        'pull_request_body_sha256' => hash('sha256', $body),
        'flow' => 'discovery',
    ];
    $receipt = prReviewReceipt($correction, 'orbit_implementation', $payload);
    $correction->forceFill(['output' => [
        'receipt_id' => $receipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]])->save();
    $test->review->forceFill(['input' => [
        'implementation_receipt_id' => $receipt->id,
        'implementation_receipt' => $payload,
        'pull_request' => [
            'number' => 42,
            'url' => 'https://github.com/nckrtl/orbit/pull/42',
            'mergeable' => true,
        ],
    ]])->save();
    $test->delivery->forceFill(['candidate_sha' => str_repeat('e', 40)])->save();
    $test->pullRequests->candidateSha = str_repeat('e', 40);
    $test->pullRequests->body = $body;
}

function promotePullRequestReviewSourceFromLateConflict(object $test): void
{
    promotePullRequestReviewSourceToCorrection($test);
    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    $receipt = $correction->receipts()->where('kind', 'orbit_implementation')->sole();
    $test->implementation->forceFill(['output' => [
        'receipt_id' => $test->implementationReceipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]])->save();
    $test->review->forceFill([
        'status' => PhaseRunStatus::Failed,
        'input' => [
            'implementation_receipt_id' => $test->implementationReceipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
        'failure_code' => 'pr_review_mergeability_changed',
        'failure_message' => 'The published pull request became unmergeable before independent review.',
        'finished_at' => now(),
    ])->save();
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The published pull request became unmergeable before independent review.',
    ])->save();
    $test->review = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'implementation_receipt_id' => $receipt->id,
            'implementation_receipt' => $receipt->payload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
    ]);
    $test->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $test->review->id,
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
        'status' => AgentDispatchStatus::Pending,
    ]);
    $test->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::Queued,
    ])->save();
    $test->herdr->agentName = 'orb-234-loop-pr-review-2';
}

function promotePullRequestReviewToSecondRound(object $test): void
{
    $firstReviewPrompt = app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
        'ORB-234',
        $test->worktree,
        $test->delivery->id,
        $test->review->id,
        $test->dispatch->id,
        sprintf(
            '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $test->review->id,
            $test->dispatch->id,
        ),
        $test->implementationPayload,
        $test->review->input['pull_request'],
    );
    $test->dispatch->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace',
        'herdr_tab_id' => 'tab',
        'herdr_pane_id' => 'first-reviewer-pane',
        'herdr_terminal_id' => 'first-reviewer-terminal',
        'herdr_agent_id' => 'first-reviewer-agent',
        'prompt_hash' => hash('sha256', $firstReviewPrompt),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ])->save();
    $reviewPayload = [
        'kind' => 'orbit_pr_review', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $test->dispatch->id,
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
    $reviewReceipt = prReviewReceipt($test->review, 'orbit_pr_review', $reviewPayload);
    $publishedReview = [
        'id' => 901,
        'reviewer_login' => 'tom-nckrtl[bot]',
        'candidate_sha' => str_repeat('b', 40),
        'state' => 'CHANGES_REQUESTED',
        'review_body_sha256' => hash('sha256', 'Fix the concrete review finding.'),
        'pull_request_body_sha256' => hash('sha256', $test->body),
    ];
    prReviewComplete($test->review, $reviewReceipt, [
        'receipt_id' => $reviewReceipt->id,
        'result' => 'changes',
        'published_review' => $publishedReview,
    ]);
    $correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'pr_review_receipt_id' => $reviewReceipt->id,
            'pr_review_receipt' => $reviewPayload,
            'implementation_receipt_id' => $test->implementationReceipt->id,
            'implementation_receipt' => $test->implementationPayload,
            'pull_request' => $test->review->input['pull_request'],
            'published_review' => $publishedReview,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $builder = prReviewDispatch($correction, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'review-correction');
    $builder->forceFill([
        'idempotency_key' => IdempotencyKey::forDispatch(
            $test->delivery->id,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            2,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_pr_review_correction',
        'prompt_hash' => str_repeat('5', 64),
        'herdr_pane_id' => 'builder-pane',
        'herdr_terminal_id' => 'builder-terminal',
        'herdr_agent_id' => 'builder-agent',
        'dispatched_at' => now(),
    ])->save();
    $body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.str_repeat('e', 40),
        'Artifact: '.str_repeat('f', 40),
        'Flow: discovery',
        'Builder gate: passed (/home/nckrtl/orbit/.git/orbit-checks/correction/result.json)',
    ]);
    $payload = [
        'kind' => 'orbit_implementation', 'schema_version' => 1,
        'delivery_id' => $test->delivery->id, 'dispatch_id' => $builder->id,
        'issue_key' => 'ORB-234', 'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2, 'result' => 'ready', 'worktree' => $test->worktree,
        'reviewed_candidate_sha' => str_repeat('b', 40),
        'candidate_sha' => str_repeat('e', 40),
        'handoff_path' => '.loop/runtime/implementation-correction.md',
        'handoff' => 'The review finding was corrected.',
        'artifact_sha' => str_repeat('f', 40),
        'gate_receipt_path' => '/home/nckrtl/orbit/.git/orbit-checks/correction/result.json',
        'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
        'pull_request_body' => $body,
        'pull_request_body_sha256' => hash('sha256', $body),
        'flow' => 'discovery',
    ];
    $receipt = prReviewReceipt($correction, 'orbit_implementation', $payload);
    $correction->forceFill(['output' => [
        'receipt_id' => $receipt->id,
        'result' => 'ready',
        'pull_request_number' => 42,
        'pull_request_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'mergeable' => true,
    ]])->save();
    $secondReview = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'implementation_receipt_id' => $receipt->id,
            'implementation_receipt' => $payload,
            'pull_request' => [
                'number' => 42,
                'url' => 'https://github.com/nckrtl/orbit/pull/42',
                'mergeable' => true,
            ],
        ],
    ]);
    $secondDispatch = AgentDispatch::query()->create([
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
        'status' => AgentDispatchStatus::Pending,
    ]);
    $test->review = $secondReview;
    $test->dispatch = $secondDispatch;
    $test->delivery->forceFill([
        'candidate_sha' => str_repeat('e', 40),
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'status' => DeliveryStatus::Queued,
    ])->save();
    $test->pullRequests->candidateSha = str_repeat('e', 40);
    $test->pullRequests->body = $body;
    $test->herdr->agentName = 'orb-234-loop-pr-review-2';
}

it('queues review dispatch for the exact pull request review phase', function () {
    Queue::fake();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(
        DispatchPullRequestReviewJob::class,
        fn (DispatchPullRequestReviewJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $this->review->id,
    );
});

it('dispatches an independent reviewer with an exact immutable prompt', function () {
    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->herdr->calls)->toBe(['open', 'split', 'start', 'prompt'])
        ->and($this->herdr->launch)->not->toBeNull()
        ->and($this->herdr->launch?->kind)->toBe('codex')
        ->and($this->herdr->launch?->arguments)->toContain('features.multi_agent=false')
        ->and($this->herdr->prompts)->toHaveCount(1)
        ->and($this->herdr->prompts[0])->toContain('Independently review the exact published pull request for ORB-234.')
        ->and($this->herdr->prompts[0])->toContain('Delivery: '.$this->delivery->id)
        ->and($this->herdr->prompts[0])->toContain('Phase run: '.$this->review->id)
        ->and($this->herdr->prompts[0])->toContain('Dispatch: '.$this->dispatch->id)
        ->and($this->herdr->prompts[0])->toContain('"candidate_sha": "'.str_repeat('b', 40).'"')
        ->and($this->herdr->prompts[0])->toContain('"number": 42')
        ->and($this->herdr->prompts[0])->toContain('delivery:submit-orbit-pr-review-receipt')
        ->and($this->dispatch->fresh()->herdr_pane_id)->toBe('reviewer-pane')
        ->and($this->dispatch->fresh()->herdr_agent_id)->toBe('reviewer-agent')
        ->and($this->transitions->calls)->toBe(2)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('dispatches review when Linear adds only the known pull request attachment', function () {
    addKnownPullRequestAttachment($this);

    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->herdr->prompts)->toHaveCount(1)
        ->and($this->transitions->calls)->toBe(2);
});

it('dispatches from the latest corrected implementation receipt', function () {
    promotePullRequestReviewSourceToCorrection($this);

    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->herdr->prompts[0])->toContain('"attempt": 2')
        ->and($this->herdr->prompts[0])->toContain('"candidate_sha": "'.str_repeat('e', 40).'"');
});

it('dispatches a distinct independent reviewer for the second review round', function () {
    promotePullRequestReviewToSecondRound($this);

    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($result->herdr_agent_name)->toBe('orb-234-loop-pr-review-2')
        ->and($this->review->fresh()->attempt)->toBe(2)
        ->and($this->herdr->prompts[0])->toContain(
            'Independently review the exact published pull request for ORB-234.',
            '"attempt": 2',
            '"candidate_sha": "'.str_repeat('e', 40).'"',
        )
        ->and($this->transitions->calls)->toBe(2)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('dispatches review attempt two after a late merge-conflict correction', function () {
    promotePullRequestReviewSourceFromLateConflict($this);

    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($result->herdr_agent_name)->toBe('orb-234-loop-pr-review-2')
        ->and($this->herdr->prompts[0])->toContain(
            '"attempt": 2',
            '"candidate_sha": "'.str_repeat('e', 40).'"',
        );
});

it('rejects an older implementation receipt when a later implementation phase exists', function () {
    PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
        'input' => [],
    ]);

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'no longer matches');

    expect($this->herdr->calls)->toBe([]);
});

it('replays waiting and settled dispatches without external work', function (AgentDispatchStatus $status) {
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => $status,
        'prompt_hash' => hash('sha256', app(OrbitFeatureWorkflow::class)->pullRequestReviewPrompt(
            'ORB-234',
            $this->worktree,
            $this->delivery->id,
            $this->review->id,
            $this->dispatch->id,
            sprintf("'%s' '%s' delivery:submit-orbit-pr-review-receipt %d %d", PHP_BINARY, base_path('artisan'), $this->review->id, $this->dispatch->id),
            $this->implementationPayload,
            $this->review->input['pull_request'],
        )),
    ])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();

    expect(app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id)->status)
        ->toBe($status)
        ->and($this->verifier->calls)->toBe(0)
        ->and($this->issues->calls)->toBe(0)
        ->and($this->pullRequests->calls)->toBe(0)
        ->and($this->herdr->calls)->toBe([]);
})->with([AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled]);

it('waits without prompting while GitHub mergeability is unresolved', function () {
    $this->pullRequests->mergeable = null;

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'has not resolved');

    expect($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->herdr->calls)->toBe([])
        ->and($this->transitions->calls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('blocks without prompting when the pull request becomes unmergeable', function () {
    $this->pullRequests->mergeable = false;

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'became unmergeable');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Failed)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('pr_review_mergeability_changed')
        ->and($this->herdr->calls)->toBe([])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rejects source, candidate, pull request, issue, and project drift before prompting', function (string $drift) {
    match ($drift) {
        'source receipt' => DB::table('receipts')->where('id', $this->implementationReceipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]),
        'candidate' => $this->verifier->mismatch = true,
        'pull request' => $this->pullRequests->number = 43,
        'issue' => $this->issues->snapshot = new OrbitIssueSnapshot(
            $this->issues->snapshot->issueId,
            $this->issues->snapshot->issueKey,
            [...$this->issues->snapshot->payload, 'delegate' => null],
            $this->issues->snapshot->contractHash,
        ),
        'project' => $this->repository->afterReserve = function (): void {
            $project = $this->delivery->projectOrchestration;
            $config = $project->config;
            $config['concurrency'] = 2;
            DB::table('project_orchestrations')->where('id', $project->id)->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
            ]);
        },
    };

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(RuntimeException::class);

    expect($this->herdr->calls)->toBe([])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['source receipt', 'candidate', 'pull request', 'issue', 'project']);

it('blocks ambiguous Linear and Herdr mutation failures', function (string $failure) {
    if ($failure === 'linear') {
        $this->transitions->fail = true;
    } else {
        $this->herdr->failure = $failure;
    }

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['linear', 'open', 'split', 'start', 'prompt']);

it('resumes the exact existing reviewer after final-verification dispatch interruption', function () {
    interruptPullRequestReviewBeforePrompt($this);

    $result = app(DispatchOrbitPullRequestReview::class)->recoverInterrupted($this->delivery->id);

    expect($result->is($this->dispatch))->toBeTrue()
        ->and($result->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($result->error_code)->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and($this->herdr->prompts)->toHaveCount(1)
        ->and($this->verifier->calls)->toBe(1)
        ->and($this->pullRequests->calls)->toBe(1)
        ->and($this->transitions->calls)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('runs interrupted reviewer recovery directly without queueing another dispatch', function () {
    Queue::fake();
    interruptPullRequestReviewBeforePrompt($this);

    $this->artisan('delivery:recover-orbit-pr-review', [
        'delivery' => (string) $this->delivery->id,
    ])->assertSuccessful();

    Queue::assertNothingPushed();
    expect($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('keeps an interrupted reviewer blocked when its ledger or live agent drifts', function (string $drift) {
    interruptPullRequestReviewBeforePrompt($this);

    match ($drift) {
        'prompt' => $this->dispatch->forceFill(['prompt_hash' => str_repeat('0', 64)])->save(),
        'stage' => $this->delivery->forceFill(['failure_details' => [
            'code' => 'pr_review_dispatch_interrupted',
            'dispatch_id' => $this->dispatch->id,
            'stage' => 'herdr_prompt_attempted',
        ]])->save(),
        'sequence' => $this->herdr->agentSequence = -1,
        'worktree' => $this->herdr->workingDirectory = '/fast/worktrees/orbit/other',
        'status' => $this->herdr->agentStatus = 'working',
        'mergeability' => $this->pullRequests->mergeable = false,
    };

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->recoverInterrupted($this->delivery->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('pr_review_dispatch_interrupted')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Starting)
        ->and($this->herdr->prompts)->toBe([])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['prompt', 'stage', 'sequence', 'worktree', 'status', 'mergeability']);

it('never replays an interrupted reviewer after an ambiguous prompt outcome', function () {
    interruptPullRequestReviewBeforePrompt($this);
    $this->herdr->failure = 'prompt';

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->recoverInterrupted($this->delivery->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'will not submit it again');
    expect(fn () => app(DispatchOrbitPullRequestReview::class)->recoverInterrupted($this->delivery->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class);

    expect($this->herdr->calls)->toBe(['get', 'prompt'])
        ->and($this->herdr->prompts)->toHaveCount(1)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('herdr_prompt_ambiguous')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous);
});

it('rearms the same pull request review dispatch after exact Linear read-back', function () {
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Ambiguous,
        'error_code' => 'linear_pr_review_transition_ambiguous',
        'error_message' => 'The prior transition outcome was unresolved.',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'linear_pr_review_transition_ambiguous',
            'dispatch_id' => $this->dispatch->id,
            'message' => 'The prior transition outcome was unresolved.',
        ],
    ])->save();
    addKnownPullRequestAttachment($this);
    $payload = $this->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $payload['assignee'] = null;
    $this->issues->snapshot = new OrbitIssueSnapshot(
        $this->issues->snapshot->issueId,
        $this->issues->snapshot->issueKey,
        $payload,
        $this->issues->snapshot->contractHash,
    );

    $phase = app(RecoverOrbitPullRequestReviewTransition::class)->handle($this->delivery->id);

    expect($phase->is($this->review))->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->dispatch->fresh()->error_code)->toBeNull()
        ->and(PhaseRun::query()->count())->toBe(4)
        ->and(AgentDispatch::query()->count())->toBe(4)
        ->and($this->transitions->calls)->toBe(0)
        ->and($this->herdr->calls)->toBe([]);
});

it('completes a partial In Review transition before rearming the reviewer', function () {
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Ambiguous,
        'error_code' => 'linear_pr_review_transition_ambiguous',
        'error_message' => 'The prior transition outcome was unresolved.',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'linear_pr_review_transition_ambiguous',
            'dispatch_id' => $this->dispatch->id,
            'message' => 'The prior transition outcome was unresolved.',
        ],
    ])->save();
    addKnownPullRequestAttachment($this);
    $payload = $this->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $this->issues->snapshot = new OrbitIssueSnapshot(
        $this->issues->snapshot->issueId,
        $this->issues->snapshot->issueKey,
        $payload,
        $this->issues->snapshot->contractHash,
    );

    $phase = app(RecoverOrbitPullRequestReviewTransition::class)->handle($this->delivery->id);

    expect($phase->is($this->review))->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->dispatch->fresh()->error_code)->toBeNull()
        ->and($this->transitions->calls)->toBe(1)
        ->and($this->herdr->calls)->toBe([]);
});

it('keeps an ambiguous pull request review blocked until Linear is exact In Review', function () {
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Ambiguous,
        'error_code' => 'linear_pr_review_transition_ambiguous',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'linear_pr_review_transition_ambiguous',
            'dispatch_id' => $this->dispatch->id,
        ],
    ])->save();

    expect(fn () => app(RecoverOrbitPullRequestReviewTransition::class)->handle($this->delivery->id))
        ->toThrow(
            OrbitPullRequestReviewDispatchFailed::class,
            'Linear does not confirm the exact In Review state and ownership for recovery.',
        );

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and($this->transitions->calls)->toBe(0)
        ->and($this->herdr->calls)->toBe([]);
});

it('queues the retained pull request review after verified recovery', function () {
    Queue::fake();
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Ambiguous,
        'error_code' => 'linear_pr_review_transition_ambiguous',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'linear_pr_review_transition_ambiguous',
            'dispatch_id' => $this->dispatch->id,
        ],
    ])->save();
    $payload = $this->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $payload['assignee'] = null;
    $this->issues->snapshot = new OrbitIssueSnapshot(
        $this->issues->snapshot->issueId,
        $this->issues->snapshot->issueKey,
        $payload,
        $this->issues->snapshot->contractHash,
    );

    $this->artisan('delivery:recover-orbit-pr-review', [
        'delivery' => (string) $this->delivery->id,
    ])->assertSuccessful();

    Queue::assertPushed(DispatchPullRequestReviewJob::class, fn (DispatchPullRequestReviewJob $job): bool => $job->deliveryId === $this->delivery->id && $job->phaseRunId === $this->review->id
    );
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending);
});

it('routes a newly conflicting pull request back to the retained Builder', function () {
    Queue::fake();
    $this->pullRequests->mergeable = false;
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The published pull request became unmergeable before independent review.',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'pr_review_mergeability_changed',
            'dispatch_id' => $this->dispatch->id,
            'message' => 'The published pull request became unmergeable before independent review.',
        ],
    ])->save();
    addKnownPullRequestAttachment($this);
    $payload = $this->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $payload['assignee'] = null;
    $this->issues->snapshot = new OrbitIssueSnapshot(
        $this->issues->snapshot->issueId,
        $this->issues->snapshot->issueKey,
        $payload,
        $this->issues->snapshot->contractHash,
    );

    $this->artisan('delivery:recover-orbit-pr-review', [
        'delivery' => (string) $this->delivery->id,
    ])->assertSuccessful();

    $correction = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->where('attempt', 2)
        ->sole();
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($correction->status)->toBe(PhaseRunStatus::Pending)
        ->and($correction->input['pull_request']['mergeable'])->toBeFalse()
        ->and($correction->agentDispatches()->sole()->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and(app(OrbitImplementationReceiptValidator::class)->matchesInput(
            $this->delivery->fresh(),
            $correction,
        ))->toBeTrue()
        ->and($this->transitions->progressCalls)->toBe(1);
    Queue::assertPushed(
        DispatchImplementationJob::class,
        fn (DispatchImplementationJob $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('recovers one Builder correction after crashing following the In Progress transition', function () {
    $this->pullRequests->mergeable = false;
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The published pull request became unmergeable before independent review.',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'pr_review_mergeability_changed',
            'dispatch_id' => $this->dispatch->id,
            'message' => 'The published pull request became unmergeable before independent review.',
        ],
    ])->save();
    addKnownPullRequestAttachment($this);
    $payload = $this->issues->snapshot->payload;
    $payload['state'] = ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started'];
    $payload['assignee'] = null;
    $this->issues->snapshot = new OrbitIssueSnapshot(
        $this->issues->snapshot->issueId,
        $this->issues->snapshot->issueKey,
        $payload,
        $this->issues->snapshot->contractHash,
    );
    $this->transitions->afterProgressTransition = function (OrbitIssueSnapshot $transitioned): never {
        $this->issues->snapshot = $transitioned;

        throw new RuntimeException('The process crashed after the Linear transition.');
    };

    expect(fn () => app(RecoverOrbitPullRequestReviewTransition::class)->handle($this->delivery->id))
        ->toThrow(RuntimeException::class, 'crashed after the Linear transition');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PR_REVIEW_PHASE)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->transitions->progressCalls)->toBe(1)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 2)
            ->doesntExist())->toBeTrue();

    $correction = app(RecoverOrbitPullRequestReviewTransition::class)->handle($this->delivery->id);

    expect($correction->phase_name)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($correction->attempt)->toBe(2)
        ->and($correction->status)->toBe(PhaseRunStatus::Pending)
        ->and($correction->input['implementation_receipt_id'])->toBe($this->implementationReceipt->id)
        ->and($correction->input['pull_request']['mergeable'])->toBeFalse()
        ->and($correction->agentDispatches()->sole()->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->transitions->progressCalls)->toBe(1)
        ->and(PhaseRun::query()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->where('attempt', 2)
            ->count())->toBe(1);
});

it('rejects reviewer identity reuse before agent start', function () {
    $this->herdr->reuseBuilder = true;

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'not independent');

    expect($this->herdr->calls)->toBe(['open', 'split'])
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous);
});

it('preserves a settlement that arrives before the prompt returns', function () {
    $this->herdr->beforePromptReturn = function (): void {
        DB::table('agent_dispatches')->where('id', $this->dispatch->id)->update([
            'status' => AgentDispatchStatus::Settled->value,
            'settled_at' => now(),
        ]);
    };

    $result = app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id);

    expect($result->status)->toBe(AgentDispatchStatus::Settled)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->herdr->prompts)->toHaveCount(1);
});

it('does not start a reviewer when another process wins the dispatch claim', function () {
    $this->transitions->afterTransition = function (): void {
        DB::table('agent_dispatches')->where('id', $this->dispatch->id)->update([
            'status' => AgentDispatchStatus::Starting->value,
        ]);
    };

    expect(fn () => app(DispatchOrbitPullRequestReview::class)->handle($this->delivery->id, $this->review->id))
        ->toThrow(OrbitPullRequestReviewDispatchFailed::class, 'already claimed');

    expect($this->herdr->calls)->toBe([])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('uses a phase-scoped queue lock and only fails its exact active phase', function () {
    expect(DispatchPullRequestReviewJob::TIMEOUT_SECONDS)->toBeLessThan(600)
        ->and(DispatchPullRequestReviewJob::LOCK_SECONDS)->toBeGreaterThan(DispatchPullRequestReviewJob::TIMEOUT_SECONDS);

    $job = new DispatchPullRequestReviewJob($this->delivery->id, $this->review->id + 1);
    $job->failed(new RuntimeException('stale failure'));
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued);

    $active = new DispatchPullRequestReviewJob($this->delivery->id, $this->review->id);
    $active->failed(new RuntimeException('active failure'));
    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('pr_review_dispatch_exhausted');

    $lock = Cache::lock(
        "delivery:pr-review-dispatch:{$this->delivery->id}:{$this->review->id}",
        DispatchPullRequestReviewJob::LOCK_SECONDS,
    );
    expect($lock->get())->toBeTrue();
    $lock->release();
});
