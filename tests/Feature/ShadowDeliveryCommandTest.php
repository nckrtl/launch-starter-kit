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
    $this->worktreeRoot = storage_path('framework/testing/shadow-command-worktrees-'.bin2hex(random_bytes(4)));
    $this->worktree = $this->worktreeRoot.'/orb-234';
    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('herdr.orchestration.enabled', true);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', shadowCommandConfig($this->worktreeRoot));

    Queue::fake();
    Process::fake(['*' => Process::result(output: str_repeat('a', 40)."\n")])->preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->worktreeRoot);
});

function shadowCommandConfig(string $worktreeRoot): array
{
    return [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => $worktreeRoot,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ];
}

function runShadowCommand(string $project, string $issueId, string $issueKey, string $worktree): array
{
    return [
        'project' => $project,
        'issue-id' => $issueId,
        'issue-key' => $issueKey,
        'worktree' => $worktree,
        '--force' => true,
    ];
}

it('creates one delivery from the worktree head and queues its advancement', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree))
        ->expectsOutput('Shadow delivery 1 queued for ORB-234 in project orbit.')
        ->assertSuccessful();

    $delivery = Delivery::sole();

    expect($delivery->external_issue_id)->toBe('linear-234')
        ->and($delivery->external_issue_key)->toBe('ORB-234')
        ->and($delivery->worktree_path)->toBe(realpath($this->worktree))
        ->and($delivery->candidate_sha)->toBe(str_repeat('a', 40));

    Queue::assertPushed(AdvanceDelivery::class, fn (AdvanceDelivery $job): bool => $job->deliveryId === $delivery->id);
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'rev-parse', 'HEAD']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 10);
});

it('refuses to start when shadow mode is disabled', function () {
    config()->set('herdr.orchestration.enabled', false);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree))
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

    $this->artisan('delivery:start-shadow', runShadowCommand($project, 'linear-234', 'ORB-234', $this->worktree))
        ->expectsOutput("Project [{$project}] is not configured and enabled.")
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
})->with([
    'missing project' => ['missing', false],
    'paused project' => ['orbit', true],
]);

it('refuses invalid issue keys and worktrees outside the configured root', function (string $issueKey, string $worktree, string $message) {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', $issueKey, $worktree))
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
})->with([
    'invalid issue key' => ['not-a-key', '/unused', 'issue key field format is invalid'],
    'outside root' => ['ORB-234', '/tmp', 'within the configured worktree root'],
]);

it('refuses invalid live project config', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode(['type' => 'orbit'], JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses project config that is not a JSON object', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode('invalid', JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses failed and malformed Git HEAD results', function ($result) {
    Process::fake(['*' => $result])->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree))
        ->expectsOutput('Could not resolve a valid Git HEAD for the worktree.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'failed command' => fn () => Process::result(errorOutput: 'fatal', exitCode: 128),
    'malformed SHA' => fn () => Process::result(output: 'not-a-sha'),
]);

it('refuses a duplicate active delivery without resolving Git again', function () {
    $arguments = runShadowCommand('orbit', 'linear-234', 'ORB-234', $this->worktree);
    $this->artisan('delivery:start-shadow', $arguments)->assertSuccessful();
    Queue::fake();

    $this->artisan('delivery:start-shadow', $arguments)
        ->expectsOutput('An active delivery already exists for [ORB-234].')
        ->assertFailed();

    expect(Delivery::count())->toBe(1);
    Queue::assertNothingPushed();
    Process::assertRanTimes(fn () => true, 1);
});
