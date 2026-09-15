<?php

use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\CreateTask as CreateTaskTool;
use App\Mcp\Tools\GetTask;
use App\Mcp\Tools\ListTasks;
use App\Mcp\Tools\ReorderTaskChildren as ReorderTaskChildrenTool;
use App\Mcp\Tools\SplitPendingTask as SplitPendingTaskTool;
use App\Mcp\Tools\UpdateTask as UpdateTaskTool;
use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-interface-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config(['commander.projects_path' => $this->projectsPath]);
    foreach (['orbit', 'commander'] as $id) {
        app(SharedKnowledgeProjectRepository::class)->create($id, ['name' => ucfirst($id), 'status' => 'active']);
    }
    $this->group = app(CreateTask::class)->handle('orbit', 'Implement feature', kind: TaskKind::Group);
    $this->first = app(CreateTask::class)->handle('orbit', 'First objective', parent: $this->group);
    $this->second = app(CreateTask::class)->handle('orbit', 'Second objective', parent: $this->group);
    $this->url = '/projects/orbit/tasks/'.$this->group->id;
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('shows project tasks and ordered briefs through Inertia', function () {
    $this->get('/projects/orbit/tasks')->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tasks/Index')->where('project.id', 'orbit')->has('tasks', 1)
            ->where('tasks.0.id', $this->group->id)->where('tasks.0.parent_id', null)->where('tasks.0.children_count', 2));
    $this->get($this->url)->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Tasks/Show')->where('task.editable', true)
            ->where('task.ordered_ids', [$this->first->id, $this->second->id])
            ->where('task.children.0.title', 'First objective')->has('task.children.0.acceptance_criteria'));
    $this->get('/projects/commander/tasks')->assertSuccessful()
        ->assertInertia(fn ($page) => $page->has('tasks', 0));
});

it('creates edits and reorders prepared tasks through the web actions without starting execution', function () {
    $input = ['title' => 'Third objective', 'kind' => 'executable', 'parent_id' => $this->group->id, 'creation_key' => 'web-create', 'acceptance_criteria' => 'Focused tests pass'];
    $this->post('/projects/orbit/tasks', $input)->assertRedirect($this->url);
    $this->post('/projects/orbit/tasks', $input)->assertRedirect($this->url);
    $third = Task::query()->where('creation_key', 'web-create')->sole();
    $this->put('/projects/orbit/tasks/'.$third->id, [
        'title' => 'Updated objective', 'description' => 'Context', 'acceptance_criteria' => 'Observable success', 'expected_version' => $third->contentVersion(),
        'status' => 'completed',
    ])->assertRedirect('/projects/orbit/tasks/'.$third->id);
    $this->put($this->url.'/order', [
        'ordered_ids' => [$third->id, $this->first->id, $this->second->id],
        'expected_ids' => [$this->first->id, $this->second->id, $third->id],
    ])->assertRedirect($this->url);
    $this->get($this->url)->assertInertia(fn ($page) => $page->where('task.children.0.title', 'Updated objective'));
    expect($third->fresh()->status->value)->toBe('pending')->and(TaskRun::query()->count())->toBe(0)->and(Task::query()->count())->toBe(4);
});

it('returns field validation and rejects stale web edits', function () {
    $this->post('/projects/orbit/tasks', [])->assertSessionHasErrors(['title', 'kind', 'creation_key']);
    $input = ['title' => 'Updated', 'expected_version' => $this->first->contentVersion()];
    $this->put('/projects/orbit/tasks/'.$this->first->id, $input)->assertSessionHasNoErrors();
    $this->from($this->url)->put('/projects/orbit/tasks/'.$this->first->id, [...$input, 'title' => 'Stale'])->assertSessionHasErrors('task');
    expect($this->first->fresh()->title)->toBe('Updated');
});

it('scopes every web read and mutation to the manifest project', function () {
    $foreignUrl = '/projects/commander/tasks/'.$this->group->id;
    $this->get($foreignUrl)->assertNotFound();
    $this->put($foreignUrl, ['title' => 'Wrong project', 'expected_version' => $this->group->contentVersion()])->assertNotFound();
    $this->put($foreignUrl.'/order', ['ordered_ids' => [], 'expected_ids' => []])->assertNotFound();
    $this->post('/projects/commander/tasks', ['title' => 'Wrong child', 'kind' => 'executable', 'parent_id' => $this->group->id, 'creation_key' => 'bad-parent'])->assertNotFound();
    $this->get('/projects/missing/tasks')->assertNotFound();
    $this->get('/projects/orbit/tasks/not-a-task')->assertNotFound();
    $this->get('/projects/orbit/tasks/1wrong')->assertNotFound();
    $this->put('/projects/orbit/tasks/not-a-task', ['title' => 'Invalid ID', 'expected_version' => $this->group->contentVersion()])->assertNotFound();
    $this->put('/projects/orbit/tasks/not-a-task/order', ['ordered_ids' => [], 'expected_ids' => []])->assertNotFound();
    expect(Task::query()->count())->toBe(3);
});

it('freezes web preparation after execution and exposes that state to the UI', function () {
    $startedAt = now()->subMinutes(65)->startOfSecond();
    $this->travelTo($startedAt, fn () => app(StartTaskRun::class)->handle($this->first, 'start', 'worker', 'reviewer'));
    $this->get($this->url)->assertInertia(fn ($page) => $page->where('task.editable', false)
        ->where('task.children.0.timing.started_at', $startedAt->toIso8601String())
        ->where('task.children.0.timing.finished_at', null)
        ->where('task.children.0.timing.elapsed_seconds', 3900)
        ->where('task.children.1.timing', null));
    $this->put('/projects/orbit/tasks/'.$this->second->id, ['title' => 'Late edit', 'expected_version' => $this->second->contentVersion()])->assertSessionHasErrors('task');
    $this->put($this->url.'/order', ['ordered_ids' => [$this->second->id, $this->first->id], 'expected_ids' => [$this->first->id, $this->second->id]])->assertSessionHasErrors('task');
    $this->post('/projects/orbit/tasks', ['title' => 'Late child', 'kind' => 'executable', 'parent_id' => $this->group->id, 'creation_key' => 'late'])->assertSessionHasErrors('task');

    $finishedAt = $startedAt->addSeconds(125);
    $this->travelTo($finishedAt, fn () => $this->first->update(['status' => TaskStatus::Completed, 'completed_at' => now()]));
    $this->travelTo($finishedAt->addHour(), fn () => $this->get($this->url)->assertInertia(fn ($page) => $page
        ->where('task.children.0.timing.finished_at', $finishedAt->toIso8601String())
        ->where('task.children.0.timing.elapsed_seconds', 125)));
});

it('does not expose a task terminal until its workspace has complete Orbit placement', function () {
    $run = app(StartTaskRun::class)->handle($this->first, 'terminal-run', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id,
        'project_id' => 'orbit',
        'source_key' => 'ORB-terminal',
        'repository' => '/srv/orbit',
        'worktree' => '/srv/orbit-terminal',
        'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64),
        'configuration' => [],
        'herdr_workspace' => ['workspaceId' => 'workspace-1', 'paneId' => 'pane-implementer'],
    ]);
    foreach ([
        ['kind' => 'implement', 'role' => 'implementer', 'pane' => 'pane-implementer', 'terminal' => 'terminal-implementer'],
        ['kind' => 'review', 'role' => 'reviewer', 'pane' => 'pane-reviewer', 'terminal' => 'terminal-reviewer'],
    ] as $index => $session) {
        TaskAgentDispatch::query()->create([
            'task_workspace_id' => $workspace->id,
            'task_run_id' => $run->id,
            'step_key' => 'terminal-'.$session['role'],
            'kind' => $session['kind'],
            'state' => 'sent',
            'token_hash' => str_repeat((string) ($index + 1), 64),
            'handoff_token' => 'handoff-'.$session['role'],
            'prompt' => 'hidden prompt',
            'session' => [
                'workspaceId' => 'workspace-1',
                'paneId' => $session['pane'],
                'terminalId' => $session['terminal'],
                'agentStatus' => 'working',
            ],
        ]);
    }

    $this->get('/projects/orbit/tasks/'.$this->group->id)->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('task.terminal_sessions', []));

    $this->postJson('/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer/observation-grant')
        ->assertNotFound();
});

it('issues a scoped Orbit observation grant without exposing runtime identifiers', function () {
    $run = app(StartTaskRun::class)->handle($this->first, 'orbit-terminal-run', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id,
        'project_id' => 'orbit',
        'source_key' => 'ORB-orbit-terminal',
        'repository' => '/srv/orbit',
        'worktree' => '/srv/orbit-orbit-terminal',
        'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64),
        'configuration' => [],
        'orbit_node_id' => 9,
        'orbit_herdr_session_id' => 41,
        'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ]);
    TaskAgentDispatch::query()->create([
        'task_workspace_id' => $workspace->id,
        'task_run_id' => $run->id,
        'step_key' => 'orbit-terminal-implementer',
        'kind' => 'implement',
        'state' => 'sent',
        'token_hash' => str_repeat('1', 64),
        'handoff_token' => 'handoff',
        'prompt' => 'hidden',
        'session' => [
            'workspaceId' => 'workspace-secret',
            'paneId' => 'pane-implementer',
            'terminalId' => 'terminal-implementer',
            'agentStatus' => 'working',
        ],
    ]);
    config([
        'commander.orbit.url' => 'https://gateway.orbit',
        'commander.orbit.ca' => '/secure/orbit-ca.pem',
        'commander.orbit.herdr_observer_origins' => ['wss://commander-tasks.herdr.beast.test'],
        'app.url' => 'https://tasks.commander.test',
    ]);
    Http::preventStrayRequests();
    $sentOptions = null;
    Http::fake(function ($request, array $options) use (&$sentOptions) {
        $sentOptions = $options;

        return Http::response([
            'data' => [
                'observer_url' => 'wss://commander-tasks.herdr.beast.test?access_token=one-time-secret',
                'scope' => 'terminal.observe',
                'pane' => 'pane-implementer',
                'terminal' => 'terminal-implementer',
                'cols' => 132,
                'rows' => 48,
                'expires_at' => now()->addMinute()->toIso8601String(),
                'nonce' => 'nonce1',
            ],
            'meta' => ['request_id' => 'request-1'],
        ], 201);
    });

    $this->get('/projects/orbit/tasks/'.$this->group->id)->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('task.terminal_sessions', [[
                'role' => 'implementer', 'label' => 'Implementer', 'status' => 'working',
            ]])
            ->missing('task.terminal_sessions.0.paneId')
            ->missing('task.terminal_sessions.0.terminalId')
            ->missing('task.terminal_sessions.0.orbitHerdrSessionId'));

    $response = $this->postJson('/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer/observation-grant', [
        'cols' => 132,
        'rows' => 48,
    ]);
    $response->assertSuccessful()
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
        ->assertExactJson(['observer_url' => 'wss://commander-tasks.herdr.beast.test?access_token=one-time-secret']);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://gateway.orbit/api/v1/herdr/sessions/41/observation-grants'
            && $request->method() === 'POST'
            && $request['pane'] === 'pane-implementer'
            && $request['terminal'] === 'terminal-implementer'
            && $request['cols'] === 132
            && $request['rows'] === 48
            && $request['origin'] === 'https://tasks.commander.test';
    });
    expect($sentOptions['verify'] ?? null)->toBe('/secure/orbit-ca.pem')
        ->and($sentOptions['allow_redirects'] ?? null)->toBeFalse()
        ->and($sentOptions['connect_timeout'] ?? null)->toBe(2)
        ->and($sentOptions['timeout'] ?? null)->toBe(5);
    $this->get('/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer')->assertNotFound();
    $this->get('/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer/transcript')->assertNotFound();
    $this->get('/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer/observation-grant')->assertMethodNotAllowed();
    $this->postJson('/projects/orbit/tasks/'.$this->group->id.'/terminal/reviewer/observation-grant')->assertNotFound();
    $this->postJson('/projects/commander/tasks/'.$this->group->id.'/terminal/implementer/observation-grant')->assertNotFound();
});

it('fails an Orbit-assigned terminal closed when the Gateway cannot issue a grant', function () {
    $run = app(StartTaskRun::class)->handle($this->first, 'failed-orbit-terminal-run', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id, 'project_id' => 'orbit', 'source_key' => 'ORB-failed-orbit-terminal',
        'repository' => '/srv/orbit', 'worktree' => '/srv/orbit-failed-orbit-terminal', 'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64), 'configuration' => [], 'orbit_node_id' => 9,
        'orbit_herdr_session_id' => 42, 'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ]);
    TaskAgentDispatch::query()->create([
        'task_workspace_id' => $workspace->id, 'task_run_id' => $run->id, 'step_key' => 'failed-orbit-terminal',
        'kind' => 'implement', 'state' => 'sent', 'token_hash' => str_repeat('1', 64), 'handoff_token' => 'handoff',
        'prompt' => 'hidden', 'session' => ['workspaceId' => 'workspace', 'paneId' => 'pane', 'terminalId' => 'terminal', 'agentStatus' => 'working'],
    ]);
    config([
        'commander.orbit.url' => 'https://gateway.orbit',
        'commander.orbit.ca' => '',
        'commander.orbit.herdr_observer_origins' => ['wss://commander-tasks.herdr.beast.test'],
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://gateway.orbit/*' => Http::response(['error' => ['code' => 'herdr.observer_failed']], 503)]);

    $grantUrl = '/projects/orbit/tasks/'.$this->group->id.'/terminal/implementer/observation-grant';
    $this->postJson($grantUrl)->assertStatus(503);

    Http::fake(['https://gateway.orbit/*' => Http::response('not-json', 201, ['Content-Type' => 'application/json'])]);
    $this->postJson($grantUrl)->assertStatus(503);

    Http::fake(['https://gateway.orbit/*' => Http::response(['data' => [
        'observer_url' => 'wss://commander-tasks.herdr.beast.test?access_token=one&access_token=two',
        'scope' => 'terminal.observe',
        'pane' => 'pane',
        'terminal' => 'terminal',
        'cols' => 120,
        'rows' => 36,
        'expires_at' => now()->addMinute()->toIso8601String(),
        'nonce' => 'nonce',
    ]], 201)]);
    $this->postJson($grantUrl)->assertStatus(503);

    $this->postJson($grantUrl, ['cols' => 39, 'rows' => 101])->assertUnprocessable();
});

it('keeps observation grant bearer URLs out of Inertia devtools storage', function () {
    expect(config('inertia.devtools.except'))
        ->toContain('projects/*/tasks/*/terminal/*/observation-grant')
        ->and(config('inertia.devtools.redact.keys'))
        ->toContain('observer_url');
});

it('keeps incomplete legacy placement out of task detail sessions', function () {
    $run = app(StartTaskRun::class)->handle($this->first, 'stale-terminal-run', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->group->id, 'project_id' => 'orbit', 'source_key' => 'ORB-stale-terminal',
        'repository' => '/srv/orbit', 'worktree' => '/srv/orbit-stale-terminal', 'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64), 'configuration' => [],
    ]);
    TaskAgentDispatch::query()->create([
        'task_workspace_id' => $workspace->id, 'task_run_id' => $run->id, 'step_key' => 'stale-terminal',
        'kind' => 'implement', 'state' => 'sent', 'token_hash' => str_repeat('1', 64), 'handoff_token' => 'handoff',
        'prompt' => 'hidden', 'session' => ['workspaceId' => 'workspace', 'paneId' => 'pane', 'terminalId' => 'terminal', 'agentStatus' => 'working'],
    ]);
    $this->get('/projects/orbit/tasks/'.$this->first->id)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('task.terminal_sessions', []));
    $this->postJson('/projects/orbit/tasks/'.$this->first->id.'/terminal/implementer/observation-grant')
        ->assertNotFound();
});

it('creates and reads tasks through MCP with retry-safe creation', function () {
    $input = ['project_id' => 'orbit', 'title' => 'From MCP', 'description' => 'Prepared externally', 'kind' => 'executable',
        'acceptance_criteria' => 'Pass a test', 'parent_id' => $this->group->id, 'creation_key' => 'mcp-create'];
    CommanderServer::tool(CreateTaskTool::class, $input)->assertOk()->assertSee('From MCP');
    CommanderServer::tool(CreateTaskTool::class, $input)->assertOk();
    $created = Task::query()->where('creation_key', 'mcp-create')->sole();
    CommanderServer::tool(GetTask::class, ['project_id' => 'orbit', 'task_id' => $this->group->id])
        ->assertOk()->assertStructuredContent(fn ($json) => $json->where('task.ordered_ids', [$this->first->id, $this->second->id, $created->id])
        ->where('task.children.2.acceptance_criteria', 'Pass a test')->etc());
    CommanderServer::tool(ListTasks::class, ['project_id' => 'commander'])->assertOk()->assertStructuredContent(['tasks' => []]);
    expect(Task::query()->count())->toBe(4)->and(TaskRun::query()->count())->toBe(0);
});

it('edits and reorders through MCP and rejects stale changes', function () {
    $input = ['project_id' => 'orbit', 'task_id' => $this->first->id, 'expected_version' => $this->first->contentVersion(),
        'title' => 'MCP edit', 'description' => 'New context', 'acceptance_criteria' => 'New criteria'];
    CommanderServer::tool(UpdateTaskTool::class, $input)->assertOk()->assertSee('MCP edit');
    CommanderServer::tool(UpdateTaskTool::class, [...$input, 'title' => 'Stale'])->assertHasErrors()->assertSee('Task changed');
    $order = [$this->first->id, $this->second->id];
    CommanderServer::tool(ReorderTaskChildrenTool::class, [
        'project_id' => 'orbit', 'task_id' => $this->group->id, 'ordered_ids' => array_reverse($order), 'expected_ids' => $order,
    ])->assertOk()->assertStructuredContent(fn ($json) => $json->where('task.ordered_ids', array_reverse($order))->etc());
    CommanderServer::tool(ReorderTaskChildrenTool::class, [
        'project_id' => 'orbit', 'task_id' => $this->group->id, 'ordered_ids' => $order, 'expected_ids' => $order,
    ])->assertHasErrors()->assertSee('Task order changed');
});

it('rejects cross-project MCP reads updates creations and reorders', function () {
    CommanderServer::tool(GetTask::class, ['project_id' => 'commander', 'task_id' => $this->group->id])->assertHasErrors()->assertSee('Task not found');
    CommanderServer::tool(UpdateTaskTool::class, ['project_id' => 'commander', 'task_id' => $this->group->id, 'title' => 'No', 'expected_version' => $this->group->contentVersion()])->assertHasErrors();
    CommanderServer::tool(CreateTaskTool::class, ['project_id' => 'commander', 'title' => 'No', 'kind' => 'executable', 'parent_id' => $this->group->id, 'creation_key' => 'no'])->assertHasErrors();
    CommanderServer::tool(ReorderTaskChildrenTool::class, ['project_id' => 'commander', 'task_id' => $this->group->id, 'ordered_ids' => [], 'expected_ids' => []])->assertHasErrors();
    expect(Task::query()->count())->toBe(3);
});

it('validates MCP fields and rejects unsafe creation retries and frozen edits', function () {
    CommanderServer::tool(CreateTaskTool::class, ['project_id' => 'orbit'])->assertHasErrors(['title', 'kind', 'creation key']);
    CommanderServer::tool(ReorderTaskChildrenTool::class, ['project_id' => 'orbit', 'task_id' => $this->group->id, 'ordered_ids' => [$this->first->id, $this->first->id], 'expected_ids' => []])->assertHasErrors(['duplicate']);
    $input = ['project_id' => 'orbit', 'title' => 'Original', 'kind' => 'executable', 'creation_key' => 'retry'];
    CommanderServer::tool(CreateTaskTool::class, $input)->assertOk();
    CommanderServer::tool(CreateTaskTool::class, [...$input, 'title' => 'Changed'])->assertHasErrors();
    app(StartTaskRun::class)->handle($this->first, 'start', 'worker', 'reviewer');
    CommanderServer::tool(UpdateTaskTool::class, ['project_id' => 'orbit', 'task_id' => $this->second->id, 'title' => 'Late', 'expected_version' => $this->second->contentVersion()])->assertHasErrors()->assertSee('frozen');
    CommanderServer::tool(CreateTaskTool::class, [...$input, 'creation_key' => 'late', 'parent_id' => $this->group->id])->assertHasErrors();
    CommanderServer::tool(ReorderTaskChildrenTool::class, ['project_id' => 'orbit', 'task_id' => $this->group->id, 'ordered_ids' => [$this->second->id, $this->first->id], 'expected_ids' => [$this->first->id, $this->second->id]])->assertHasErrors();
});

it('validates the audited pending-tail split MCP contract', function () {
    CommanderServer::tool(SplitPendingTaskTool::class, ['project_id' => 'orbit'])
        ->assertHasErrors(['workspace id', 'target task id', 'active run id', 'active dispatch id', 'expected manifest hash',
            'expected target version', 'amendment key', 'reason', 'evidence', 'replacements', 'exclusive', 'apply']);
});

it('registers task tools on the authenticated MCP transport and fails closed without a token', function () {
    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    config(['commander.mcp_token' => '']);
    $this->postJson('/mcp/commander', $payload)->assertUnauthorized();
    config(['commander.mcp_token' => 'task-test-token']);
    $this->withToken('wrong')->postJson('/mcp/commander', $payload)->assertUnauthorized();
    $response = $this->withToken('task-test-token')->postJson('/mcp/commander', $payload)->assertSuccessful();
    $names = collect($response->json('result.tools'))->pluck('name')->all();
    foreach ([ListTasks::class, GetTask::class, CreateTaskTool::class, UpdateTaskTool::class, ReorderTaskChildrenTool::class, SplitPendingTaskTool::class] as $tool) {
        expect($names)->toContain(app($tool)->name());
    }
});
