<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
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

beforeEach(function () {
    $this->originalDirectory = getcwd();
    $this->base = storage_path('framework/testing/orbit-plan-review-receipt-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->headSha = str_repeat('a', 40);
    $this->treeSha = str_repeat('b', 40);
    $this->planningArtifactSha = str_repeat('c', 40);
    $this->reviewArtifactSha = str_repeat('d', 40);
    $this->handoffPath = $this->worktreePath.'/.loop/runtime/plan-review-handoff.md';

    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repositoryPath.'/.git/orbit-checks/'.$this->headSha.'/startup', 0755, true);
    File::makeDirectory($this->repositoryPath.'/bin', 0755, true);
    File::makeDirectory(dirname($this->handoffPath), 0755, true);
    File::put($this->repositoryPath.'/bin/plan-lint', "#!/usr/bin/env bash\n");
    chmod($this->repositoryPath.'/bin/plan-lint', 0755);
    File::put($this->handoffPath, "The plan is independently approved.\n");

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
            $this->repositoryPath.'/.git/orbit-checks/'.$this->headSha.'/startup/result.json',
            $this->headSha,
            $this->treeSha,
        ),
    );
    $this->planning = PhaseRun::sole();
    $this->planner = AgentDispatch::query()->create([
        'phase_run_id' => $this->planning->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'orbit-plan-review-planner',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('e', 64),
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ]);
    $planningPayload = [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $this->delivery->id,
        'dispatch_id' => $this->planner->id,
        'issue_key' => 'ORB-234',
        'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1,
        'result' => 'ready',
        'worktree' => $this->worktreePath,
        'candidate_sha' => $this->headSha,
        'handoff_path' => '.loop/runtime/planning-handoff.md',
        'handoff' => 'Planning is ready for independent review.',
        'artifact_sha' => $this->planningArtifactSha,
        'plan_sha256' => str_repeat('f', 64),
    ];
    $this->planningReceipt = Receipt::query()->create([
        'phase_run_id' => $this->planning->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => $planningPayload,
        'payload_hash' => hash('sha256', json_encode($planningPayload, JSON_THROW_ON_ERROR)),
        'candidate_sha' => $this->headSha,
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $this->planning->forceFill([
        'status' => PhaseRunStatus::Completed,
        'output' => ['receipt_id' => $this->planningReceipt->id, 'result' => 'ready'],
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ])->save();
    $this->phaseRun = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'input' => [
            'planning_receipt_id' => $this->planningReceipt->id,
            'planning_receipt' => $planningPayload,
        ],
        'started_at' => now(),
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->phaseRun->id,
        'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'orbit-plan-review-receipt',
        'herdr_agent_name' => 'orb-234-loop-plan-review',
        'prompt_name' => 'orbit_plan_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('1', 64),
        'status' => AgentDispatchStatus::Waiting,
    ]);
    $this->delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        'status' => DeliveryStatus::WaitingForAgent,
    ])->save();
    $this->arguments = [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => (string) $this->dispatch->id,
        '--result' => 'pass',
        '--handoff' => '.loop/runtime/plan-review-handoff.md',
        '--artifact' => $this->reviewArtifactSha,
    ];
    $this->plan = "Plan format: 1\nIssue: ORB-234\nFlow: discovery\nReview verdict: PASS\n";

    chdir($this->worktreePath);
    Queue::fake();
    fakeOrbitPlanReviewReceiptProcesses($this);
});

afterEach(function () {
    if (is_string($this->originalDirectory)) {
        chdir($this->originalDirectory);
    }

    File::deleteDirectory($this->base);
});

function fakeOrbitPlanReviewReceiptProcesses(object $test, ?string $head = null): void
{
    $validator = realpath($test->repositoryPath.'/bin/plan-lint');
    $candidate = $head ?? $test->headSha;

    Process::fake(function ($process) use ($test, $candidate, $validator) {
        return match ($process->command) {
            ['git', 'rev-parse', 'HEAD'] => Process::result(output: $candidate."\n"),
            [$validator, 'verify', 'ORB-234', '--worktree='.$test->worktreePath, '--artifact='.$test->reviewArtifactSha] => Process::result(output: "passed\n"),
            ['git', 'cat-file', '-t', $candidate],
            ['git', 'cat-file', '-t', $test->reviewArtifactSha] => Process::result(output: "commit\n"),
            ['git', 'rev-list', '--parents', '-n', '1', $test->reviewArtifactSha] => Process::result(
                output: "{$test->reviewArtifactSha} {$candidate}\n",
            ),
            ['git', 'ls-tree', '-r', '--name-only', $candidate, '--', '.loop'] => Process::result(),
            ['git', 'diff', '--name-only', $candidate, $test->reviewArtifactSha, '--', '.', ':(exclude).loop'] => Process::result(),
            ['git', 'ls-tree', '-r', $test->reviewArtifactSha, '--', '.loop'] => Process::result(
                output: '100644 blob '.str_repeat('2', 40)."\t.loop/plan.md\n",
            ),
            ['git', 'show', $test->reviewArtifactSha.':.loop/plan.md'] => Process::result(output: $test->plan),
            default => throw new RuntimeException('Unexpected Orbit plan-review receipt command.'),
        };
    })->preventStrayProcesses();
}

it('captures one immutable idempotent passing plan-review receipt', function () {
    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('Orbit plan-review receipt 2 captured for phase run 2.')
        ->assertSuccessful();

    $receipt = Receipt::where('kind', 'orbit_plan_review')->sole();

    expect($receipt->schema_version)->toBe(1)
        ->and($receipt->candidate_sha)->toBe($this->headSha)
        ->and($receipt->validation_status)->toBe(ReceiptValidationStatus::Valid)
        ->and($receipt->payload)->toBe([
            'kind' => 'orbit_plan_review',
            'schema_version' => 1,
            'delivery_id' => $this->delivery->id,
            'dispatch_id' => $this->dispatch->id,
            'issue_key' => 'ORB-234',
            'phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
            'attempt' => 1,
            'result' => 'pass',
            'worktree' => $this->worktreePath,
            'candidate_sha' => $this->headSha,
            'handoff_path' => '.loop/runtime/plan-review-handoff.md',
            'handoff' => 'The plan is independently approved.',
            'artifact_sha' => $this->reviewArtifactSha,
            'plan_sha256' => hash('sha256', $this->plan),
        ]);
    Queue::assertPushed(AdvanceDelivery::class, 1);

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('Orbit plan-review receipt 2 was already captured.')
        ->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(1);
    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('captures a fixing review only with an exact FIX artifact', function () {
    $this->arguments['--result'] = 'fix';
    $this->plan = str_replace('PASS', 'FIX', $this->plan);

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_plan_review')->sole()->payload['result'])->toBe('fix')
        ->and(Receipt::where('kind', 'orbit_plan_review')->sole()->payload['plan_sha256'])
        ->toBe(hash('sha256', $this->plan));
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('captures a blocked review handoff without claiming an artifact', function () {
    $this->arguments['--result'] = 'blocked';
    unset($this->arguments['--artifact']);

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)->assertSuccessful();

    $payload = Receipt::where('kind', 'orbit_plan_review')->sole()->payload;
    expect($payload['result'])->toBe('blocked')
        ->and($payload['artifact_sha'])->toBeNull()
        ->and($payload['plan_sha256'])->toBeNull();
    Process::assertNotRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'plan-lint'));
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('accepts a review receipt that proves the prompt arrived before its RPC returns', function () {
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill(['status' => DeliveryStatus::Preparing])->save();

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::where('kind', 'orbit_plan_review')->sole()->payload['dispatch_id'])
        ->toBe($this->dispatch->id);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('rejects a different second plan-review receipt', function () {
    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)->assertSuccessful();
    File::put($this->handoffPath, "Different review handoff.\n");

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('A different plan-review receipt was already captured for this phase.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(1)
        ->and(Receipt::where('kind', 'orbit_plan_review')->sole()->payload['handoff'])
        ->toBe('The plan is independently approved.');
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('rejects a reviewer that changed the candidate', function () {
    fakeOrbitPlanReviewReceiptProcesses($this, str_repeat('9', 40));

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('The reviewer changed the candidate or reviewed another head.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Process::assertRanTimes(fn () => true, 1);
    Queue::assertNothingPushed();
});

it('rejects a review artifact whose verdict disagrees with the receipt', function () {
    $this->arguments['--result'] = 'fix';

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('The saved Orbit planning artifact must have a FIX review verdict.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('requires the exact active plan-review dispatch', function () {
    $this->dispatch->forceFill(['status' => AgentDispatchStatus::Pending])->save();

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('The plan-review phase run and dispatch do not match an active Orbit reviewer.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Process::assertNothingRan();
});

it('rejects review input that no longer matches its planning receipt', function () {
    $input = $this->phaseRun->input;
    $input['planning_receipt']['handoff'] = 'Changed review input.';
    $this->phaseRun->forceFill(['input' => $input])->save();

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('The plan-review receipt no longer matches the active dispatch.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a source planning receipt whose immutable hash no longer matches', function () {
    DB::table('receipts')
        ->where('id', $this->planningReceipt->id)
        ->update(['payload_hash' => str_repeat('0', 64)]);

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput('The plan-review receipt no longer matches the active dispatch.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('requires pass and fix artifacts and forbids one for blocked review', function (string $result, ?string $artifact) {
    $arguments = [...$this->arguments, '--result' => $result];

    if ($artifact === null) {
        unset($arguments['--artifact']);
    } else {
        $arguments['--artifact'] = $artifact;
    }

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $arguments)
        ->expectsOutput('Pass or fix requires one full artifact SHA; blocked must not include an artifact.')
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'pass without artifact' => ['pass', null],
    'fix with short artifact' => ['fix', 'abc123'],
    'blocked with artifact' => ['blocked', str_repeat('d', 40)],
]);

it('rejects unsafe and empty plan-review handoffs', function (string $case, string $message) {
    $outside = $this->base.'/outside.md';
    File::put($outside, $case === 'empty' ? '' : 'Outside handoff');

    if ($case === 'symlink') {
        unlink($this->handoffPath);
        symlink($outside, $this->handoffPath);
    } elseif ($case === 'outside') {
        $this->arguments['--handoff'] = $outside;
    } else {
        File::put($this->handoffPath, "   \n");
    }

    $this->artisan('delivery:submit-orbit-plan-review-receipt', $this->arguments)
        ->expectsOutput($message)
        ->assertFailed();

    expect(Receipt::where('kind', 'orbit_plan_review')->count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'symlink' => ['symlink', "Handoff must be a regular file inside the issue's .loop directory."],
    'outside' => ['outside', "Handoff must be a regular file inside the issue's .loop directory."],
    'empty' => ['empty', 'Handoff is empty.'],
]);
