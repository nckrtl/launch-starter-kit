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
        'focused' => false, 'revision' => 2, 'state_change_seq' => 3,
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

    expect($runtime->openWorktree('/tmp/repository', '/tmp/worktree')->paneId)->toBe('p1')
        ->and($runtime->splitPane('p1', '/tmp/worktree')->terminalId)->toBe('term1')
        ->and($runtime->startAgent('p1', 'commander-1')->stateChangeSeq)->toBe(3)
        ->and($runtime->promptAgent('commander-1', 'safe prompt')->agentName)->toBe('commander-1')
        ->and($runtime->getAgent('commander-1')->paneId)->toBe('p1')
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
