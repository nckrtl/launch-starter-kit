<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Jobs\AdvanceDelivery;
use App\Models\Delivery;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/shadow-command-projects-'.bin2hex(random_bytes(4)));
    $this->repository = storage_path('framework/testing/shadow-command-repository-'.bin2hex(random_bytes(4)));
    $this->worktreeRoot = storage_path('framework/testing/shadow-command-worktrees-'.bin2hex(random_bytes(4)));
    $this->worktree = $this->worktreeRoot.'/orb-234';
    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    File::put($this->repository.'/bin/worktree-create', "#!/usr/bin/env bash\n");
    chmod($this->repository.'/bin/worktree-create', 0755);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('herdr.orchestration.enabled', true);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', shadowCommandConfig($this->repository, $this->worktreeRoot));

    Queue::fake();
    Process::fake(['*' => Process::sequence()
        ->push($this->worktree."\n")
        ->push(str_repeat('a', 40)."\n")])
        ->preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->repository);
    File::deleteDirectory($this->worktreeRoot);
});

function shadowCommandConfig(string $repository, string $worktreeRoot): array
{
    return [
        'type' => 'orbit',
        'repository' => $repository,
        'worktreeRoot' => $worktreeRoot,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ];
}

function runShadowCommand(string $project, string $issueId, string $issueKey): array
{
    return [
        'project' => $project,
        'issue-id' => $issueId,
        'issue-key' => $issueKey,
        '--force' => true,
    ];
}

it('prepares and records an Orbit worktree before queueing advancement', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('Shadow delivery 1 queued for ORB-234 in project orbit.')
        ->assertSuccessful();

    $delivery = Delivery::sole();

    expect($delivery->external_issue_id)->toBe('linear-234')
        ->and($delivery->external_issue_key)->toBe('ORB-234')
        ->and($delivery->worktree_path)->toBe(realpath($this->worktree))
        ->and($delivery->candidate_sha)->toBe(str_repeat('a', 40));

    Queue::assertPushed(AdvanceDelivery::class, fn (AdvanceDelivery $job): bool => $job->deliveryId === $delivery->id);
    Process::assertRan(fn ($process): bool => $process->command === [realpath($this->repository.'/bin/worktree-create'), 'ORB-234', '--flow=discovery']
        && $process->path === realpath($this->repository)
        && $process->timeout === 300);
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'rev-parse', 'HEAD']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 10);
});

it('refuses to start when shadow mode is disabled', function () {
    config()->set('herdr.orchestration.enabled', false);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('Herdr orchestration shadow mode is disabled.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses missing and paused projects', function (string $project, bool $pause) {
    if ($pause) {
        $this->project->update(['state' => ProjectOrchestrationState::Paused]);
    }

    $this->artisan('delivery:start-shadow', runShadowCommand($project, 'linear-234', 'ORB-234'))
        ->expectsOutput("Project [{$project}] is not configured and enabled.")
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
})->with([
    'missing project' => ['missing', false],
    'paused project' => ['orbit', true],
]);

it('refuses invalid issue keys before preparing a worktree', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'not-a-key'))
        ->expectsOutputToContain('issue key field format is invalid')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses invalid live project config', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode(['type' => 'orbit'], JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses project config that is not a JSON object', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode('invalid', JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses a failed Orbit worktree adapter', function () {
    Process::fake(['*' => Process::result(errorOutput: 'adapter failed', exitCode: 1)])->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('Orbit worktree preparation failed: adapter failed')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes an Orbit worktree adapter launch failure', function () {
    Process::fake(fn () => throw new RuntimeException('launch failed'))->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('The Orbit worktree adapter could not run.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes a prepared worktree Git inspection failure', function () {
    $calls = 0;
    $worktree = $this->worktree;

    Process::fake(function () use (&$calls, $worktree) {
        if ($calls++ === 0) {
            return Process::result(output: $worktree."\n");
        }

        throw new RuntimeException('inspection failed');
    })->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('The prepared worktree Git HEAD could not be inspected.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses returned worktrees outside the configured root', function () {
    Process::fake(['*' => Process::result(output: sys_get_temp_dir()."\n")])->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('Orbit returned a worktree outside the configured worktree root.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertRanTimes(fn () => true, 1);
});

it('refuses a malformed Git head from the prepared worktree', function () {
    Process::fake(['*' => Process::sequence()->push($this->worktree."\n")->push('not-a-sha')])
        ->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234'))
        ->expectsOutput('Could not resolve a valid Git HEAD for the prepared worktree.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a duplicate active delivery without resolving Git again', function () {
    $arguments = runShadowCommand('orbit', 'linear-234', 'ORB-234');
    $this->artisan('delivery:start-shadow', $arguments)->assertSuccessful();
    Queue::fake();

    $this->artisan('delivery:start-shadow', $arguments)
        ->expectsOutput('An active delivery already exists for [ORB-234].')
        ->assertFailed();

    expect(Delivery::count())->toBe(1);
    Queue::assertNothingPushed();
    Process::assertRanTimes(fn () => true, 2);
});
