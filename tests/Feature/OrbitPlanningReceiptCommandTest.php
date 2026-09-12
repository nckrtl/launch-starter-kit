<?php

use App\Delivery\Actions\CaptureOrbitPlanningReceipt;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\ReconcileOrbitSettledReceiptWait;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPlanningReceiptFailed;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->originalDirectory = getcwd();
    $this->base = storage_path('framework/testing/orbit-planning-receipt-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->headSha = str_repeat('a', 40);
    $this->treeSha = str_repeat('b', 40);
    $this->artifactSha = str_repeat('c', 40);
    $this->handoffPath = $this->worktreePath.'/.loop/runtime/planning-handoff.md';

    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repositoryPath.'/.git/orbit-checks/'.$this->headSha.'/startup', 0755, true);
    File::makeDirectory($this->repositoryPath.'/bin', 0755, true);
    File::makeDirectory(dirname($this->handoffPath), 0755, true);
    File::put($this->repositoryPath.'/bin/plan-lint', "#!/usr/bin/env bash\n");
    chmod($this->repositoryPath.'/bin/plan-lint', 0755);
    File::put($this->handoffPath, "Planning completed.\n");

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
    $delivery = app(StartOrbitDelivery::class)->handle(
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
    $this->phaseRun = PhaseRun::sole();
    $this->phaseRun->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->phaseRun->id,
        'agent_role' => 'planner',
        'idempotency_key' => 'orbit-planning-receipt',
        'herdr_agent_name' => 'orbit-planner',
        'prompt_name' => 'planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('d', 64),
        'status' => AgentDispatchStatus::Waiting,
    ]);
    $this->arguments = [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => (string) $this->dispatch->id,
        '--result' => 'ready',
        '--handoff' => '.loop/runtime/planning-handoff.md',
        '--artifact' => $this->artifactSha,
    ];
    $this->plan = "Plan format: 1\nIssue: ORB-234\nFlow: discovery\nReview verdict: PENDING\n";

    chdir($this->worktreePath);
    Queue::fake();
    fakeOrbitPlanningReceiptProcesses($this);
});

afterEach(function () {
    if (is_string($this->originalDirectory)) {
        chdir($this->originalDirectory);
    }

    File::deleteDirectory($this->base);
});

function fakeOrbitPlanningReceiptProcesses(object $test, ?string $head = null): void
{
    $validator = realpath($test->repositoryPath.'/bin/plan-lint');
    $candidate = $head ?? $test->headSha;

    Process::fake(function ($process) use ($test, $candidate, $validator) {
        return match ($process->command) {
            ['git', 'rev-parse', 'HEAD'] => Process::result(output: $candidate."\n"),
            [$validator, 'verify', 'ORB-234', '--worktree='.$test->worktreePath, '--artifact='.$test->artifactSha] => Process::result(output: "passed\n"),
            ['git', 'cat-file', '-t', $candidate],
            ['git', 'cat-file', '-t', $test->artifactSha] => Process::result(output: "commit\n"),
            ['git', 'rev-list', '--parents', '-n', '1', $test->artifactSha] => Process::result(output: "{$test->artifactSha} {$candidate}\n"),
            ['git', 'ls-tree', '-r', '--name-only', $candidate, '--', '.loop'] => Process::result(),
            ['git', 'diff', '--name-only', $candidate, $test->artifactSha, '--', '.', ':(exclude).loop'] => Process::result(),
            ['git', 'ls-tree', '-r', $test->artifactSha, '--', '.loop'] => Process::result(
                output: '100644 blob '.str_repeat('f', 40)."\t.loop/plan.md\n",
            ),
            ['git', 'show', $test->artifactSha.':.loop/plan.md'] => Process::result(output: $test->plan),
            default => throw new RuntimeException('Unexpected Orbit planning receipt command.'),
        };
    })->preventStrayProcesses();
}

it('captures one immutable idempotent ready planning receipt', function () {
    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->expectsOutput('Orbit planning receipt 1 captured for phase run 1.')
        ->assertSuccessful();

    $receipt = Receipt::sole();

    expect($receipt->kind)->toBe('orbit_planning')
        ->and($receipt->schema_version)->toBe(1)
        ->and($receipt->candidate_sha)->toBe($this->headSha)
        ->and($receipt->validation_status)->toBe(ReceiptValidationStatus::Valid)
        ->and($receipt->payload)->toBe([
            'kind' => 'orbit_planning',
            'schema_version' => 1,
            'delivery_id' => $this->phaseRun->delivery_id,
            'dispatch_id' => $this->dispatch->id,
            'issue_key' => 'ORB-234',
            'phase' => 'planning',
            'attempt' => 1,
            'result' => 'ready',
            'worktree' => $this->worktreePath,
            'candidate_sha' => $this->headSha,
            'handoff_path' => '.loop/runtime/planning-handoff.md',
            'handoff' => 'Planning completed.',
            'artifact_sha' => $this->artifactSha,
            'plan_sha256' => hash('sha256', $this->plan),
        ]);
    Queue::assertPushed(AdvanceDelivery::class, 1);

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->expectsOutput('Orbit planning receipt 1 was already captured.')
        ->assertSuccessful();

    expect(Receipt::count())->toBe(1);
    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('recovers an exact planning receipt submitted after the receipt grace timeout', function () {
    $delivery = Delivery::sole();
    $delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();
    $this->dispatch->forceFill([
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'planning-workspace',
        'herdr_tab_id' => 'planning-tab',
        'herdr_pane_id' => 'planning-pane',
        'herdr_terminal_id' => 'planning-terminal',
        'herdr_agent_id' => null,
        'herdr_agent_name' => 'orbit-planner',
        'status' => AgentDispatchStatus::Settled,
        'state_change_seq' => 31,
        'dispatched_at' => now()->subHour(),
        'settled_at' => now()->subMinutes(5),
    ])->save();
    $waits = app(ReconcileOrbitSettledReceiptWait::class);

    expect($waits->handle($delivery->id, $this->phaseRun->id, $this->dispatch->id))->toBeTrue()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked);

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::query()->where('phase_run_id', $this->phaseRun->id)->count())->toBe(1)
        ->and($this->phaseRun->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->phaseRun->fresh()->failure_code)->toBeNull()
        ->and($this->phaseRun->fresh()->finished_at)->toBeNull()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($delivery->fresh()->failure_details)->toBeNull()
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and($this->dispatch->fresh()->state_change_seq)->toBe(31);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('rejects a different second planning receipt', function () {
    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)->assertSuccessful();
    File::put($this->handoffPath, "Different handoff.\n");

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->expectsOutput('A different planning receipt was already captured for this phase.')
        ->assertFailed();

    expect(Receipt::count())->toBe(1)
        ->and(Receipt::sole()->payload['handoff'])->toBe('Planning completed.');
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('captures a blocked planning receipt without an artifact or plan validation', function () {
    $arguments = [
        ...$this->arguments,
        '--result' => 'blocked',
    ];
    unset($arguments['--artifact']);

    $this->artisan('delivery:submit-orbit-receipt', $arguments)->assertSuccessful();

    expect(Receipt::sole()->payload['result'])->toBe('blocked')
        ->and(Receipt::sole()->payload['artifact_sha'])->toBeNull()
        ->and(Receipt::sole()->payload['plan_sha256'])->toBeNull();
    Process::assertNotRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'plan-lint'));
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('accepts a valid receipt after Herdr settles the exact planning dispatch', function () {
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Settled,
        'settled_at' => now(),
    ])->save();

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::sole()->payload['dispatch_id'])->toBe($this->dispatch->id)
        ->and(Receipt::sole()->validation_status)->toBe(ReceiptValidationStatus::Valid);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('accepts a receipt that proves a prompt reached the worker before the prompt RPC returns', function () {
    $this->dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)->assertSuccessful();

    expect(Receipt::sole()->payload['dispatch_id'])->toBe($this->dispatch->id)
        ->and(Receipt::sole()->validation_status)->toBe(ReceiptValidationStatus::Valid);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('requires the exact active planning dispatch', function () {
    $this->artisan('delivery:submit-orbit-receipt', [
        ...$this->arguments,
        'dispatch' => '999',
    ])->expectsOutput('The planning phase run and dispatch do not match an active Orbit worker.')
        ->assertFailed();

    expect(Receipt::count())->toBe(0);
    Process::assertNothingRan();
});

it('rejects a detached planning phase rebound to another delivery', function () {
    $sourceDelivery = Delivery::sole();
    $payload = [
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'delivery_id' => $sourceDelivery->id,
        'dispatch_id' => $this->dispatch->id,
        'issue_key' => 'ORB-234',
        'phase' => 'planning',
        'attempt' => 1,
        'result' => 'ready',
        'worktree' => $this->worktreePath,
        'candidate_sha' => $this->headSha,
        'handoff_path' => '.loop/runtime/planning-handoff.md',
        'handoff' => 'Planning completed.',
        'artifact_sha' => $this->artifactSha,
        'plan_sha256' => hash('sha256', $this->plan),
    ];
    $otherDelivery = Delivery::query()->create([
        'project_orchestration_id' => $sourceDelivery->project_orchestration_id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => '99999999-2222-4333-8444-555555555555',
        'external_issue_key' => 'ORB-999',
        'workflow_type' => $sourceDelivery->workflow_type,
        'workflow_version' => $sourceDelivery->workflow_version,
        'status' => DeliveryStatus::WaitingForAgent,
        'current_phase' => $sourceDelivery->current_phase,
        'worktree_path' => $sourceDelivery->worktree_path,
        'candidate_sha' => $sourceDelivery->candidate_sha,
    ]);
    $detachedPhase = clone $this->phaseRun;
    $detachedPhase->delivery_id = $otherDelivery->id;

    expect(fn () => app(CaptureOrbitPlanningReceipt::class)->handle(
        $detachedPhase,
        $this->dispatch,
        $payload,
    ))->toThrow(
        OrbitPlanningReceiptFailed::class,
        'The planning receipt no longer matches the active dispatch.',
    );

    expect(Receipt::count())->toBe(0);
});

it('requires execution from the exact delivery worktree', function () {
    chdir($this->base);

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->expectsOutput('Run this command from the exact delivery worktree.')
        ->assertFailed();

    expect(Receipt::count())->toBe(0);
    Process::assertNothingRan();
});

it('rejects unsafe or empty planning handoffs', function (string $case, string $message) {
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

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->expectsOutput($message)
        ->assertFailed();

    expect(Receipt::count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'symlink' => ['symlink', "Handoff must be a regular file inside the issue's .loop directory."],
    'outside' => ['outside', "Handoff must be a regular file inside the issue's .loop directory."],
    'empty' => ['empty', 'Handoff is empty.'],
]);

it('requires a ready artifact and forbids one for blocked planning', function (string $result, ?string $artifact) {
    $arguments = [...$this->arguments, '--result' => $result];

    if ($artifact === null) {
        unset($arguments['--artifact']);
    } else {
        $arguments['--artifact'] = $artifact;
    }

    $this->artisan('delivery:submit-orbit-receipt', $arguments)
        ->expectsOutput('Ready requires one full artifact SHA; blocked must not include an artifact.')
        ->assertFailed();

    expect(Receipt::count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'ready without artifact' => ['ready', null],
    'ready with short artifact' => ['ready', 'abc123'],
    'blocked with artifact' => ['blocked', str_repeat('c', 40)],
]);

it('captures a docs-updated planning candidate for advancement verification', function () {
    $candidate = str_repeat('e', 40);
    fakeOrbitPlanningReceiptProcesses($this, $candidate);

    $this->artisan('delivery:submit-orbit-receipt', $this->arguments)
        ->assertSuccessful();

    expect(Receipt::sole()->candidate_sha)->toBe($candidate)
        ->and(Receipt::sole()->payload['candidate_sha'])->toBe($candidate);
    Process::assertRanTimes(fn () => true, 9);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});
