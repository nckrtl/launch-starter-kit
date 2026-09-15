<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/browser-tasks-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config(['commander.projects_path' => $this->projectsPath, 'inertia.ssr.enabled' => false]);
    app(SharedKnowledgeProjectRepository::class)->create('tasks-demo', ['name' => 'Tasks Demo', 'status' => 'active']);
    Http::fake();
    Process::fake();
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('lists only top-level task groups and opens their subtask breakdown', function () {
    $group = app(CreateTask::class)->handle(
        'tasks-demo',
        'Prepare release',
        'This full release brief stays in its tab.',
        TaskKind::Group,
        acceptanceCriteria: 'Every release check passes.',
    );
    $active = app(CreateTask::class)->handle('tasks-demo', 'Draft changelog', 'Write public notes.', parent: $group);
    $pending = app(CreateTask::class)->handle('tasks-demo', 'Publish artifacts', parent: $group);
    $done = app(CreateTask::class)->handle('tasks-demo', 'Verify release', parent: $group);
    $this->travelTo(now()->subMinutes(65)->startOfSecond(), fn () => app(StartTaskRun::class)->handle($active, 'start', 'worker', 'reviewer'));
    $done->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);
    $completed = app(CreateTask::class)->handle('tasks-demo', 'Shipped release', kind: TaskKind::Group);
    $completed->update(['status' => TaskStatus::Completed, 'completed_at' => now()]);

    $page = visit('/projects/tasks-demo/tasks');
    $page
        ->assertAttribute('[aria-label="Project sections"] [role="tab"]:first-child', 'href', '/projects/tasks-demo')
        ->assertAttribute('[aria-label="Project sections"] [role="tab"]:first-child', 'aria-selected', 'false')
        ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(2)', 'href', '/projects/tasks-demo?section=instances')
        ->assertAttribute('[aria-label="Project sections"] [role="tab"]:nth-child(3)', 'href', '/projects/tasks-demo?section=github')
        ->assertAttribute('[aria-label="Project sections"] [role="tab"]:last-child', 'aria-selected', 'true')
        ->assertSee('Prepare release')->assertSee('Shipped release')
        ->assertDontSee('Draft changelog')->assertDontSee('Publish artifacts')
        ->assertPresent('tbody [data-task-status-icon="in-progress"]')
        ->assertPresent('tbody [data-task-status-icon="completed"]')
        ->assertSeeIn('tbody > tr:first-child', 'Prepare release')
        ->assertSeeIn('tbody > tr:last-child', 'Shipped release')
        ->click('In progress')->assertSee('Prepare release')->assertDontSee('Shipped release')
        ->click('Completed')->assertSee('Shipped release')->assertDontSee('Prepare release')
        ->click('All')->assertSee('Prepare release')->assertSee('Shipped release')
        ->click('a[href="/projects/tasks-demo/tasks/'.$group->id.'"]')->assertPathIs('/projects/tasks-demo/tasks/'.$group->id)
        ->assertAttribute('[aria-label="Task group sections"] [role="tab"]:first-child', 'aria-selected', 'true')
        ->assertAttribute('[aria-label="Task group sections"] [role="tab"]:last-child', 'aria-selected', 'false')
        ->assertDontSee('This full release brief stays in its tab.')
        ->assertSeeIn('[data-task-lane="todo"]', 'Publish artifacts')
        ->assertSeeIn('[data-task-lane="in-progress"]', 'Draft changelog')
        ->assertSeeIn('[data-task-lane="in-progress"]', 'Started')
        ->assertSeeIn('[data-task-lane="in-progress"]', 'Duration')
        ->assertSeeIn('[data-task-lane="done"]', 'Verify release')
        ->assertSeeIn('[data-task-id="'.$active->id.'"] [data-slot="card-title"]', '#1 Draft changelog')
        ->assertSeeIn('[data-task-id="'.$pending->id.'"] [data-slot="card-title"]', '#2 Publish artifacts')
        ->assertSeeIn('[data-task-id="'.$done->id.'"] [data-slot="card-title"]', '#3 Verify release')
        ->assertSeeIn('[data-task-id="'.$active->id.'"] [data-slot="card-action"]', 'ID '.$active->id)
        ->assertSeeIn('[data-task-id="'.$pending->id.'"] [data-slot="card-action"]', 'ID '.$pending->id);

    expect($page->script('document.querySelector(\'[data-task-id="'.$pending->id.'"]\').innerText'))
        ->not->toContain('Pending')->not->toContain('Not started')
        ->and($page->script('document.querySelector(\'[data-task-id="'.$active->id.'"]\').innerText'))
        ->not->toContain('Running')
        ->and($page->script('document.querySelector(\'[data-task-id="'.$done->id.'"]\').innerText'))
        ->not->toContain('Completed');

    $page->click('Brief')->assertSee('This full release brief stays in its tab.')
        ->assertSee('Every release check passes.')
        ->click('Tasks')->click('a[href="/projects/tasks-demo/tasks/'.$active->id.'"]')
        ->assertPathIs('/projects/tasks-demo/tasks/'.$active->id)
        ->assertSee('Write public notes.')->assertSee('Run information')
        ->assertSee('Started')->assertSee('Duration')
        ->assertNoJavaScriptErrors();
});

it('renders recorded implementer and reviewer panes through Orbit observation grants', function () {
    $group = app(CreateTask::class)->handle('tasks-demo', 'Observe delivery', kind: TaskKind::Group);
    $task = app(CreateTask::class)->handle('tasks-demo', 'Build observer', parent: $group);
    $run = app(StartTaskRun::class)->handle($task, 'browser-terminal', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $group->id,
        'project_id' => 'tasks-demo',
        'source_key' => 'browser-terminal',
        'repository' => '/srv/tasks-demo',
        'worktree' => '/srv/tasks-demo-terminal',
        'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64),
        'configuration' => [],
        'orbit_node_id' => 9,
        'orbit_herdr_session_id' => 41,
        'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ]);

    foreach ([
        ['kind' => 'implement', 'role' => 'implementer'],
        ['kind' => 'review', 'role' => 'reviewer'],
    ] as $index => $session) {
        TaskAgentDispatch::query()->create([
            'task_workspace_id' => $workspace->id,
            'task_run_id' => $run->id,
            'step_key' => 'browser-'.$session['role'],
            'kind' => $session['kind'],
            'state' => 'sent',
            'token_hash' => str_repeat((string) ($index + 1), 64),
            'handoff_token' => 'browser-handoff-'.$session['role'],
            'prompt' => 'hidden prompt',
            'session' => [
                'workspaceId' => 'browser-workspace',
                'paneId' => 'pane-'.$session['role'],
                'terminalId' => 'terminal-'.$session['role'],
                'agentStatus' => 'working',
            ],
        ]);
    }

    config([
        'commander.orbit.url' => 'https://gateway.orbit',
        'commander.orbit.ca' => '',
        'commander.orbit.herdr_observer_origins' => ['wss://commander-tasks.herdr.beast.test'],
        'app.url' => 'https://tasks.commander.test',
    ]);
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    $grants = 0;
    Http::fake(function ($request) use (&$grants) {
        $grants++;

        return Http::response(['data' => [
            'observer_url' => 'wss://commander-tasks.herdr.beast.test?access_token=detail-pane-'.$grants,
            'scope' => 'terminal.observe',
            'pane' => $request['pane'],
            'terminal' => $request['terminal'],
            'cols' => $request['cols'],
            'rows' => $request['rows'],
            'expires_at' => now()->addMinute()->toIso8601String(),
            'nonce' => 'detailnonce'.$grants,
        ]], 201);
    });

    $page = visit('/projects/tasks-demo/tasks');
    $page->script(<<<'JS'
        () => {
        window.__detailObserverSends = 0;
        window.WebSocket = class {
            constructor() {
                this.onopen = null;
                this.onmessage = null;
                this.onerror = null;
                this.onclose = null;
                window.setTimeout(() => {
                    this.onopen?.({ type: "open" });
                    this.onmessage?.({
                        data: JSON.stringify({
                            type: "terminal.frame",
                            encoding: "ansi",
                            full: true,
                            seq: 1,
                            bytes: "TGF0ZXN0IEhlcmRyIHBhbmU=",
                        }),
                    });
                }, 10);
            }

            send() {
                window.__detailObserverSends += 1;
            }

            close() {}
        };
        return true;
        }
    JS);

    $page->click('a[href="/projects/tasks-demo/tasks/'.$group->id.'"]')
        ->assertSee('Sessions')->assertSee('Implementer')->assertSee('Reviewer')
        ->assertPresent('[data-task-terminal="implementer"]')
        ->assertSeeIn('[data-task-terminal="implementer"] .xterm-rows', 'Latest Herdr pane')
        ->assertDontSee('Readable output')
        ->assertDontSee('Live terminal')
        ->click('Reviewer')
        ->assertPresent('[data-task-terminal="reviewer"]')
        ->assertSeeIn('[data-task-terminal="reviewer"] .xterm-rows', 'Latest Herdr pane')
        ->assertNoJavaScriptErrors();

    expect($grants)->toBe(2)
        ->and($page->script('window.__detailObserverSends'))->toBe(0);
});

it('connects an Orbit-assigned pane directly with a fresh receive-only observation grant', function () {
    $group = app(CreateTask::class)->handle('tasks-demo', 'Observe remote delivery', kind: TaskKind::Group);
    $task = app(CreateTask::class)->handle('tasks-demo', 'Build remote observer', parent: $group);
    $run = app(StartTaskRun::class)->handle($task, 'browser-orbit-terminal', 'worker', 'reviewer');
    $workspace = TaskWorkspace::query()->create([
        'root_task_id' => $group->id,
        'project_id' => 'tasks-demo',
        'source_key' => 'browser-orbit-terminal',
        'repository' => '/srv/tasks-demo',
        'worktree' => '/srv/tasks-demo-orbit-terminal',
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
        'step_key' => 'browser-orbit-implementer',
        'kind' => 'implement',
        'state' => 'sent',
        'token_hash' => str_repeat('1', 64),
        'handoff_token' => 'browser-orbit-handoff',
        'prompt' => 'hidden prompt',
        'session' => [
            'workspaceId' => 'browser-orbit-workspace',
            'paneId' => 'browser-orbit-pane',
            'terminalId' => 'browser-orbit-terminal',
            'agentStatus' => 'working',
        ],
    ]);
    config([
        'commander.orbit.url' => 'https://gateway.orbit',
        'commander.orbit.ca' => '',
        'commander.orbit.herdr_observer_origins' => ['wss://commander-tasks.herdr.beast.test'],
        'app.url' => 'https://tasks.commander.test',
    ]);
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();
    $grantSequence = 0;
    Http::fake(function ($request) use (&$grantSequence) {
        if ($request->method() !== 'POST'
            || $request->url() !== 'https://gateway.orbit/api/v1/herdr/sessions/41/observation-grants') {
            return Http::response([], 404);
        }

        if ($request['origin'] !== 'https://tasks.commander.test') {
            return Http::response([], 422);
        }

        $grantSequence++;

        return Http::response(['data' => [
            'observer_url' => 'wss://commander-tasks.herdr.beast.test?access_token=browser-one-time-secret-'.$grantSequence,
            'scope' => 'terminal.observe',
            'pane' => 'browser-orbit-pane',
            'terminal' => 'browser-orbit-terminal',
            'cols' => $request['cols'],
            'rows' => $request['rows'],
            'expires_at' => now()->addMinute()->toIso8601String(),
            'nonce' => 'browsernonce',
        ]], 201);
    });

    $page = visit('/projects/tasks-demo/tasks');
    $page->script(<<<'JS'
        () => {
        window.__taskObserverUrls = [];
        window.__taskObserverSends = 0;
        window.__taskObserverConnections = 0;
        window.WebSocket = class {
            constructor(url) {
                this.url = url;
                this.onopen = null;
                this.onmessage = null;
                this.onerror = null;
                this.onclose = null;
                window.__taskObserverUrls.push(url);
                window.__taskObserverConnections += 1;
                const connection = window.__taskObserverConnections;
                window.setTimeout(() => {
                    this.onopen?.({ type: "open" });
                    this.onmessage?.({
                        data: JSON.stringify({
                            type: "terminal.frame",
                            encoding: "ansi",
                            full: true,
                            seq: 1,
                            bytes: "T3JiaXQgcmVtb3RlIHBhbmU=",
                        }),
                    });
                    if (connection === 1) this.onclose?.({ type: "close" });
                }, 10);
            }

            send() {
                window.__taskObserverSends += 1;
            }

            close() {}
        };
        return true;
        }
    JS);

    $page->click('a[href="/projects/tasks-demo/tasks/'.$group->id.'"]')
        ->assertPathIs('/projects/tasks-demo/tasks/'.$group->id)
        ->assertPresent('[data-task-terminal="implementer"]')
        ->assertSeeIn('[data-task-terminal="implementer"] .xterm-rows', 'Orbit remote pane')
        ->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            const terminal = document.querySelector('[aria-label="Implementer terminal, read only"]');
            terminal.style.width = "480px";
            terminal.style.height = "320px";
            return true;
        }
    JS);
    $page->assertScript('window.__taskObserverUrls.length', 2);

    expect($grantSequence)->toBe(2)
        ->and($page->script('window.__taskObserverUrls'))
        ->toBe([
            'wss://commander-tasks.herdr.beast.test?access_token=browser-one-time-secret-1',
            'wss://commander-tasks.herdr.beast.test?access_token=browser-one-time-secret-2',
        ])
        ->and($page->script('window.__taskObserverSends'))->toBe(0);
});

it('creates a feature and subtasks edits criteria and changes their execution order', function () {
    $page = visit('/projects');
    $page->click('Tasks Demo')->click('Tasks')->assertPathIs('/projects/tasks-demo/tasks')
        ->assertSee('No tasks yet')->click('Create task')
        ->fill('title', 'Ship feature')->fill('description', 'One clear outcome')->fill('acceptance_criteria', 'Feature works')
        ->click('Create')->assertSee('Ship feature')->assertSee('No tasks yet')
        ->click('Add task')->fill('title', 'Add domain behavior')->fill('description', 'Implement the domain')
        ->fill('acceptance_criteria', 'Domain tests pass')->click('Create')
        ->assertSee('Add domain behavior')
        ->click('Add task')->fill('title', 'Add interface')->fill('acceptance_criteria', 'Browser checks pass')->click('Create')
        ->assertSee('Add interface')
        ->assertDisabled('[aria-label="Move Add domain behavior up"]')
        ->assertDisabled('[aria-label="Move Add interface down"]')
        ->click('[aria-label="Edit Add interface"]')
        ->fill('acceptance_criteria', 'Browser and accessibility checks pass')->click('Save task')
        ->assertSee('Browser and accessibility checks pass')->click('Back to Ship feature')
        ->click('[aria-label="Move Add interface up"]')
        ->assertSeeIn('[data-task-lane="todo"] li:first-child', 'Add interface')
        ->assertSeeIn('[data-task-lane="todo"] li:last-child', 'Add domain behavior')
        ->assertNoJavaScriptErrors();

    $group = Task::query()->where('title', 'Ship feature')->sole();
    expect(array_map(fn (Task $task) => $task->title, app(TaskGraph::class)->orderedChildren($group)))->toBe(['Add interface', 'Add domain behavior'])
        ->and(TaskRun::query()->count())->toBe(0);
});

it('shows frozen task briefs without mutation controls after execution starts', function () {
    $group = app(CreateTask::class)->handle('tasks-demo', 'In progress feature', kind: TaskKind::Group);
    $first = app(CreateTask::class)->handle('tasks-demo', 'Active objective', parent: $group);
    app(CreateTask::class)->handle('tasks-demo', 'Next objective', parent: $group);
    app(StartTaskRun::class)->handle($first, 'start', 'worker', 'reviewer');

    visit('/projects/tasks-demo/tasks/'.$group->id)->assertSee('Execution has started')
        ->assertSeeIn('[data-task-heading-meta]', 'In progress')
        ->assertDontSeeIn('[data-task-heading-meta]', 'Group')
        ->assertDontSeeIn('[data-task-heading-meta]', 'Pending')
        ->assertSeeIn('[data-task-lane="in-progress"]', 'Active objective')
        ->assertSeeIn('[data-task-lane="todo"]', 'Next objective')
        ->assertDontSee('Add task')->assertDontSee('Edit task')->assertDontSee('Edit')
        ->assertNoJavaScriptErrors();
});
