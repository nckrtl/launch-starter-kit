<?php

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitPlanning;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitPlanReview;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
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
use App\Delivery\Exceptions\OrbitPlanningAdvancementFailed;
use App\Delivery\Exceptions\OrbitPlanReviewDispatchFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanReviewReceiptValidator;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class PlanningAdvancementRepository implements OrbitRepository
{
    public int $reservations = 0;

    public int $verifications = 0;

    public ?VerifiedOrbitPlanningOutcome $outcome = null;

    public ?Closure $beforeVerificationReturn = null;

    public mixed $reservationHandle = null;

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        $this->reservations++;
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not reserve the planning advancement test.');
        }

        $this->reservationHandle = $handle;

        return new OrbitDeliveryReservation($handle, 'planning-advancement.lock');
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
        $this->verifications++;

        if ($this->beforeVerificationReturn instanceof Closure) {
            ($this->beforeVerificationReturn)();
        }

        return $this->outcome
            ?? throw new RuntimeException('No planning outcome was configured.');
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

final class PlanningAdvancementIssueProvider implements OrbitIssueProvider
{
    public int $fetches = 0;

    public function __construct(private readonly OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->fetches++;

        return $this->snapshot;
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-planning-advance-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->startupSha = str_repeat('a', 40);
    $this->candidateSha = str_repeat('b', 40);
    $this->treeSha = str_repeat('c', 40);
    $this->artifactSha = str_repeat('d', 40);
    $this->planHash = str_repeat('e', 64);
    $this->contractHash = str_repeat('d', 64);
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot(
            '11111111-2222-4333-8444-555555555555',
            'ORB-234',
            $this->worktree.'/.loop/issue.json',
        ),
        $this->worktree,
        new CandidateCheck('/home/nckrtl/orbit/.git/orbit-checks/'.$this->startupSha.'/startup/result.json', $this->startupSha, str_repeat('9', 40)),
    );
    $this->delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();
    $this->phase = PhaseRun::sole();
    $this->phase->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->planner = AgentDispatch::query()->create([
        'phase_run_id' => $this->phase->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'planning-advance-planner',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('8', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $this->repository = new PlanningAdvancementRepository;
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        $this->artifactSha,
        $this->planHash,
    );
    $issue = new OrbitIssueSnapshot(
        '11111111-2222-4333-8444-555555555555',
        'ORB-234',
        [
            'id' => '11111111-2222-4333-8444-555555555555',
            'identifier' => 'ORB-234',
            'state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'In Progress', 'type' => 'started'],
        ],
        $this->contractHash,
    );
    $this->issues = new PlanningAdvancementIssueProvider($issue);
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitIssueProvider::class, $this->issues);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function capturePlanningAdvancementReceipt(object $test, string $result = 'ready'): Receipt
{
    $ready = $result === 'ready';
    $correction = $test->phase->attempt === 2;
    $payload = [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $test->planner->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => $test->phase->attempt,
        'result' => $result,
        'worktree' => $test->worktree,
        'candidate_sha' => $test->candidateSha,
        'handoff_path' => $correction
            ? '.loop/runtime/planning-correction-handoff.md'
            : '.loop/runtime/planning-handoff.md',
        'handoff' => $ready
            ? ($correction ? 'The review findings were corrected.' : 'Planning is ready for independent review.')
            : 'Planning stopped on a missing product decision.',
        'artifact_sha' => $ready ? $test->artifactSha : null,
        'plan_sha256' => $ready ? $test->planHash : null,
    ];

    return Receipt::query()->create([
        'phase_run_id' => $test->phase->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => $test->candidateSha,
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
}

/** @return array{Receipt, PhaseRun} */
function preparePlanningCorrectionAdvancement(object $test): array
{
    $planningReceipt = capturePlanningAdvancementReceipt($test);
    $test->phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $planningReceipt->id, 'result' => 'ready'],
        'finished_at' => now(),
    ])->save();
    $review = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningReceipt->payload,
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $reviewer = AgentDispatch::query()->create([
        'phase_run_id' => $review->id,
        'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'planning-correction-reviewer',
        'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('7', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $reviewPayload = [
        'kind' => 'orbit_plan_review',
        'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $reviewer->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'result' => 'fix',
        'worktree' => $test->worktree,
        'candidate_sha' => $test->candidateSha,
        'handoff_path' => '.loop/runtime/plan-review-handoff.md',
        'handoff' => 'Correct every preflight finding.',
        'artifact_sha' => str_repeat('f', 40),
        'plan_sha256' => str_repeat('6', 64),
    ];
    $reviewReceipt = Receipt::query()->create([
        'phase_run_id' => $review->id,
        'kind' => 'orbit_plan_review',
        'schema_version' => 1,
        'payload' => $reviewPayload,
        'payload_hash' => hash('sha256', json_encode($reviewPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => $test->candidateSha,
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $review->forceFill(['output' => ['receipt_id' => $reviewReceipt->id, 'result' => 'fix']])->save();
    $correction = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'plan_review_receipt_id' => $reviewReceipt->id,
            'plan_review_receipt' => $reviewReceipt->payload,
        ],
        'started_at' => now(),
    ]);
    $planner = AgentDispatch::query()->create([
        'phase_run_id' => $correction->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'planning-correction-planner',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning_correction',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('8', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $test->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'candidate_sha' => $test->candidateSha,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $test->phase = $correction;
    $test->planner = $planner;

    return [$reviewReceipt, $correction];
}

it('atomically consumes a ready planning receipt into one independent plan-review intent', function () {
    $receipt = capturePlanningAdvancementReceipt($this);
    $job = new AdvanceDelivery($this->delivery->id);

    $job->handle(app(AdvanceDeliveryAction::class));
    $job->handle(app(AdvanceDeliveryAction::class));

    $delivery = $this->delivery->fresh();
    $planning = PhaseRun::where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)->sole();
    $review = PhaseRun::where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)->sole();
    $reviewer = $review->agentDispatches()->sole();

    expect($delivery->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and($delivery->status)->toBe(DeliveryStatus::Queued)
        ->and($delivery->candidate_sha)->toBe($this->candidateSha)
        ->and($planning->status)->toBe(PhaseRunStatus::Completed)
        ->and($planning->output)->toBe(['receipt_id' => $receipt->id, 'result' => 'ready'])
        ->and($review->status)->toBe(PhaseRunStatus::Pending)
        ->and($review->attempt)->toBe(1)
        ->and($review->input)->toBe([
            'planning_receipt_id' => $receipt->id,
            'planning_receipt' => $receipt->payload,
        ])
        ->and($reviewer->agent_role)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)
        ->and($reviewer->herdr_agent_name)->toBe('orb-234-loop-plan-review')
        ->and($reviewer->herdr_agent_name)->not->toBe($this->planner->herdr_agent_name)
        ->and($reviewer->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::count())->toBe(2)
        ->and(AgentDispatch::count())->toBe(2)
        ->and($this->repository->reservations)->toBe(1)
        ->and($this->repository->verifications)->toBe(1)
        ->and($this->issues->fetches)->toBe(1)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('waits harmlessly for receipt and settlement in either order', function () {
    $this->planner->forceFill(['status' => AgentDispatchStatus::Waiting, 'settled_at' => null])->save();
    capturePlanningAdvancementReceipt($this);
    $action = app(AdvanceDeliveryAction::class);

    expect($action->handle($this->delivery->id))->toBeFalse();
    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($this->repository->reservations)->toBe(0);

    $this->planner->forceFill(['status' => AgentDispatchStatus::Settled, 'settled_at' => now()])->save();
    expect($action->handle($this->delivery->id))->toBeTrue();
    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE);
});

it('waits harmlessly when settlement arrives before its receipt', function () {
    $action = app(AdvanceDeliveryAction::class);

    expect($action->handle($this->delivery->id))->toBeFalse();
    expect($this->repository->reservations)->toBe(0);

    capturePlanningAdvancementReceipt($this);
    expect($action->handle($this->delivery->id))->toBeTrue();
    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE);
});

it('records a blocked planning handoff without creating a reviewer', function () {
    $receipt = capturePlanningAdvancementReceipt($this, 'blocked');
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        null,
        null,
    );

    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);
    app(AdvanceDeliveryAction::class)->handle($this->delivery->id);

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->candidate_sha)->toBe($this->candidateSha)
        ->and($this->delivery->fresh()->failure_details)->toMatchArray([
            'code' => 'planning_blocked',
            'receipt_id' => $receipt->id,
            'handoff' => 'Planning stopped on a missing product decision.',
        ])
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($this->phase->fresh()->failure_code)->toBe('planning_blocked')
        ->and(PhaseRun::count())->toBe(1)
        ->and(AgentDispatch::count())->toBe(1)
        ->and($this->repository->verifications)->toBe(1);
});

it('routes a ready planning correction to plan review attempt two', function () {
    [$fixReceipt, $correction] = preparePlanningCorrectionAdvancement($this);
    $this->candidateSha = str_repeat('1', 40);
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        $this->artifactSha,
        $this->planHash,
    );
    $receipt = capturePlanningAdvancementReceipt($this);
    $action = app(AdvanceOrbitPlanning::class);

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeTrue()
        ->and($action->handle($this->delivery->id))->toBeFalse();

    $review = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->where('attempt', 2)
        ->sole();
    $reviewer = $review->agentDispatches()->sole();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->candidate_sha)->toBe($this->candidateSha)
        ->and($correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($correction->fresh()->output)->toBe(['receipt_id' => $receipt->id, 'result' => 'ready'])
        ->and($correction->input)->toBe([
            'plan_review_receipt_id' => $fixReceipt->id,
            'plan_review_receipt' => $fixReceipt->payload,
        ])
        ->and($review->input)->toBe([
            'planning_receipt_id' => $receipt->id,
            'planning_receipt' => $receipt->payload,
        ])
        ->and($reviewer->agent_role)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)
        ->and($reviewer->herdr_agent_name)->toBe('orb-234-loop-plan-review-2')
        ->and($reviewer->status)->toBe(AgentDispatchStatus::Pending)
        ->and(app(OrbitPlanReviewReceiptValidator::class)->matchesInput(
            $this->delivery->fresh(),
            $review,
        ))->toBeTrue()
        ->and(PhaseRun::count())->toBe(4)
        ->and(AgentDispatch::count())->toBe(4)
        ->and($this->repository->verifications)->toBe(1);
});

it('routes a blocked planning correction to resolution with complete provenance', function () {
    [$fixReceipt, $correction] = preparePlanningCorrectionAdvancement($this);
    $receipt = capturePlanningAdvancementReceipt($this, 'blocked');
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        null,
        null,
    );
    $action = app(AdvanceOrbitPlanning::class);

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeTrue()
        ->and($action->handle($this->delivery->id))->toBeFalse();

    $resolution = PhaseRun::query()
        ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->where('attempt', 1)
        ->sole();
    $resolver = $resolution->agentDispatches()->sole();

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::RESOLUTION_PHASE)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($correction->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($correction->fresh()->output)->toBe(['receipt_id' => $receipt->id, 'result' => 'blocked'])
        ->and($resolution->input)->toBe([
            'planning_receipt_id' => $receipt->id,
            'planning_receipt' => $receipt->payload,
            'plan_review_receipt_id' => $fixReceipt->id,
            'plan_review_receipt' => $fixReceipt->payload,
        ])
        ->and($resolver->agent_role)->toBe(OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE)
        ->and($resolver->herdr_agent_name)->toBe('orb-234-loop-resolution-1')
        ->and($resolver->prompt_name)->toBe('orbit_resolution')
        ->and($resolver->status)->toBe(AgentDispatchStatus::Pending)
        ->and(PhaseRun::count())->toBe(4)
        ->and(AgentDispatch::count())->toBe(4)
        ->and($this->repository->verifications)->toBe(1);
});

it('rejects changed correction provenance before consuming its receipt', function () {
    preparePlanningCorrectionAdvancement($this);
    capturePlanningAdvancementReceipt($this);
    $input = $this->phase->input;
    $input['plan_review_receipt']['handoff'] = 'Changed review findings.';
    $this->phase->forceFill(['input' => $input])->save();

    expect(fn () => app(AdvanceOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningAdvancementFailed::class, 'correction provenance is inconsistent');

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->repository->verifications)->toBe(0);
});

it('refuses a repository outcome that does not match the immutable receipt', function () {
    capturePlanningAdvancementReceipt($this);
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        $this->artifactSha,
        str_repeat('0', 64),
    );

    expect(fn () => app(AdvanceDeliveryAction::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningAdvancementFailed::class, 'does not match its receipt');

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and(PhaseRun::count())->toBe(1)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rejects inconsistent retained review intent instead of creating another attempt', function () {
    capturePlanningAdvancementReceipt($this);
    $action = app(AdvanceDeliveryAction::class);
    $action->handle($this->delivery->id);
    AgentDispatch::where('agent_role', OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE)
        ->sole()
        ->forceFill(['prompt_name' => 'wrong'])
        ->save();

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'retained Orbit plan-review intent is inconsistent');

    expect(PhaseRun::count())->toBe(2)
        ->and(AgentDispatch::count())->toBe(2);
});

it('rejects a retained review intent whose embedded receipt changed', function () {
    capturePlanningAdvancementReceipt($this);
    $action = app(AdvanceDeliveryAction::class);
    $action->handle($this->delivery->id);
    $review = PhaseRun::where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)->sole();
    $input = $review->input;
    $input['planning_receipt']['handoff'] = 'Changed after the transition.';
    $review->forceFill(['input' => $input])->save();

    expect(fn () => app(DispatchOrbitPlanReview::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanReviewDispatchFailed::class, 'immutable planning receipt');

    expect(PhaseRun::count())->toBe(2)
        ->and(AgentDispatch::count())->toBe(2);
});

it('rejects a current issue whose identity no longer matches the delivery', function () {
    capturePlanningAdvancementReceipt($this);
    app()->instance(OrbitIssueProvider::class, new PlanningAdvancementIssueProvider(new OrbitIssueSnapshot(
        '99999999-2222-4333-8444-555555555555',
        'ORB-234',
        [
            'id' => '99999999-2222-4333-8444-555555555555',
            'identifier' => 'ORB-234',
            'state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'In Progress', 'type' => 'started'],
        ],
        $this->contractHash,
    )));

    expect(fn () => app(AdvanceDeliveryAction::class)->handle($this->delivery->id))
        ->toThrow(OrbitIssueContractChanged::class, 'changed before plan-review advancement');

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($this->repository->verifications)->toBe(0)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rejects a project config change during external verification', function () {
    capturePlanningAdvancementReceipt($this);
    $this->repository->beforeVerificationReturn = function (): void {
        app(ConfigureProjectOrchestration::class)->handle('orbit', [
            'type' => 'orbit',
            'repository' => '/home/nckrtl/orbit',
            'worktreeRoot' => '/fast/worktrees/orbit',
            'herdrSession' => 'orbit',
            'concurrency' => 2,
            'defaultFlow' => 'discovery',
        ]);
    };

    expect(fn () => app(AdvanceDeliveryAction::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningAdvancementFailed::class, 'planning ledger changed during advancement');

    expect($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::INITIAL_PHASE)
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and(PhaseRun::count())->toBe(1)
        ->and($this->repository->reservationIsHeld())->toBeFalse();
});

it('rejects inconsistent retained blocked evidence', function () {
    capturePlanningAdvancementReceipt($this, 'blocked');
    $this->repository->outcome = new VerifiedOrbitPlanningOutcome(
        $this->candidateSha,
        $this->treeSha,
        null,
        null,
    );
    $action = app(AdvanceDeliveryAction::class);
    $action->handle($this->delivery->id);
    $failure = $this->delivery->fresh()->failure_details;
    $failure['handoff'] = 'Changed after the transition.';
    $this->delivery->forceFill(['failure_details' => $failure])->save();

    expect(fn () => $action->handle($this->delivery->id))
        ->toThrow(OrbitPlanningAdvancementFailed::class, 'retained blocked planning transition is inconsistent');

    expect(PhaseRun::count())->toBe(1)
        ->and(AgentDispatch::count())->toBe(1);
});
