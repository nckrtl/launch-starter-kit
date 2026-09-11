<?php

use App\Delivery\Data\HerdrAgentLaunch;
use App\Herdr\RequestFailed;
use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHerdrServer;

beforeEach(function () {
    $pane = [
        'workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'term1',
        'agent' => 'codex', 'name' => 'commander-1', 'agent_status' => 'working',
        'agent_session' => [
            'agent' => 'codex', 'kind' => 'thread', 'source' => 'herdr:codex', 'value' => 'thread-1',
        ],
        'cwd' => '/tmp/worktree', 'focused' => false, 'revision' => 2, 'state_change_seq' => 3,
    ];
    $this->server = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc' => [
            'worktree.open' => [
                'type' => 'worktree_opened', 'workspace' => ['workspace_id' => 'w1'],
                'tab' => ['tab_id' => 't1'], 'root_pane' => $pane,
                'worktree' => ['path' => '/tmp/worktree'], 'already_open' => false,
            ],
            'pane.split' => ['type' => 'pane_info', 'pane' => $pane],
            'agent.start' => ['type' => 'agent_started', 'agent' => $pane, 'argv' => ['codex']],
            'agent.prompt' => ['type' => 'agent_prompted', 'agent' => $pane],
            'agent.get' => ['type' => 'agent_info', 'agent' => $pane],
        ],
    ]);
});

afterEach(function () {
    $this->server->stop();
    Sleep::fake(false);
});

it('maps protocol 22 orchestration responses and sends exact methods', function () {
    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));
    $opened = $runtime->openWorktree('/tmp/repository', '/tmp/worktree');
    $pane = $runtime->splitPane('p1', '/tmp/worktree');
    $started = $runtime->startAgent('p1', 'commander-1');
    $prompted = $runtime->promptAgent('commander-1', 'safe prompt');
    $agent = $runtime->getAgent('commander-1');

    expect($opened->paneId)->toBe('p1')
        ->and($pane->terminalId)->toBe('term1')
        ->and($started->agentId)->toBe('thread-1')
        ->and($started->stateChangeSeq)->toBe(3)
        ->and($prompted->agentName)->toBe('commander-1')
        ->and($agent->paneId)->toBe('p1')
        ->and($agent->workingDirectory)->toBe('/tmp/worktree')
        ->and($agent->agentStatus)->toBe('working')
        ->and(array_column($this->server->requests(), 'method'))->toBe([
            'worktree.open', 'pane.split', 'agent.start', 'agent.prompt', 'agent.get',
        ])
        ->and($this->server->requests()[0]['params'])->toBe([
            'cwd' => '/tmp/repository',
            'path' => '/tmp/worktree',
            'focus' => false,
            'trust_repository' => false,
        ]);
});

it('passes a workflow-owned label and agent launch profile to Herdr', function () {
    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));
    $launch = new HerdrAgentLaunch('codex', ['-m', 'gpt-5.6-sol'], 120_000);

    $runtime->openWorktree('/tmp/repository', '/tmp/worktree', 'ORB-234');
    $runtime->startAgent('p1', 'orb-234-loop-builder', $launch);

    expect($this->server->requests()[0]['params'])->toBe([
        'cwd' => '/tmp/repository',
        'path' => '/tmp/worktree',
        'focus' => false,
        'trust_repository' => false,
        'label' => 'ORB-234',
    ])->and($this->server->requests()[1]['params'])->toBe([
        'pane_id' => 'p1',
        'name' => 'orb-234-loop-builder',
        'kind' => 'codex',
        'args' => ['-m', 'gpt-5.6-sol'],
        'timeout_ms' => 120_000,
    ]);
});

it('retries a prompt while the named agent is becoming ready', function () {
    Sleep::fake();
    $this->server->stop();
    $this->server = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc_sequences' => [
            'agent.prompt' => [
                ['error' => ['code' => 'agent_not_ready', 'message' => 'agent is not active yet']],
                ['result' => [
                    'type' => 'agent_prompted',
                    'agent' => [
                        'workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1', 'terminal_id' => 'term1',
                        'agent' => 'codex', 'name' => 'commander-1', 'state_change_seq' => 4,
                    ],
                ]],
            ],
        ],
    ]);

    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));

    expect($runtime->promptAgent('commander-1', 'safe prompt')->stateChangeSeq)->toBe(4)
        ->and(array_column($this->server->requests(), 'method'))->toBe(['agent.prompt', 'agent.prompt']);
    Sleep::assertSleptTimes(1);
});

it('does not retry prompt errors other than agent not ready', function () {
    Sleep::fake();
    $this->server->stop();
    $this->server = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc_sequences' => [
            'agent.prompt' => [
                ['error' => ['code' => 'agent_blocked', 'message' => 'agent is blocked']],
                ['result' => ['type' => 'agent_prompted', 'agent' => []]],
            ],
        ],
    ]);

    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));

    try {
        $runtime->promptAgent('commander-1', 'safe prompt');
    } catch (RequestFailed $exception) {
        $failure = $exception;
    }

    expect($failure ?? null)->toBeInstanceOf(RequestFailed::class)
        ->and($failure->errorCode)->toBe('agent_blocked')
        ->and(array_column($this->server->requests(), 'method'))->toBe(['agent.prompt']);
    Sleep::assertNeverSlept();
});

it('maps protocol 22 workspace cleanup responses and sends exact safe requests', function () {
    $this->server->stop();
    $this->server = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc' => [
            'session.snapshot' => [
                'type' => 'session_snapshot',
                'snapshot' => [
                    'version' => '0.9.0',
                    'protocol' => 22,
                    'workspaces' => [[
                        'workspace_id' => 'w1',
                        'worktree' => [
                            'repo_root' => '/tmp/repository',
                            'checkout_path' => '/tmp/worktree',
                            'is_linked_worktree' => true,
                        ],
                    ]],
                    'tabs' => [],
                    'panes' => [[
                        'workspace_id' => 'w1', 'tab_id' => 't1',
                        'pane_id' => 'p1', 'terminal_id' => 'term1',
                        'cwd' => '/tmp/worktree',
                    ]],
                    'layouts' => [],
                    'agents' => [[
                        'workspace_id' => 'w1', 'tab_id' => 't1',
                        'pane_id' => 'p1', 'terminal_id' => 'term1',
                        'agent' => 'codex', 'name' => 'orb-234-loop-builder',
                        'agent_status' => 'idle', 'cwd' => '/tmp/worktree',
                    ]],
                ],
            ],
            'agent.read' => [
                'type' => 'pane_read',
                'read' => [
                    'workspace_id' => 'w1', 'tab_id' => 't1', 'pane_id' => 'p1',
                    'source' => 'recent_unwrapped', 'format' => 'text',
                    'text' => '> /quit',
                ],
            ],
            'agent.send_keys' => ['type' => 'ok'],
            'pane.process_info' => [
                'type' => 'pane_process_info',
                'process_info' => [
                    'pane_id' => 'p1', 'shell_pid' => 100,
                    'foreground_process_group_id' => 100,
                    'foreground_processes' => [['pid' => 100, 'name' => 'zsh']],
                ],
            ],
            'workspace.close' => ['type' => 'ok'],
        ],
    ]);
    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));

    $snapshot = $runtime->snapshot();
    $output = $runtime->readAgent('orb-234-loop-builder');
    $runtime->sendAgentKeys('orb-234-loop-builder', ['/', 'q', 'u', 'i', 't', 'enter']);
    $process = $runtime->inspectPaneProcess('p1');
    $runtime->closeWorkspace('w1', 22);
    $runtime->closeWorkspace('w1', 20);

    expect($snapshot->protocol)->toBe(22)
        ->and($snapshot->workspaces[0]->checkoutPath)->toBe('/tmp/worktree')
        ->and($snapshot->agents[0]->status)->toBe('idle')
        ->and($output->text)->toBe('> /quit')
        ->and($process->foregroundProcesses[0]->name)->toBe('zsh');

    $requests = $this->server->requests();
    expect(array_column($requests, 'method'))->toBe([
        'session.snapshot', 'agent.read', 'agent.send_keys',
        'pane.process_info', 'workspace.close', 'workspace.close',
    ])->and(array_column($requests, 'params'))->toBe([
        [],
        [
            'target' => 'orb-234-loop-builder',
            'source' => 'recent_unwrapped',
            'format' => 'text',
            'lines' => 160,
            'strip_ansi' => true,
        ],
        [
            'target' => 'orb-234-loop-builder',
            'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
        ],
        ['pane_id' => 'p1'],
        ['workspace_id' => 'w1', 'close_group' => false],
        ['workspace_id' => 'w1'],
    ]);
});

it('rejects malformed workspace cleanup snapshots', function () {
    $this->server->stop();
    $this->server = FakeHerdrServer::start([
        'agents' => [], 'workspaces' => [], 'events' => [],
        'rpc' => [
            'session.snapshot' => [
                'type' => 'session_snapshot',
                'snapshot' => [
                    'version' => '0.9.0', 'protocol' => 22,
                    'workspaces' => [], 'tabs' => [], 'layouts' => [],
                    'panes' => 'not-a-list', 'agents' => [],
                ],
            ],
        ],
    ]);

    expect(fn () => (new SocketHerdrRuntime(new SocketClient($this->server->socketPath)))->snapshot())
        ->toThrow(InvalidArgumentException::class, 'missing [panes]');
});
