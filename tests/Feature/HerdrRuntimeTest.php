<?php

use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
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

afterEach(fn () => $this->server->stop());

it('maps protocol 22 orchestration responses and sends exact methods', function () {
    $runtime = new SocketHerdrRuntime(new SocketClient($this->server->socketPath));

    expect($runtime->openWorktree('/tmp/worktree')->paneId)->toBe('p1')
        ->and($runtime->splitPane('p1', '/tmp/worktree')->terminalId)->toBe('term1')
        ->and($runtime->startAgent('p1', 'commander-1')->stateChangeSeq)->toBe(3)
        ->and($runtime->promptAgent('commander-1', 'safe prompt')->agentName)->toBe('commander-1')
        ->and($runtime->getAgent('commander-1')->paneId)->toBe('p1')
        ->and(array_column($this->server->requests(), 'method'))->toBe([
            'worktree.open', 'pane.split', 'agent.start', 'agent.prompt', 'agent.get',
        ]);
});
