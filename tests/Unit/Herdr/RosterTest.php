<?php

use App\Herdr\Roster;

beforeEach(function () {
    $this->roster = Roster::fromPayloads(
        agents: [
            ['pane_id' => 'w7:p1', 'workspace_id' => 'w7', 'agent' => 'codex', 'name' => 'orb15-impl', 'agent_status' => 'done'],
            ['pane_id' => 'w7:p3', 'workspace_id' => 'w7', 'agent' => 'claude', 'name' => null, 'display_agent' => 'Claude Code', 'agent_status' => 'idle'],
            ['pane_id' => 'wV:p1', 'workspace_id' => 'wV', 'agent' => null, 'agent_status' => 'unknown'],
        ],
        workspaces: [
            ['workspace_id' => 'w7', 'label' => 'ORB-15'],
            ['workspace_id' => 'wV', 'label' => 'Orbit'],
        ],
    );
});

it('lists the panes and their statuses', function () {
    expect($this->roster->paneIds())->toBe(['w7:p1', 'w7:p3', 'wV:p1'])
        ->and($this->roster->statuses())->toBe(['w7:p1' => 'done', 'w7:p3' => 'idle', 'wV:p1' => 'unknown']);
});

it('names an agent by its name, then its display name, then its kind', function () {
    expect($this->roster->agentName('w7:p1'))->toBe('orb15-impl')
        ->and($this->roster->agentName('w7:p3'))->toBe('Claude Code')
        ->and($this->roster->agentName('wV:p1'))->toBeNull()
        ->and($this->roster->agentKind('w7:p3'))->toBe('claude')
        ->and($this->roster->agentKind('w9:p9'))->toBeNull();
});

it('resolves workspace labels', function () {
    expect($this->roster->workspaceId('w7:p3'))->toBe('w7')
        ->and($this->roster->workspaceLabel('w7'))->toBe('ORB-15')
        ->and($this->roster->workspaceLabel('w0'))->toBeNull()
        ->and($this->roster->workspaceId('w9:p9'))->toBeNull();
});

it('skips malformed entries', function () {
    $roster = Roster::fromPayloads(agents: ['nope', ['workspace_id' => 'w1']], workspaces: [42, ['label' => 'x']]);

    expect($roster->paneIds())->toBe([]);
});
