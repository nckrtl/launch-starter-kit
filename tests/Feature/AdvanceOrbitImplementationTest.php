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
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceOrbitImplementation as AdvanceImplementationJob;
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
            ->and($reviewedCandidateSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('c', 40));
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

    public function publish(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest {
        expect(DB::transactionLevel())->toBe($this->transactionLevel)
            ->and($issueKey)->toBe('ORB-234')
            ->and($issueTitle)->toBe('Build the feature')
            ->and($candidateSha)->toBe(str_repeat('b', 40));
        $this->calls++;

        if ($this->afterPublish instanceof Closure) {
            ($this->afterPublish)();
        }

        return new PublishedOrbitPullRequest(
            number: 42,
            url: 'https://github.com/nckrtl/orbit/pull/42',
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

it('queues the dedicated implementation advancement job', function () {
    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();
    Queue::assertPushed(AdvanceImplementationJob::class, 1);
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
    Queue::assertNothingPushed();
});

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
    $job = new AdvanceImplementationJob($this->delivery->id);
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

it('fails only an active implementation when advancement retries are exhausted', function () {
    $job = new AdvanceImplementationJob($this->delivery->id);
    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'implementation_advancement_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('does not fail a queued retained-Builder correction from a stale advancement job', function () {
    $this->pullRequests->mergeable = false;
    app(AdvanceOrbitImplementation::class)->handle($this->delivery->id);

    (new AdvanceImplementationJob($this->delivery->id))->failed(new RuntimeException('Stale queue failure.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
        ->and($this->phase->fresh()->status)->toBe(PhaseRunStatus::Completed);
});

it('releases a contended implementation-advancement lock for retry', function () {
    $lock = Cache::lock(
        "delivery:implementation-advance:{$this->delivery->id}",
        AdvanceImplementationJob::LOCK_SECONDS,
    );
    $lock->get();

    try {
        $job = (new AdvanceImplementationJob($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(AdvanceOrbitImplementation::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});
