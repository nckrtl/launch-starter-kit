<?php

use App\Herdr\RequestFailed;
use App\Jobs\AdvanceTaskRunner;
use App\Models\TaskAgentDispatch;
use App\Models\TaskSessionReconnection;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Runtime\HerdrTaskAgents;
use App\Tasks\Runtime\TaskAgentSessions;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\Runtime\TaskSessionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHerdrServer;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Process::preventStrayProcesses();
    $this->runtimeInterfaceDirectory = sys_get_temp_dir().'/commander-task-interface-'.bin2hex(random_bytes(8));
    File::makeDirectory($this->runtimeInterfaceDirectory.'/projects', 0700, true);
    File::makeDirectory($this->runtimeInterfaceDirectory.'/worktree', 0700);
    config(['commander.projects_path' => $this->runtimeInterfaceDirectory.'/projects', 'task-runtime.enabled' => false]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->interfaceRoot = app(CreateTask::class)->handle('orbit', 'Feature', 'Feature objective.', TaskKind::Group, acceptanceCriteria: 'Feature works.');
    $this->interfaceTask = app(CreateTask::class)->handle('orbit', 'Task', 'Task objective.', parent: $this->interfaceRoot, acceptanceCriteria: 'Focused checks pass.');
    $this->interfaceServer = null;
    Queue::fake();
});

afterEach(function () {
    $this->interfaceServer?->stop();
    Sleep::fake(false);
    File::deleteDirectory($this->runtimeInterfaceDirectory);
});

function taskInterfaceWorkspace(?string $socket = null, array $agentArguments = [], ?string $worktree = null): TaskWorkspace
{
    return TaskWorkspace::query()->create([
        'root_task_id' => test()->interfaceRoot->id, 'project_id' => 'orbit', 'source_key' => 'ORB-TEST',
        'repository' => test()->runtimeInterfaceDirectory.'/repository', 'worktree' => $worktree ?? test()->runtimeInterfaceDirectory.'/worktree',
        'base_sha' => str_repeat('a', 40), 'manifest_hash' => app(TaskRuntimePlan::class)->hash(test()->interfaceRoot),
        'configuration' => ['socket' => $socket ?? '/unused.sock', 'agent_kind' => 'codex', 'agent_arguments' => $agentArguments],
    ]);
}

function taskInterfaceDispatch(TaskWorkspace $workspace): TaskAgentDispatch
{
    $run = app(StartTaskRun::class)->handle(test()->interfaceTask, 'interface-start', 'worker', 'reviewer', baseSha: $workspace->base_sha);

    return $workspace->dispatches()->create([
        'task_run_id' => $run->id, 'step_key' => $run->id.':implement:0', 'kind' => 'implement', 'round' => 0,
        'state' => 'sent', 'token_hash' => hash('sha256', 'interface-secret'), 'handoff_token' => 'interface-secret',
        'prompt' => 'Private instruction interface-secret', 'session' => ['agentName' => 'worker'],
    ]);
}

it('checks audited native processes at task and supplemental review prompt boundaries', function (string $adapter, bool $changed) {
    $pane = taskInterfacePane();
    $pane['agent_status'] = 'idle';
    $pane['agent_session'] = null;
    $workspace = taskInterfaceHerdr([
        'agent.get' => ['type' => 'agent_info', 'agent' => $pane],
        'agent.prompt' => ['type' => 'agent_prompted', 'agent' => $pane],
    ]);
    $dispatch = taskInterfaceDispatch($workspace);
    $session = ['workspaceId' => 'w1', 'tabId' => 't1', 'paneId' => 'p1', 'terminalId' => 'term1',
        'agentName' => 'worker', 'workingDirectory' => $workspace->worktree, 'agentId' => null];
    $observer = new class($changed) implements TaskSessionObserver
    {
        public int $calls = 0;

        public function __construct(private bool $changed) {}

        public function observe(TaskWorkspace $workspace, array $session, string $conversation, bool $yielded): array
        {
            $this->calls++;

            return ['session' => $session, 'process' => ['pid' => 42, 'start_time' => $this->changed ? 'replacement' : 'original']];
        }
    };
    app()->instance(TaskSessionObserver::class, $observer);
    TaskSessionReconnection::query()->create(['task_workspace_id' => $workspace->id, 'task_agent_dispatch_id' => $dispatch->id,
        'request_hash' => str_repeat('a', 64), 'request' => [], 'binding' => [], 'created_at' => now(),
        'sessions' => ['implementer' => ['previous' => [...$session, 'terminalId' => 'old', 'agentId' => '11111111-1111-4111-8111-111111111111'], 'current' => $session,
            'conversation_id' => '11111111-1111-4111-8111-111111111111', 'process' => ['pid' => 42, 'start_time' => 'original']]]]);
    $resolver = app(TaskAgentSessions::class);
    expect($resolver->resolve($workspace, [...$session, 'terminalId' => 'old', 'agentId' => '11111111-1111-4111-8111-111111111111']))->toBe($session)
        ->and($resolver->resolve($workspace, [...$session, 'agentId' => '11111111-1111-4111-8111-111111111111']))->toBe($session);
    $send = $adapter === 'task'
        ? fn () => app(HerdrTaskAgents::class)->prompt($workspace, $session, 'Scoped next assignment')
        : fn () => app(HerdrTaskLandingReviewer::class)->promptOnce($workspace, $session, 'Scoped package review');
    if ($changed) {
        expect($send)->toThrow(LogicException::class, 'resumed Codex process changed');
    } else {
        expect($send()['terminalId'])->toBe('term1');
    }
    expect($observer->calls)->toBe(1)
        ->and(count(array_filter($this->interfaceServer->requests(), fn ($request) => $request['method'] === 'agent.prompt')))->toBe($changed ? 0 : 1);
})->with([['task', true], ['task', false], ['landing', true], ['landing', false]]);

function taskInterfacePane(): array
{
    return [
        'workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'term1',
        'agent' => 'codex', 'name' => 'worker', 'agent_status' => 'working',
        'agent_session' => ['agent' => 'codex', 'kind' => 'thread', 'source' => 'herdr:codex', 'value' => 'thread-1'],
        'cwd' => test()->runtimeInterfaceDirectory.'/worktree', 'focused' => false, 'revision' => 2, 'state_change_seq' => 3,
    ];
}

function taskInterfaceHerdr(array $overrides = [], array $sequences = [], array $agentArguments = [], ?string $worktree = null): TaskWorkspace
{
    $pane = taskInterfacePane();
    $pane['cwd'] = $worktree ?? $pane['cwd'];
    test()->interfaceServer = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc' => array_replace([
            'worktree.open' => [
                'type' => 'worktree_opened', 'workspace' => ['workspace_id' => 'w1'],
                'tab' => ['tab_id' => 't1'], 'root_pane' => $pane,
                'worktree' => ['path' => $pane['cwd']], 'already_open' => false,
            ],
            'tab.create' => ['type' => 'tab_created', 'tab' => ['tab_id' => 't2'], 'root_pane' => $pane],
            'agent.start' => ['type' => 'agent_started', 'agent' => $pane, 'argv' => ['codex']],
            'agent.get' => ['type' => 'agent_info', 'agent' => $pane],
            'agent.prompt' => ['type' => 'agent_prompted', 'agent' => $pane],
        ], $overrides),
        'rpc_sequences' => $sequences,
    ]);

    return taskInterfaceWorkspace(test()->interfaceServer->socketPath, $agentArguments, $worktree);
}

it('inspects approved briefs while disabled without admitting or dispatching work', function () {
    expect(Artisan::call('tasks:inspect', ['project' => 'orbit', 'task' => $this->interfaceRoot->id]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['manifest_hash'])->toBe(app(TaskRuntimePlan::class)->hash($this->interfaceRoot))
        ->and($output['manifest']['children'][0]['id'])->toBe($this->interfaceTask->id)
        ->and($output['workspace'])->toBeNull()
        ->and(TaskWorkspace::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('never exposes handoff tokens or prompts through inspection or model serialization', function () {
    $workspace = taskInterfaceWorkspace();
    $dispatch = taskInterfaceDispatch($workspace);
    expect(Artisan::call('tasks:inspect', ['project' => 'orbit', 'task' => $this->interfaceRoot->id]))->toBe(0);
    expect(Artisan::output())->not->toContain('interface-secret', 'Private instruction', 'token_hash', 'handoff_token')
        ->and($dispatch->toJson())->not->toContain('interface-secret', 'Private instruction', 'token_hash', 'handoff_token')
        ->and($dispatch->getRawOriginal('prompt'))->not->toContain('Private instruction')
        ->and($dispatch->getRawOriginal('handoff_token'))->not->toBe('interface-secret');
    Queue::assertNothingPushed();
});

it('keeps kickoff disabled by default and requires explicit adoption when enabled', function (bool $enabled) {
    config(['task-runtime.enabled' => $enabled]);
    $this->artisan('tasks:start', ['project' => 'orbit', 'task' => $this->interfaceRoot->id])
        ->expectsOutputToContain('explicitly confirm exclusive')
        ->assertFailed();
    expect(TaskWorkspace::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([false, true]);

it('uses a separate durable queue with a timeout below redelivery', function () {
    $job = new AdvanceTaskRunner(123);
    expect($job->connection)->toBe('task-runtime')->and($job->queue)->toBe('tasks')
        ->and($job->tries)->toBe(1)
        ->and(config('queue.connections.task-runtime.driver'))->toBe('database')
        ->and(config('queue.connections.task-runtime.after_commit'))->toBeTrue()
        ->and(config('queue.connections.task-runtime.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(unserialize(serialize($job))->executionKey)->toBe($job->executionKey)
        ->and((new AdvanceTaskRunner(123))->executionKey)->not->toBe($job->executionKey);
});

it('accepts a scoped blocked handoff through the CLI and queues reconciliation without idle', function () {
    $workspace = taskInterfaceWorkspace();
    $dispatch = taskInterfaceDispatch($workspace);
    $file = $this->runtimeInterfaceDirectory.'/receipt.json';
    File::put($file, json_encode(['token' => 'interface-secret', 'summary' => 'Need task clarification.', 'evidence' => 'Inspected assignment.', 'verdict' => 'blocked'], JSON_THROW_ON_ERROR));
    $cwd = getcwd();
    try {
        chdir($workspace->worktree);
        $this->artisan('tasks:submit', ['dispatch' => $dispatch->id, '--file' => $file])
            ->expectsOutputToContain('Handoff recorded')->assertSuccessful();
    } finally {
        chdir($cwd);
    }
    expect($dispatch->fresh()->state)->toBe('acknowledged')
        ->and($workspace->fresh()->attention)->toBe('Need task clarification.')
        ->and($dispatch->fresh()->result)->not->toHaveKey('token');
    Queue::assertPushed(AdvanceTaskRunner::class, fn ($job) => $job->workspaceId === $workspace->id);
});

it('rejects CLI handoffs from another checkout before reading the file', function () {
    $dispatch = taskInterfaceDispatch(taskInterfaceWorkspace());
    $this->artisan('tasks:submit', ['dispatch' => $dispatch->id, '--file' => '/missing'])
        ->expectsOutputToContain('exact assigned feature worktree')->assertFailed();
    expect($dispatch->fresh()->state)->toBe('sent');
    Queue::assertNothingPushed();
});

it('rejects unsafe paths and malformed handoff JSON without advancing', function (string $case) {
    $workspace = taskInterfaceWorkspace();
    $dispatch = taskInterfaceDispatch($workspace);
    $file = $this->runtimeInterfaceDirectory.'/receipt.json';
    $contents = match ($case) {
        'list' => '[]', 'invalid' => '{', 'numeric-key' => '{"0": "bad", "summary": "bad"}',
        'oversized' => str_repeat('x', 262_145), default => '{}',
    };
    File::put($file, $contents);
    if ($case === 'inside') {
        $file = $workspace->worktree.'/receipt.json';
        File::put($file, '{}');
    } elseif ($case === 'symlink-inside') {
        File::put($workspace->worktree.'/receipt.json', '{}');
        unlink($file);
        symlink($workspace->worktree.'/receipt.json', $file);
    } elseif ($case === 'relative') {
        $file = '../receipt.json';
    }
    $cwd = getcwd();
    try {
        chdir($workspace->worktree);
        $this->artisan('tasks:submit', ['dispatch' => $dispatch->id, '--file' => $file])->assertFailed();
    } finally {
        chdir($cwd);
    }
    expect($dispatch->fresh()->state)->toBe('sent');
    Queue::assertNothingPushed();
})->with(['list', 'invalid', 'numeric-key', 'oversized', 'inside', 'symlink-inside', 'relative']);

it('pins workspace configuration and dispatch assignment against later model edits', function () {
    $workspace = taskInterfaceWorkspace();
    $dispatch = taskInterfaceDispatch($workspace);
    expect(fn () => $workspace->update(['configuration' => ['socket' => '/replacement.sock']]))->toThrow(LogicException::class, 'immutable');
    expect(fn () => $dispatch->update(['round' => 22]))->toThrow(LogicException::class, 'immutable');
    expect(fn () => $workspace->fresh()->delete())->toThrow(LogicException::class, 'history');
    expect(fn () => $dispatch->fresh()->delete())->toThrow(LogicException::class, 'history');
    $dispatch->refresh()->update(['state' => 'acknowledged', 'result' => ['summary' => 'Done']]);
    expect(fn () => $dispatch->update(['result' => ['summary' => 'Replacement']]))->toThrow(LogicException::class, 'immutable');
    $workspace->refresh()->update(['final_result' => ['verdict' => 'pass']]);
    expect(fn () => $workspace->update(['final_result' => ['verdict' => 'revise']]))->toThrow(LogicException::class, 'immutable');
});

it('opens a dedicated Herdr workspace and full-width tabs without an idle gate', function () {
    Sleep::fake();
    $workspace = taskInterfaceHerdr();
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    $prompted = $agents->prompt($workspace, $session, 'Task instruction');
    $agents->start($workspace->fresh(), 'worker');
    expect($prompted['agentId'])->toBe('thread-1')
        ->and($prompted['agentStatus'])->toBe('working')
        ->and($workspace->fresh()->herdr_workspace['workspaceId'])->toBe('w1');
    $requests = $this->interfaceServer->requests();
    expect(array_column($requests, 'method'))->toBe(['worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'tab.create', 'agent.start'])
        ->and($requests[3]['params'])->toBe(['target' => 'worker', 'text' => 'Task instruction']);
    Sleep::assertNeverSlept();
});

it('expands worktree arguments for each launch without changing the admitted configuration', function (string $directory) {
    $arguments = [
        '--model', 'gpt-5.6-sol',
        '-c', 'mcp_servers.orbit-cli-boost.cwd={worktree}/apps/cli',
        '-c', 'mcp_servers.orbit-gateway-boost.cwd={worktree}/apps/gateway',
        '--config', 'mcp_servers.orbit-cli-boost.cwd=/explicit/override',
    ];
    $worktree = $this->runtimeInterfaceDirectory.'/'.$directory;
    $workspace = taskInterfaceHerdr(agentArguments: $arguments, worktree: $worktree);
    $configuration = $workspace->configuration;
    config(['task-runtime.projects.orbit.agent_arguments' => ['--model', 'different-model']]);
    $agents = app(HerdrTaskAgents::class);

    $agents->start($workspace, 'worker');
    $agents->start($workspace->fresh(), 'worker');

    $requests = $this->interfaceServer->requests();
    expect(array_column($requests, 'method'))->toBe(['worktree.open', 'agent.start', 'tab.create', 'agent.start'])
        ->and($requests[2]['params'])->toBe([
            'workspace_id' => 'w1',
            'cwd' => $worktree,
            'focus' => false,
            'label' => 'worker',
        ]);
    foreach ([$requests[1], $requests[3]] as $request) {
        expect($request['params']['args'])->toBe([
            '--model', 'gpt-5.6-sol',
            '-c', 'mcp_servers.orbit-cli-boost.cwd='.$worktree.'/apps/cli',
            '-c', 'mcp_servers.orbit-gateway-boost.cwd='.$worktree.'/apps/gateway',
            '--config', 'mcp_servers.orbit-cli-boost.cwd=/explicit/override',
        ]);
    }
    expect($workspace->fresh()->configuration)->toBe($configuration);
    Queue::assertNothingPushed();
})->with(['worktree', 'work tree "quoted" \\path é = value']);

it('rejects invalid launch arguments before opening a Herdr workspace or tab', function (bool $alreadyOpened) {
    $workspace = taskInterfaceHerdr(agentArguments: ['--model', false]);
    if ($alreadyOpened) {
        $workspace->update(['herdr_workspace' => ['workspaceId' => 'w1', 'paneId' => 'p1']]);
    }

    expect(fn () => (app(HerdrTaskAgents::class))->start($workspace, 'worker'))->toThrow(LogicException::class, 'Invalid agent argument')
        ->and($this->interfaceServer->requests())->toBe([]);
})->with([false, true]);

it('waits through the Tasks startup window for explicit pre-write prompt refusals', function () {
    Sleep::fake();
    $pending = taskInterfacePane();
    $pending['agent_session'] = null;
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $pending, 'argv' => ['codex']],
    ], ['agent.prompt' => [
        ...array_fill(0, 60, ['error' => ['code' => 'agent_not_ready', 'message' => 'Launch pending; prompt not written']]),
        ['result' => ['type' => 'agent_prompted', 'agent' => taskInterfacePane()]],
    ]]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    $dispatch = taskInterfaceDispatch($workspace);
    $before = [$workspace->fresh()->getRawOriginal(), $dispatch->fresh()->getRawOriginal()];

    expect($session['agentId'])->toBeNull()
        ->and($agents->prompt($workspace, $session, 'One retained instruction')['agentId'])->toBe('thread-1');
    $requests = $this->interfaceServer->requests();
    expect($requests[1]['params']['timeout_ms'])->toBe(15_000)
        ->and(array_column($requests, 'method'))->toBe([
            'worktree.open', 'agent.start', ...array_merge(...array_fill(0, 61, ['agent.get', 'agent.prompt'])),
        ])
        ->and([$workspace->fresh()->getRawOriginal(), $dispatch->fresh()->getRawOriginal()])->toBe($before)
        ->and(TaskAgentDispatch::query()->count())->toBe(1);
    foreach ($requests as $request) {
        if ($request['method'] === 'agent.prompt') {
            expect($request['params'])->toBe(['target' => 'worker', 'text' => 'One retained instruction']);
        }
    }
    Sleep::assertSequence(array_fill(0, 60, Sleep::for(250)->milliseconds()));
    Queue::assertNothingPushed();
});

it('exhausts one Tasks startup refusal budget without restarting or changing the assignment', function () {
    Sleep::fake();
    $workspace = taskInterfaceHerdr(sequences: [
        'agent.prompt' => [['error' => ['code' => 'agent_not_ready', 'message' => 'Still pending']]],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    $dispatch = taskInterfaceDispatch($workspace);
    $before = [$workspace->fresh()->getRawOriginal(), $dispatch->fresh()->getRawOriginal()];

    expect(fn () => $agents->prompt($workspace, $session, 'One retained instruction'))->toThrow(RequestFailed::class, 'Still pending')
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', ...array_merge(...array_fill(0, 61, ['agent.get', 'agent.prompt'])),
        ])
        ->and([$workspace->fresh()->getRawOriginal(), $dispatch->fresh()->getRawOriginal()])->toBe($before)
        ->and(TaskAgentDispatch::query()->count())->toBe(1);
    Sleep::assertSequence(array_fill(0, 60, Sleep::for(250)->milliseconds()));
    Queue::assertNothingPushed();
});

it('accepts an originally absent conversation whether it appears during readiness or stays absent', function (bool $appears) {
    Sleep::fake();
    $pending = taskInterfacePane();
    $pending['agent_session'] = null;
    $ready = $appears ? taskInterfacePane() : $pending;
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $pending, 'argv' => ['codex']],
    ], [
        'agent.get' => array_map(fn (array $pane): array => ['result' => ['type' => 'agent_info', 'agent' => $pane]], [$pending, $ready]),
        'agent.prompt' => [
            ['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']],
            ['result' => ['type' => 'agent_prompted', 'agent' => $ready]],
        ],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect($agents->prompt($workspace, $session, 'One instruction')['agentId'])->toBe($appears ? 'thread-1' : null)
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get', 'agent.prompt',
        ]);
    Sleep::assertSleptTimes(1);
})->with([false, true]);

it('rechecks retained identity and pins a newly observed conversation on every refusal attempt', function (string $field) {
    Sleep::fake();
    $pending = taskInterfacePane();
    $pending['agent_session'] = null;
    $replacement = taskInterfacePane();
    if ($field === 'agent_session') {
        $replacement[$field]['value'] = 'replacement';
    } elseif ($field === 'missing_conversation') {
        $replacement['agent_session'] = null;
    } else {
        $replacement[$field] = 'replacement';
    }
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $pending, 'argv' => ['codex']],
    ], [
        'agent.get' => [
            ['result' => ['type' => 'agent_info', 'agent' => taskInterfacePane()]],
            ['result' => ['type' => 'agent_info', 'agent' => $replacement]],
        ],
        'agent.prompt' => [
            ['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']],
            ['result' => ['type' => 'agent_prompted', 'agent' => taskInterfacePane()]],
        ],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->prompt($workspace, $session, 'Never prompt replacement'))->toThrow(LogicException::class)
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get',
        ]);
    Sleep::assertSleptTimes(1);
})->with(['workspace_id', 'tab_id', 'pane_id', 'terminal_id', 'name', 'cwd', 'agent_session', 'missing_conversation']);

it('retains the first conversation that appears after an initial refusal', function () {
    Sleep::fake();
    $pending = taskInterfacePane();
    $pending['agent_session'] = null;
    $replacement = taskInterfacePane();
    $replacement['agent_session']['value'] = 'replacement';
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $pending, 'argv' => ['codex']],
    ], [
        'agent.get' => array_map(fn (array $pane): array => ['result' => ['type' => 'agent_info', 'agent' => $pane]],
            [$pending, taskInterfacePane(), $replacement]),
        'agent.prompt' => [['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']]],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->prompt($workspace, $session, 'Never prompt replacement'))->toThrow(LogicException::class, 'conversation')
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get', 'agent.prompt', 'agent.get',
        ]);
    Sleep::assertSleptTimes(2);
});

it('does not retry observation failures after an explicit prompt refusal', function (string $code) {
    Sleep::fake();
    $workspace = taskInterfaceHerdr(sequences: [
        'agent.get' => [
            ['result' => ['type' => 'agent_info', 'agent' => taskInterfacePane()]],
            ['error' => ['code' => $code, 'message' => 'Observation unavailable']],
        ],
        'agent.prompt' => [
            ['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']],
            ['result' => ['type' => 'agent_prompted', 'agent' => taskInterfacePane()]],
        ],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->prompt($workspace, $session, 'One instruction'))->toThrow(RequestFailed::class, 'Observation unavailable')
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get',
        ]);
    Sleep::assertSleptTimes(1);
})->with(['agent_not_ready', 'agent_not_found', 'internal_error']);

it('stops on any other prompt error after an explicit startup refusal', function (string $code) {
    Sleep::fake();
    $workspace = taskInterfaceHerdr(sequences: [
        'agent.prompt' => [
            ['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']],
            ['error' => ['code' => $code, 'message' => 'Do not resend']],
            ['result' => ['type' => 'agent_prompted', 'agent' => taskInterfacePane()]],
        ],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->prompt($workspace, $session, 'One instruction'))->toThrow(RequestFailed::class, 'Do not resend')
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get', 'agent.prompt',
        ]);
    Sleep::assertSleptTimes(1);
})->with(['internal_error', 'agent_not_found', 'agent_blocked']);

it('does not retry malformed or changed prompt responses after a startup refusal', function (bool $malformed) {
    Sleep::fake();
    $replacement = taskInterfacePane();
    $replacement['agent_session']['value'] = 'replacement';
    $workspace = taskInterfaceHerdr(sequences: [
        'agent.prompt' => [
            ['error' => ['code' => 'agent_not_ready', 'message' => 'Not written']],
            ['result' => ['type' => $malformed ? 'unexpected' : 'agent_prompted', 'agent' => $replacement]],
            ['result' => ['type' => 'agent_prompted', 'agent' => taskInterfacePane()]],
        ],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->prompt($workspace, $session, 'One instruction'))->toThrow($malformed ? InvalidArgumentException::class : LogicException::class)
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe([
            'worktree.open', 'agent.start', 'agent.get', 'agent.prompt', 'agent.get', 'agent.prompt',
        ]);
    Sleep::assertSleptTimes(1);
})->with([false, true]);

it('refuses to adopt an already open Herdr worktree', function () {
    $pane = taskInterfacePane();
    $workspace = taskInterfaceHerdr(['worktree.open' => [
        'type' => 'worktree_opened', 'workspace' => ['workspace_id' => 'w1'], 'tab' => ['tab_id' => 't1'],
        'root_pane' => $pane, 'worktree' => ['path' => $pane['cwd']], 'already_open' => true,
    ]]);
    expect(fn () => (app(HerdrTaskAgents::class))->start($workspace, 'worker'))->toThrow(LogicException::class, 'already open');
    expect(array_column($this->interfaceServer->requests(), 'method'))->toBe(['worktree.open']);
});

it('rejects replacement identities before sending any prompt', function (string $field) {
    $replacement = taskInterfacePane();
    if ($field === 'agent_session') {
        $replacement[$field]['value'] = 'replacement';
    } else {
        $replacement[$field] = 'replacement';
    }
    $workspace = taskInterfaceHerdr(['agent.get' => ['type' => 'agent_info', 'agent' => $replacement]]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    expect(fn () => $agents->prompt($workspace, $session, 'Do not send'))->toThrow(LogicException::class);
    expect(array_column($this->interfaceServer->requests(), 'method'))->not->toContain('agent.prompt');
})->with(['workspace_id', 'tab_id', 'pane_id', 'terminal_id', 'name', 'cwd', 'agent_session']);

it('pins a newly observed conversation before checking the prompt response', function (bool $changed) {
    $initial = taskInterfacePane();
    $initial['agent_session'] = null;
    $after = taskInterfacePane();
    if ($changed) {
        $after['agent_session']['value'] = 'replacement';
    }
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $initial, 'argv' => ['codex']],
        'agent.prompt' => ['type' => 'agent_prompted', 'agent' => $after],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    expect($session['agentId'])->toBeNull();
    if ($changed) {
        expect(fn () => $agents->prompt($workspace, $session, 'Task instruction'))->toThrow(LogicException::class, 'conversation');
    } else {
        expect($agents->prompt($workspace, $session, 'Task instruction')['agentId'])->toBe('thread-1');
    }
})->with([false, true]);

it('does not retry an ambiguous Herdr prompt error', function () {
    $workspace = taskInterfaceHerdr(sequences: [
        'agent.prompt' => [['error' => ['code' => 'internal_error', 'message' => 'Outcome uncertain']]],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    expect(fn () => $agents->prompt($workspace, $session, 'Task instruction'))->toThrow(RequestFailed::class);
    expect(array_count_values(array_column($this->interfaceServer->requests(), 'method'))['agent.prompt'])->toBe(1);
});

it('observes ordinary retained identity without requiring an originally absent native UUID', function (?string $observedId) {
    $initial = taskInterfacePane();
    $initial['agent_session'] = null;
    $observed = taskInterfacePane();
    if ($observedId === null) {
        $observed['agent_session'] = null;
    }
    $workspace = taskInterfaceHerdr([
        'agent.start' => ['type' => 'agent_started', 'agent' => $initial, 'argv' => ['codex']],
        'agent.get' => ['type' => 'agent_info', 'agent' => $observed],
    ]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');
    $agents->assertSession($workspace, $session);

    expect($session['agentId'])->toBeNull()
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe(['worktree.open', 'agent.start', 'agent.get']);
})->with([null, 'thread-1']);

it('refuses changed retained reviewer identity during read only observation', function (string $field) {
    $replacement = taskInterfacePane();
    if ($field === 'agent_session') {
        $replacement[$field]['value'] = 'replacement';
    } else {
        $replacement[$field] = 'replacement';
    }
    $workspace = taskInterfaceHerdr(['agent.get' => ['type' => 'agent_info', 'agent' => $replacement]]);
    $agents = app(HerdrTaskAgents::class);
    $session = $agents->start($workspace, 'worker');

    expect(fn () => $agents->assertSession($workspace, $session))->toThrow(LogicException::class)
        ->and(array_column($this->interfaceServer->requests(), 'method'))->toBe(['worktree.open', 'agent.start', 'agent.get']);
})->with(['workspace_id', 'tab_id', 'pane_id', 'terminal_id', 'name', 'cwd', 'agent_session']);
