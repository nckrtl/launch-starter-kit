<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
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
    $this->projectsPath = storage_path('framework/testing/shadow-receipt-projects-'.bin2hex(random_bytes(4)));
    $this->worktree = storage_path('framework/testing/shadow-receipt-worktree-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit-receipt', ['name' => 'Orbit Receipt', 'status' => 'active']);
    $project = app(ConfigureProjectOrchestration::class)->handle('orbit-receipt', [
        'type' => 'orbit', 'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => dirname($this->worktree), 'herdrSession' => 'orbit',
        'concurrency' => 1, 'defaultFlow' => 'discovery',
    ]);
    $this->delivery = app(StartShadowDelivery::class)->handle(
        $project,
        verifiedOrbitIssueSnapshot('44444444-5555-4666-8777-888888888888', 'ORB-234', $this->worktree.'/.loop/issue.json'),
        $this->worktree,
        new CandidateCheck('/checks/result.json', str_repeat('a', 40), str_repeat('b', 40)),
    );
    $this->phaseRun = PhaseRun::sole();
    $this->phaseRun->forceFill(['status' => PhaseRunStatus::Running, 'started_at' => now()])->save();
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->phaseRun->id,
        'agent_role' => 'test',
        'idempotency_key' => 'shadow-receipt-command',
        'herdr_agent_name' => 'commander-receipt-test',
        'prompt_name' => 'herdr_test',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => AgentDispatchStatus::Waiting,
    ]);

    Queue::fake();
    Process::fake(['*' => Process::result(output: str_repeat('a', 40)."\n")])->preventStrayProcesses();
});

afterEach(function () {
    if (is_string($this->originalDirectory)) {
        chdir($this->originalDirectory);
    }

    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->worktree);
});

it('captures an idempotent valid receipt from the delivery worktree', function () {
    chdir($this->worktree);
    $arguments = ['phase-run' => (string) $this->phaseRun->id, 'dispatch' => (string) $this->dispatch->id];

    $this->artisan('delivery:submit-shadow-receipt', $arguments)
        ->expectsOutput('Shadow receipt 1 captured for phase run 1.')
        ->assertSuccessful();

    $receipt = Receipt::sole();

    expect($receipt->validation_status)->toBe(ReceiptValidationStatus::Valid)
        ->and($receipt->payload['dispatch_id'])->toBe($this->dispatch->id)
        ->and($receipt->payload['head_sha'])->toBe(str_repeat('a', 40));
    Queue::assertPushed(AdvanceDelivery::class, fn (AdvanceDelivery $job): bool => $job->deliveryId === $this->delivery->id);

    Queue::fake();
    $this->artisan('delivery:submit-shadow-receipt', $arguments)
        ->expectsOutput('Shadow receipt 1 was already captured.')
        ->assertSuccessful();

    expect(Receipt::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('refuses a mismatched dispatch or working directory before capture', function () {
    $this->artisan('delivery:submit-shadow-receipt', [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => '999',
    ])->expectsOutput('The shadow phase run and dispatch do not match.')
        ->assertFailed();

    $this->artisan('delivery:submit-shadow-receipt', [
        'phase-run' => (string) $this->phaseRun->id,
        'dispatch' => (string) $this->dispatch->id,
    ])->expectsOutput('Run this command from the delivery worktree.')
        ->assertFailed();

    expect(Receipt::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});
