<?php

use App\Models\TaskAgentDispatch;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-workspace-placement-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config([
        'commander.projects_path' => $this->projectsPath,
        'task-runtime.enabled' => true,
    ]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function placementWorkspace(string $source, string $socket, array $placement = []): TaskWorkspace
{
    $root = app(CreateTask::class)->handle('orbit', $source, kind: TaskKind::Group);

    return TaskWorkspace::query()->create([
        'root_task_id' => $root->id,
        'project_id' => 'orbit',
        'source_key' => $source,
        'repository' => '/srv/orbit',
        'worktree' => '/srv/'.mb_strtolower($source),
        'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64),
        'configuration' => ['socket' => $socket],
        ...$placement,
    ]);
}

it('atomically migrates exact legacy workspaces from one canonical Orbit placement', function () {
    $placement = [
        'orbit_node_id' => 9,
        'orbit_herdr_session_id' => 41,
        'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ];
    $source = placementWorkspace('ORB-source', '/run/herdr.sock', $placement);
    $first = placementWorkspace('ORB-first', '/run/herdr.sock');
    $second = placementWorkspace('ORB-second', '/run/herdr.sock');
    $session = ['workspaceId' => 'historic', 'paneId' => 'historic:p1', 'terminalId' => 'term-historic'];
    TaskAgentDispatch::query()->create([
        'task_workspace_id' => $first->id,
        'step_key' => 'historic-implementer',
        'kind' => 'implement',
        'state' => 'sent',
        'token_hash' => str_repeat('1', 64),
        'handoff_token' => 'handoff',
        'prompt' => 'hidden',
        'session' => $session,
    ]);

    $this->artisan('tasks:workspace:migrate-herdr-placement', [
        'project' => 'orbit',
        'source' => $source->id,
        '--expected' => 2,
        '--exclusive' => true,
    ])->assertSuccessful();

    foreach ([$first, $second] as $workspace) {
        expect($workspace->refresh()->only(array_keys($placement)))->toBe($placement);
    }
    expect(TaskAgentDispatch::query()->sole()->session)->toBe($session);

    $this->artisan('tasks:workspace:migrate-herdr-placement', [
        'project' => 'orbit',
        'source' => $source->id,
        '--expected' => 0,
        '--exclusive' => true,
    ])->assertSuccessful();
});

it('rejects an inexact migration without changing any workspace', function (string $defect) {
    $placement = [
        'orbit_node_id' => 9,
        'orbit_herdr_session_id' => 41,
        'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ];
    $source = placementWorkspace('ORB-source-'.$defect, '/run/herdr.sock', $placement);
    $legacy = placementWorkspace('ORB-legacy-'.$defect, $defect === 'socket' ? '/run/other.sock' : '/run/herdr.sock');
    if ($defect === 'partial') {
        TaskWorkspace::query()->whereKey($legacy->id)->toBase()->update(['orbit_node_id' => 9]);
    }

    $this->artisan('tasks:workspace:migrate-herdr-placement', [
        'project' => 'orbit',
        'source' => $source->id,
        '--expected' => $defect === 'count' ? 2 : 1,
        '--exclusive' => true,
    ])->assertFailed();

    expect($legacy->refresh()->orbit_herdr_session_id)->toBeNull();
})->with(['socket', 'partial', 'count']);
