<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ImplementationReceiptRepository implements OrbitImplementationRepository
{
    public int $verificationCount = 0;

    public ?string $failure = null;

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
        $this->verificationCount++;

        if ($this->failure !== null) {
            throw new OrbitRepositoryFailed($this->failure);
        }

        expect($startupWorktree->headSha)->toBe(str_repeat('a', 40))
            ->and($snapshot->issueKey)->toBe('ORB-234')
            ->and($reviewedCandidateSha)->toBe(str_repeat('a', 40))
            ->and($candidateSha)->toBe(str_repeat('b', 40))
            ->and($artifactSha)->toBe(str_repeat('d', 40))
            ->and($pullRequestBody)->toContain('Issue: ORB-234');

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: str_repeat('c', 40),
            artifactSha: $artifactSha,
            gateReceiptPath: $gateReceiptPath,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }
}

beforeEach(function () {
    $this->originalDirectory = getcwd();
    $this->base = storage_path('framework/testing/orbit-implementation-receipt-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->reviewedSha = str_repeat('a', 40);
    $this->candidateSha = str_repeat('b', 40);
    $this->treeSha = str_repeat('c', 40);
    $this->artifactSha = str_repeat('d', 40);
    $this->planningArtifactSha = str_repeat('e', 40);
    $this->reviewArtifactSha = str_repeat('f', 40);
    $this->handoffPath = $this->worktreePath.'/.loop/runtime/implementation-handoff.md';
    $this->bodyPath = $this->worktreePath.'/.loop/runtime/pull-request-body.md';
    $this->gatePath = $this->repositoryPath.'/.git/orbit-checks/'.$this->candidateSha.'/review/result.json';

    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory(dirname($this->gatePath), 0755, true);
    File::makeDirectory(dirname($this->handoffPath), 0755, true);
    File::put($this->handoffPath, "Implementation is ready for publication.\n");
    File::put($this->bodyPath, implode("\n", [
        'Issue: ORB-234',
        'Flow: discovery',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Builder gate: passed ('.$this->gatePath.')',
    ])."\n");
    File::put($this->gatePath, "{}\n");

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
            $this->treeSha,
        ),
    );

    $this->planning = PhaseRun::sole();
    $planner = implementationReceiptDispatch(
        $this->planning,
        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'orbit-implementation-planning',
        'orbit_planning',
    );
    $planningPayload = implementationPlanningPayload($this, $planner, 1);
    $planningReceipt = implementationStoredReceipt($this->planning, 'orbit_planning', $planningPayload);
    implementationCompletePhase($this->planning, $planningReceipt, 'ready');

    $this->review = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $reviewer = implementationReceiptDispatch(
        $this->review,
        OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'orbit-implementation-plan-review',
        'orbit_plan_review',
    );
    $reviewPayload = implementationReviewPayload($this, $reviewer, 1, 'pass');
    $this->reviewReceipt = implementationStoredReceipt($this->review, 'orbit_plan_review', $reviewPayload);
    implementationCompletePhase($this->review, $this->reviewReceipt, 'pass');

    $this->phaseRun = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'plan_review_receipt_id' => $this->reviewReceipt->id,
            'plan_review_receipt' => $reviewPayload,
        ],
        'started_at' => now(),
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->phaseRun->id,
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'idempotency_key' => 'orbit-implementation-receipt',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('1', 64),
        'status' => AgentDispatchStatus::Waiting,
    ]);
    $this->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $this->arguments = [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => (string) $this->dispatch->id,
        '--result' => 'ready',
        '--handoff' => '.loop/runtime/implementation-handoff.md',
        '--artifact' => $this->artifactSha,
        '--gate' => $this->gatePath,
        '--body' => '.loop/runtime/pull-request-body.md',
    ];
    $this->repository = new ImplementationReceiptRepository;
    app()->instance(OrbitImplementationRepository::class, $this->repository);

    chdir($this->worktreePath);
    Queue::fake();
    Process::fake(function ($process) {
        if ($process->command !== ['git', 'rev-parse', 'HEAD']) {
            throw new RuntimeException('Unexpected Orbit implementation receipt command.');
        }

        return Process::result(output: $this->candidateSha."\n");
    })->preventStrayProcesses();
});

afterEach(function () {
    if (is_string($this->originalDirectory)) {
        chdir($this->originalDirectory);
    }

    File::deleteDirectory($this->base);
});

function implementationReceiptDispatch(
    PhaseRun $phase,
    string $role,
    string $key,
    string $prompt,
): AgentDispatch {
    return AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $role,
        'idempotency_key' => $key,
        'herdr_agent_name' => $role === OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            ? 'orb-234-loop-plan-review'
            : 'orb-234-loop-builder',
        'prompt_name' => $prompt,
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('2', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function implementationPlanningPayload(object $test, AgentDispatch $dispatch, int $attempt): array
{
    return [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $dispatch->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => $attempt,
        'result' => 'ready',
        'worktree' => $test->worktreePath,
        'candidate_sha' => $test->reviewedSha,
        'handoff_path' => '.loop/runtime/planning-handoff.md',
        'handoff' => 'Planning is ready.',
        'artifact_sha' => $test->planningArtifactSha,
        'plan_sha256' => str_repeat('3', 64),
    ];
}

/** @return array<string, mixed> */
function implementationReviewPayload(
    object $test,
    AgentDispatch $dispatch,
    int $attempt,
    string $result,
): array {
    return [
        'kind' => 'orbit_plan_review',
        'schema_version' => 1,
        'delivery_id' => $test->delivery->id,
        'dispatch_id' => $dispatch->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => $attempt,
        'result' => $result,
        'worktree' => $test->worktreePath,
        'candidate_sha' => $test->reviewedSha,
        'handoff_path' => '.loop/runtime/plan-review-handoff.md',
        'handoff' => 'The plan was independently reviewed.',
        'artifact_sha' => $test->reviewArtifactSha,
        'plan_sha256' => str_repeat('4', 64),
    ];
}

/** @param array<string, mixed> $payload */
function implementationStoredReceipt(PhaseRun $phase, string $kind, array $payload): Receipt
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

function implementationCompletePhase(PhaseRun $phase, Receipt $receipt, string $result): void
{
    $phase->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $receipt->id, 'result' => $result],
        'started_at' => $phase->started_at ?? now()->subMinute(),
        'finished_at' => now(),
    ])->save();
}

function implementationPromoteToSecondReview(object $test): void
{
    $firstReviewPayload = [...$test->reviewReceipt->payload, 'result' => 'fix'];
    DB::table('receipts')->where('id', $test->reviewReceipt->id)->update([
        'payload' => json_encode($firstReviewPayload, JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', json_encode($firstReviewPayload, JSON_THROW_ON_ERROR)),
    ]);
    $test->reviewReceipt->refresh();
    implementationCompletePhase($test->review, $test->reviewReceipt, 'fix');

    $planning = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'plan_review_receipt_id' => $test->reviewReceipt->id,
            'plan_review_receipt' => $firstReviewPayload,
        ],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $planner = implementationReceiptDispatch(
        $planning,
        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'orbit-implementation-planning-2',
        'orbit_planning_correction',
    );
    $planningPayload = implementationPlanningPayload($test, $planner, 2);
    $planningReceipt = implementationStoredReceipt($planning, 'orbit_planning', $planningPayload);
    implementationCompletePhase($planning, $planningReceipt, 'ready');

    $review = PhaseRun::query()->create([
        'delivery_id' => $test->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Completed,
        'input' => [
            'planning_receipt_id' => $planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $reviewer = implementationReceiptDispatch(
        $review,
        OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'orbit-implementation-plan-review-2',
        'orbit_plan_review',
    );
    $reviewPayload = implementationReviewPayload($test, $reviewer, 2, 'pass');
    $reviewReceipt = implementationStoredReceipt($review, 'orbit_plan_review', $reviewPayload);
    implementationCompletePhase($review, $reviewReceipt, 'pass');
    $test->phaseRun->forceFill(['input' => [
        'plan_review_receipt_id' => $reviewReceipt->id,
        'plan_review_receipt' => $reviewPayload,
    ]])->save();
}

it('captures one immutable idempotent ready implementation receipt', function () {
    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('Orbit implementation receipt 3 captured for phase run 3.')
        ->assertSuccessful();

    $receipt = Receipt::where('kind', 'orbit_implementation')->sole();
    $body = trim((string) File::get($this->bodyPath));

    expect($receipt->schema_version)->toBe(1)
        ->and($receipt->candidate_sha)->toBe($this->candidateSha)
        ->and($receipt->validation_status)->toBe(ReceiptValidationStatus::Valid)
        ->and($receipt->payload)->toBe([
            'kind' => 'orbit_implementation',
            'schema_version' => 1,
            'delivery_id' => $this->delivery->id,
            'dispatch_id' => $this->dispatch->id,
            'issue_key' => 'ORB-234',
            'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            'attempt' => 1,
            'result' => 'ready',
            'worktree' => $this->worktreePath,
            'reviewed_candidate_sha' => $this->reviewedSha,
            'candidate_sha' => $this->candidateSha,
            'handoff_path' => '.loop/runtime/implementation-handoff.md',
            'handoff' => 'Implementation is ready for publication.',
            'artifact_sha' => $this->artifactSha,
            'gate_receipt_path' => $this->gatePath,
            'pull_request_body_path' => '.loop/runtime/pull-request-body.md',
            'pull_request_body' => $body,
            'pull_request_body_sha256' => hash('sha256', $body),
            'flow' => 'discovery',
        ])
        ->and($this->repository->verificationCount)->toBe(1);
    Queue::assertPushed(AdvanceDelivery::class, 1);

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('Orbit implementation receipt 3 was already captured.')
        ->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(1)
        ->and($this->repository->verificationCount)->toBe(2);
    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('captures a blocked implementation without claiming completion evidence', function () {
    $arguments = [...$this->arguments, '--result' => 'blocked'];
    unset($arguments['--artifact'], $arguments['--gate'], $arguments['--body']);

    $this->artisan('delivery:submit-orbit-implementation-receipt', $arguments)->assertSuccessful();

    $payload = Receipt::where('kind', 'orbit_implementation')->sole()->payload;
    expect($payload['result'])->toBe('blocked')
        ->and($payload['artifact_sha'])->toBeNull()
        ->and($payload['gate_receipt_path'])->toBeNull()
        ->and($payload['pull_request_body_path'])->toBeNull()
        ->and($payload['pull_request_body'])->toBeNull()
        ->and($payload['pull_request_body_sha256'])->toBeNull()
        ->and($payload['flow'])->toBeNull()
        ->and($this->repository->verificationCount)->toBe(0);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('accepts a receipt that proves the prompt arrived before its RPC returns', function () {
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::Preparing])->save();

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_implementation')->sole()->payload['dispatch_id'])
        ->toBe($this->dispatch->id);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('rejects a different second implementation receipt', function () {
    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)->assertSuccessful();
    File::put($this->handoffPath, "Different implementation handoff.\n");

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('A different implementation receipt was already captured for this phase.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(1)
        ->and(Receipt::where('kind', 'orbit_implementation')->sole()->payload['handoff'])
        ->toBe('Implementation is ready for publication.');
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('accepts an implementation backed by a passing second plan review', function () {
    implementationPromoteToSecondReview($this);

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_implementation')->sole()->payload['reviewed_candidate_sha'])
        ->toBe($this->reviewedSha)
        ->and($this->repository->verificationCount)->toBe(1);
});

it('requires complete ready evidence and forbids evidence for blocked results', function (
    string $result,
    string $missingOrExtra,
) {
    $arguments = [...$this->arguments, '--result' => $result];

    if ($result === 'ready') {
        unset($arguments[$missingOrExtra]);
    } else {
        unset($arguments['--artifact'], $arguments['--gate'], $arguments['--body']);
        $arguments[$missingOrExtra] = $missingOrExtra === '--artifact' ? $this->artifactSha : 'evidence';
    }

    $this->artisan('delivery:submit-orbit-implementation-receipt', $arguments)
        ->expectsOutput('Ready requires one artifact, gate, and body; blocked must not include implementation evidence.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'ready without artifact' => ['ready', '--artifact'],
    'ready without gate' => ['ready', '--gate'],
    'ready without body' => ['ready', '--body'],
    'blocked with artifact' => ['blocked', '--artifact'],
    'blocked with gate' => ['blocked', '--gate'],
    'blocked with body' => ['blocked', '--body'],
]);

it('rejects unsafe and empty implementation files', function (string $target, string $case, string $message) {
    $property = $target === 'Handoff' ? 'handoffPath' : 'bodyPath';
    $option = $target === 'Handoff' ? '--handoff' : '--body';
    $path = $this->{$property};
    $outside = $this->base.'/outside.md';
    File::put($outside, $case === 'empty' ? '' : 'Outside evidence');

    if ($case === 'symlink') {
        unlink($path);
        symlink($outside, $path);
    } elseif ($case === 'outside') {
        $this->arguments[$option] = $outside;
    } else {
        File::put($path, "   \n");
    }

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput($message)
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'symlink handoff' => ['Handoff', 'symlink', "Handoff must be a regular file inside the issue's .loop directory."],
    'outside handoff' => ['Handoff', 'outside', "Handoff must be a regular file inside the issue's .loop directory."],
    'empty handoff' => ['Handoff', 'empty', 'Handoff is empty.'],
    'symlink body' => ['Pull request body', 'symlink', "Pull request body must be a regular file inside the issue's .loop directory."],
    'outside body' => ['Pull request body', 'outside', "Pull request body must be a regular file inside the issue's .loop directory."],
    'empty body' => ['Pull request body', 'empty', 'Pull request body is empty.'],
]);

it('requires execution from the exact implementation worktree', function () {
    chdir($this->base);

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('Run this command from the exact delivery worktree.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(0);
    Process::assertNothingRan();
});

it('does not capture an implementation when repository verification fails', function () {
    $this->repository->failure = 'The pushed implementation evidence changed.';

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('The pushed implementation evidence changed.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(0)
        ->and($this->repository->verificationCount)->toBe(1);
    Queue::assertNothingPushed();
});

it('rejects implementation input or source-receipt corruption', function (string $corruption) {
    if ($corruption === 'input') {
        $input = $this->phaseRun->input;
        $input['plan_review_receipt']['handoff'] = 'Changed source input.';
        $this->phaseRun->forceFill(['input' => $input])->save();
    } else {
        DB::table('receipts')->where('id', $this->reviewReceipt->id)->update([
            'payload_hash' => str_repeat('0', 64),
        ]);
    }

    $this->artisan('delivery:submit-orbit-implementation-receipt', $this->arguments)
        ->expectsOutput('The implementation receipt no longer matches the active dispatch.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_implementation')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['input', 'source receipt']);
