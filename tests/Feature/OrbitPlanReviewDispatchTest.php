<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitPlanReview;
use App\Delivery\Actions\CaptureHerdrEvent;
use App\Delivery\Actions\CaptureOrbitPlanReviewReceipt;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitPlanReview;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanReviewAdvancementFailed;
use App\Delivery\Exceptions\OrbitPlanReviewDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitPlanReview as AdvanceOrbitPlanReviewJob;
use App\Jobs\DispatchOrbitPlanReview as DispatchOrbitPlanReviewJob;
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

final class PlanReviewDispatchRepository implements OrbitRepository
{
    public mixed $reservationHandle = null;

    public int $verificationCount = 0;

    public int $artifactVerificationCount = 0;

    public ?Closure $afterReserve = null;

    public ?Closure $afterArtifactVerify = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve the test delivery.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'plan-review-dispatch.lock');
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
        $this->artifactVerificationCount++;

        if ($this->afterArtifactVerify instanceof Closure) {
            $afterArtifactVerify = $this->afterArtifactVerify;
            $this->afterArtifactVerify = null;
            $afterArtifactVerify();
        }

        return new VerifiedOrbitPlanningArtifact($artifactSha, str_repeat('6', 64));
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        $this->verificationCount++;

        expect($startupWorktree->headSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBeIn([str_repeat('d', 40), null]);

        return new VerifiedOrbitPlanningOutcome(
            $candidateSha,
            str_repeat('c', 40),
            $artifactSha,
            $artifactSha === null ? null : str_repeat('e', 64),
        );
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

final class PlanReviewIssueProvider implements OrbitIssueProvider
{
    public function __construct(public OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        return $this->snapshot;
    }
}

final class PlanReviewHerdrRuntime implements HerdrRuntime
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $prompts = [];

    public ?string $failure = null;

    public ?string $crash = null;

    public ?Closure $beforePromptReturn = null;

    public ?HerdrAgentLaunch $launch = null;

    public function __construct(private readonly PlanReviewDispatchRepository $repository) {}

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        $this->call('open');

        return new OpenedHerdrWorktree('workspace-1', 'tab-1', 'root-pane', 'root-terminal', false);
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        $this->call('split');

        return $this->identifiers('');
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        $this->launch = $launch;
        $this->call('start');

        return $this->identifiers($name);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->prompts[] = $prompt;
        $this->call('prompt');

        if (! $this->repository->reservationIsHeld()) {
            throw new RuntimeException('The review reservation was released before prompting.');
        }

        if ($this->beforePromptReturn instanceof Closure) {
            ($this->beforePromptReturn)();
        }

        return $this->identifiers($name, 42);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->call('get');

        return $this->identifiers($name);
    }

    private function call(string $name): void
    {
        $this->calls[] = $name;

        if ($this->crash === $name) {
            throw new Error("{$name} crashed.");
        }

        if ($this->failure === $name) {
            throw new RuntimeException("{$name} failed ambiguously.");
        }
    }

    private function identifiers(string $name, int $sequence = 40): HerdrAgentIdentifiers
    {
        return new HerdrAgentIdentifiers(
            'workspace-1',
            'tab-1',
            'worker-pane',
            'worker-terminal',
            'codex-reviewer-1',
            $name,
            $sequence,
        );
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-plan-review-dispatch-'.bin2hex(random_bytes(4)));
    $projects = $this->base.'/projects';
    File::makeDirectory($projects, 0755, true);
    config()->set('commander.projects_path', $projects);
    config()->set('herdr.session', 'orbit');
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
    $planner = AgentDispatch::query()->create([
        'phase_run_id' => $planning->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'planning-dispatch',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('1', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $this->planningPayload = [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $this->delivery->id,
        'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1,
        'result' => 'ready',
        'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/planning-handoff.md',
        'handoff' => 'Review this complete plan.',
        'artifact_sha' => str_repeat('d', 40),
        'plan_sha256' => str_repeat('e', 64),
    ];
    $this->planningReceipt = Receipt::query()->create([
        'phase_run_id' => $planning->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => $this->planningPayload,
        'payload_hash' => hash('sha256', json_encode($this->planningPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $planning->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $this->planningReceipt->id, 'result' => 'ready'],
        'finished_at' => now(),
    ])->save();
    $this->review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Pending,
        'input' => [
            'planning_receipt_id' => $this->planningReceipt->id,
            'planning_receipt' => $this->planningPayload,
        ],
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->review->id,
        'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
            1,
            OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review',
        'prompt_version' => OrbitFeatureWorkflow::PLAN_REVIEW_PROMPT_VERSION,
        'prompt_hash' => str_repeat('0', 64),
        'status' => AgentDispatchStatus::Pending,
    ]);
    $this->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'candidate_sha' => str_repeat('b', 40),
        'status' => DeliveryStatus::Queued,
    ])->save();
    $payload = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'identifier' => 'ORB-234',
        'state' => ['id' => 'state-1', 'name' => 'In Progress', 'type' => 'started'],
    ];
    $this->repository = new PlanReviewDispatchRepository;
    $this->issues = new PlanReviewIssueProvider(new OrbitIssueSnapshot(
        $payload['id'],
        $payload['identifier'],
        $payload,
        str_repeat('d', 64),
    ));
    $this->herdr = new PlanReviewHerdrRuntime($this->repository);
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitIssueProvider::class, $this->issues);
    app()->instance(HerdrRuntime::class, $this->herdr);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function planReviewReceiptPayload(object $test): array
{
    return [
        'kind' => 'orbit_plan_review',
        'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $test->dispatch->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'result' => 'blocked',
        'worktree' => $test->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/plan-review-handoff.md',
        'handoff' => 'Review is blocked by a process condition.',
        'artifact_sha' => null,
        'plan_sha256' => null,
    ];
}

function capturedPlanReviewResult(object $test, string $result): Receipt
{
    app(DispatchOrbitPlanReview::class)->handle($test->delivery->id);
    $test->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();
    $payload = planReviewReceiptPayload($test);
    $payload['result'] = $result;

    if ($result !== 'blocked') {
        $payload['artifact_sha'] = str_repeat('f', 40);
        $payload['plan_sha256'] = str_repeat('6', 64);
    }

    return app(CaptureOrbitPlanReviewReceipt::class)->handle(
        $test->review->fresh(),
        $test->dispatch->fresh(),
        $payload,
    );
}

it('routes the review phase to its dedicated dispatch job', function () {
    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(DispatchOrbitPlanReviewJob::class, 1);
    Queue::assertNotPushed(AdvanceDelivery::class);
});

it('requeues interrupted review preparation but waits once the reviewer owns the phase', function () {
    $this->delivery->forceFill(['status' => DeliveryStatus::Preparing])->save();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(DispatchOrbitPlanReviewJob::class, 1);

    Queue::fake();
    $this->review->forceFill(['status' => PhaseRunStatus::Running])->save();
    $this->dispatch->forceFill(['status' => AgentDispatchStatus::Waiting])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(AdvanceOrbitPlanReviewJob::class, 1);
});

it('routes verified plan-review results into one durable next-phase intent', function (
    string $result,
    string $phaseName,
    int $attempt,
    string $role,
    string $agent,
    string $prompt,
) {
    $receipt = capturedPlanReviewResult($this, $result);
    $action = app(AdvanceOrbitPlanReview::class);

    $action->handle($this->delivery->id);
    $finishedAt = $this->review->fresh()->finished_at;
    $action->handle($this->delivery->id);

    $next = PhaseRun::query()
        ->where('phase_name', $phaseName)
        ->where('attempt', $attempt)
        ->sole();
    $nextDispatch = $next->agentDispatches()->sole();

    expect($this->delivery->fresh()->current_phase)->toBe($phaseName)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($this->review->fresh()->output)->toBe(['receipt_id' => $receipt->id, 'result' => $result])
        ->and($this->review->fresh()->finished_at?->equalTo($finishedAt))->toBeTrue()
        ->and($next->status)->toBe(PhaseRunStatus::Pending)
        ->and($next->input)->toBe([
            'plan_review_receipt_id' => $receipt->id,
            'plan_review_receipt' => $receipt->payload,
        ])
        ->and($nextDispatch->agent_role)->toBe($role)
        ->and($nextDispatch->herdr_agent_name)->toBe($agent)
        ->and($nextDispatch->prompt_name)->toBe($prompt)
        ->and($nextDispatch->prompt_hash)->toBe(str_repeat('0', 64))
        ->and($nextDispatch->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::count())->toBe(3)
        ->and(AgentDispatch::count())->toBe(3)
        ->and($this->repository->artifactVerificationCount)->toBe($result === 'blocked' ? 0 : 1)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with([
    'pass to implementation' => [
        'pass',
        OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        1,
        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'orb-234-loop-builder',
        'orbit_implementation',
    ],
    'first fix to planning correction' => [
        'fix',
        OrbitFeatureWorkflow::INITIAL_PHASE,
        2,
        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'orb-234-loop-builder',
        'orbit_planning_correction',
    ],
    'blocked to resolution' => [
        'blocked',
        OrbitFeatureWorkflow::RESOLUTION_PHASE,
        1,
        OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        'orb-234-loop-resolution-1',
        'orbit_resolution',
    ],
]);

it('waits for both the review receipt and Herdr settlement in either order', function (string $first) {
    app(DispatchOrbitPlanReview::class)->handle($this->delivery->id);

    if ($first === 'receipt') {
        app(CaptureOrbitPlanReviewReceipt::class)->handle(
            $this->review->fresh(),
            $this->dispatch->fresh(),
            planReviewReceiptPayload($this),
        );
    } else {
        $this->dispatch->forceFill(['status' => AgentDispatchStatus::Settled, 'settled_at' => now()])->save();
    }

    app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and($this->repository->verificationCount)->toBe(2);

    if ($first === 'receipt') {
        $this->dispatch->forceFill(['status' => AgentDispatchStatus::Settled, 'settled_at' => now()])->save();
    } else {
        app(CaptureOrbitPlanReviewReceipt::class)->handle(
            $this->review->fresh(),
            $this->dispatch->fresh(),
            planReviewReceiptPayload($this),
        );
    }

    app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE);
})->with(['receipt', 'settlement']);

it('routes a repeated fixing review to resolution with complete correction provenance', function () {
    $firstReviewReceipt = capturedPlanReviewResult($this, 'fix');
    app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);
    $planning = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
        ->where('attempt', 2)
        ->sole();
    $planner = $planning->agentDispatches()->sole();
    $planner->forceFill(['status' => AgentDispatchStatus::Settled, 'settled_at' => now()])->save();
    $planningPayload = [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $this->delivery->id,
        'dispatch_id' => $planner->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2,
        'result' => 'ready',
        'worktree' => $this->worktree,
        'candidate_sha' => str_repeat('b', 40),
        'handoff_path' => '.loop/runtime/planning-correction-handoff.md',
        'handoff' => 'The review findings were corrected.',
        'artifact_sha' => str_repeat('7', 40),
        'plan_sha256' => str_repeat('8', 64),
    ];
    $planningReceipt = Receipt::query()->create([
        'phase_run_id' => $planning->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => $planningPayload,
        'payload_hash' => hash('sha256', json_encode($planningPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $planning->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $planningReceipt->id, 'result' => 'ready'],
        'started_at' => now(),
        'finished_at' => now(),
    ])->save();
    $review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now(),
    ]);
    $reviewer = AgentDispatch::query()->create([
        'phase_run_id' => $review->id,
        'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => IdempotencyKey::forDispatch(
            $this->delivery->id,
            OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
            2,
            OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        )->value,
        'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('9', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $reviewPayload = [
        ...planReviewReceiptPayload($this),
        'dispatch_id' => $reviewer->id,
        'attempt' => 2,
        'result' => 'fix',
        'artifact_sha' => str_repeat('f', 40),
        'plan_sha256' => str_repeat('6', 64),
    ];
    Receipt::query()->create([
        'phase_run_id' => $review->id,
        'kind' => 'orbit_plan_review',
        'schema_version' => 1,
        'payload' => $reviewPayload,
        'payload_hash' => hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => str_repeat('b', 40),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $this->delivery->refresh()->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();

    app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE);

    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->sole();
    expect($review->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($resolution->input['plan_review_receipt']['result'])->toBe('fix')
        ->and($planning->input)->toBe([
            'plan_review_receipt_id' => $firstReviewReceipt->id,
            'plan_review_receipt' => $firstReviewReceipt->payload,
        ]);
});

it('rejects corruption in a retained post-review intent', function () {
    capturedPlanReviewResult($this, 'pass');
    $action = app(AdvanceOrbitPlanReview::class);
    $action->handle($this->delivery->id);
    $implementation = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->sole();
    $implementation->forceFill(['input' => ['changed' => true]])->save();

    expect(fn () => $action->handle($this->delivery->id))
        ->toThrow(
            OrbitPlanReviewAdvancementFailed::class,
            'retained post-review intent is inconsistent',
        );

    expect(PhaseRun::count())->toBe(3)
        ->and(AgentDispatch::count())->toBe(3);
});

it('keeps concurrent duplicate review routing idempotent after verification', function () {
    capturedPlanReviewResult($this, 'pass');
    $action = app(AdvanceOrbitPlanReview::class);
    $this->repository->afterArtifactVerify = function () use ($action): void {
        $action->handle($this->delivery->id);
    };

    $action->handle($this->delivery->id);

    $implementation = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->sole();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($implementation->status)->toBe(PhaseRunStatus::Pending)
        ->and(PhaseRun::count())->toBe(3)
        ->and(AgentDispatch::count())->toBe(3);
});

it('rejects project and issue changes before routing a review result', function (string $change, string $exception) {
    capturedPlanReviewResult($this, 'pass');

    if ($change === 'config') {
        $this->repository->afterReserve = function (): void {
            $project = $this->delivery->projectOrchestration;
            $config = $project->config;
            $config['concurrency'] = 2;
            $project->forceFill(['config' => $config])->save();
        };
    } else {
        $payload = $this->issues->snapshot->payload;
        $payload['state'] = ['id' => 'state-2', 'name' => 'Todo', 'type' => 'unstarted'];
        $this->issues->snapshot = new OrbitIssueSnapshot(
            $payload['id'],
            $payload['identifier'],
            $payload,
            str_repeat('d', 64),
        );
    }

    expect(fn () => app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow($exception);

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and(PhaseRun::count())->toBe(2)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with([
    'config' => ['config', OrbitPlanReviewAdvancementFailed::class],
    'issue' => ['issue', OrbitIssueContractChanged::class],
]);

it('dispatches exactly one independent reviewer with the complete immutable planning receipt', function () {
    $dispatch = app(DispatchOrbitPlanReview::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_agent_name)->toBe('orb-234-loop-plan-review')
        ->and($dispatch->prompt_hash)->toBe(hash('sha256', $this->herdr->prompts[0]))
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->repository->verificationCount)->toBe(2)
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->herdr->prompts[0])->toContain(
            '/home/nckrtl/orbit/.agents/skills/reviewing-feature-plans/SKILL.md',
            'delivery:submit-orbit-plan-review-receipt 2 2 --result=pass',
            json_encode($this->planningPayload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        )
        ->and($this->herdr->launch?->arguments)->toContain('features.multi_agent=false');

    app(DispatchOrbitPlanReview::class)->handle($this->delivery->id);

    expect($this->herdr->calls)->toBe(['open', 'split', 'start', 'prompt'])
        ->and(AgentDispatch::where('agent_role', OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)->count())->toBe(1);
});

it('reconciles one ambiguous reviewer start without creating a replacement', function () {
    $this->herdr->failure = 'start';

    $dispatch = app(DispatchOrbitPlanReview::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->herdr->calls)->toBe(['open', 'split', 'start', 'get', 'prompt']);
});

it('blocks an ambiguous prompt and never replays it', function () {
    $this->herdr->failure = 'prompt';

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'will not submit it again automatically');

    expect($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->herdr->prompts)->toHaveCount(1);

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'not eligible');

    expect($this->herdr->prompts)->toHaveCount(1);
});

it('blocks an interrupted reviewer startup without replaying external work', function () {
    $this->herdr->crash = 'open';

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(Error::class, 'open crashed');

    $this->herdr->crash = null;

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'manual recovery is required');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toMatchArray([
            'code' => 'plan_review_dispatch_interrupted',
            'dispatch_id' => $this->dispatch->id,
            'stage' => 'plan_review_dispatch_starting',
        ])
        ->and($this->herdr->calls)->toBe(['open']);
});

it('stops before external mutation when the immutable planning receipt changes after reservation', function () {
    $this->repository->afterReserve = function (): void {
        $payload = $this->planningReceipt->payload;
        $payload['handoff'] = 'Tampered after reservation.';
        DB::table('receipts')->where('id', $this->planningReceipt->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    };

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'immutable planning receipt');

    expect($this->herdr->calls)->toBe([])
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rechecks project configuration and candidate identity after taking the controller reservation', function (string $change) {
    $this->repository->afterReserve = function () use ($change): void {
        if ($change === 'config') {
            $project = $this->delivery->projectOrchestration;
            $config = $project->config;
            $config['concurrency'] = 2;
            $project->forceFill(['config' => $config])->save();

            return;
        }

        $this->delivery->forceFill(['candidate_sha' => str_repeat('f', 40)])->save();
    };

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class);

    expect($this->herdr->calls)->toBe([])
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
})->with(['config', 'candidate']);

it('requires the unchanged In Progress issue contract before creating Herdr state', function (string $change) {
    $payload = $this->issues->snapshot->payload;

    if ($change === 'state') {
        $payload['state'] = ['id' => 'state-2', 'name' => 'Todo', 'type' => 'unstarted'];
    }

    $this->issues->snapshot = new OrbitIssueSnapshot(
        $payload['id'],
        $payload['identifier'],
        $payload,
        $change === 'contract' ? str_repeat('f', 64) : str_repeat('d', 64),
    );

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitIssueContractChanged::class);

    expect($this->herdr->calls)->toBe([])
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Pending);
})->with(['state', 'contract']);

it('keeps duplicate review-dispatch jobs idempotent', function () {
    (new DispatchOrbitPlanReviewJob($this->delivery->id))->handle(app(DispatchOrbitPlanReview::class));
    (new DispatchOrbitPlanReviewJob($this->delivery->id))->handle(app(DispatchOrbitPlanReview::class));

    expect($this->herdr->calls)->toBe(['open', 'split', 'start', 'prompt'])
        ->and(AgentDispatch::where('agent_role', OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)->count())->toBe(1);
});

it('preserves receipt and settlement races while the reviewer prompt returns', function () {
    config()->set('herdr.orchestration.enabled', true);
    $this->herdr->beforePromptReturn = function (): void {
        $dispatch = $this->dispatch->fresh();

        app(CaptureHerdrEvent::class)->handle([
            'event' => 'pane.agent_status_changed',
            'data' => [
                'pane_id' => $dispatch->herdr_pane_id,
                'workspace_id' => $dispatch->herdr_workspace_id,
                'agent_status' => 'done',
                'state_change_seq' => $dispatch->state_change_seq + 1,
            ],
        ]);
        app(CaptureOrbitPlanReviewReceipt::class)->handle(
            $this->review->fresh(),
            $dispatch,
            planReviewReceiptPayload($this),
        );
        app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);
    };

    $dispatch = app(DispatchOrbitPlanReview::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->error_code)->toBeNull()
        ->and(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(1)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Completed);
    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('rejects a changed source review receipt before routing', function (string $change) {
    $receipt = capturedPlanReviewResult($this, 'pass');

    if ($change === 'payload') {
        $payload = $receipt->payload;
        $payload['handoff'] = 'Changed after validation.';
        DB::table('receipts')->where('id', $receipt->id)->update([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    } elseif ($change === 'hash') {
        DB::table('receipts')->where('id', $receipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]);
    } else {
        DB::table('receipts')->where('id', $receipt->id)->update([
            'candidate_sha' => str_repeat('0', 40),
        ]);
    }

    expect(fn () => app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewAdvancementFailed::class, 'does not match');

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and($this->review->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and(PhaseRun::count())->toBe(2);
})->with(['payload', 'hash', 'candidate']);

it('uses a bounded review-advancement job and preserves completed routing on failure', function () {
    capturedPlanReviewResult($this, 'pass');
    app(AdvanceOrbitPlanReview::class)->handle($this->delivery->id);
    $job = new AdvanceOrbitPlanReviewJob($this->delivery->id);

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBe(AdvanceOrbitPlanReviewJob::TIMEOUT_SECONDS)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvanceOrbitPlanReviewJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->failure_details)->toBeNull();
});

it('queues delivery continuation after plan review routing completes', function () {
    capturedPlanReviewResult($this, 'pass');

    (new AdvanceOrbitPlanReviewJob($this->delivery->id))
        ->handle(app(AdvanceOrbitPlanReview::class));

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE);
    Queue::assertPushed(
        AdvanceDelivery::class,
        fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id,
    );
});

it('preserves a blocked review state when review advancement exhausts its retries', function () {
    $job = new AdvanceOrbitPlanReviewJob($this->delivery->id);
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => ['code' => 'manual_review_recovery'],
    ])->save();

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe(['code' => 'manual_review_recovery']);
});

it('fails an active review when review advancement exhausts its retries', function () {
    $job = new AdvanceOrbitPlanReviewJob($this->delivery->id);
    $this->review->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failed_at)->not->toBeNull()
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'plan_review_advancement_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('releases a contended review-advancement lock for retry', function () {
    $lock = Cache::lock(
        "delivery:plan-review-advance:{$this->delivery->id}",
        AdvanceOrbitPlanReviewJob::LOCK_SECONDS,
    );
    $lock->get();

    try {
        $job = (new AdvanceOrbitPlanReviewJob($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitPlanReview::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('uses a bounded review job and preserves a specific blocked recovery state on failure', function () {
    $job = new DispatchOrbitPlanReviewJob($this->delivery->id);
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => ['code' => 'herdr_prompt_ambiguous'],
    ])->save();

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toBe(['code' => 'herdr_prompt_ambiguous']);
});

it('releases a contended review-dispatch lock for retry', function () {
    $lock = Cache::lock("delivery:plan-review-dispatch:{$this->delivery->id}", 270);
    $lock->get();

    try {
        $job = (new DispatchOrbitPlanReviewJob($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(DispatchOrbitPlanReview::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});
