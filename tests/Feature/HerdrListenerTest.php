<?php

use App\Herdr\Debouncer;
use App\Herdr\Listener;
use App\Herdr\SocketClient;
use App\Herdr\StatusTracker;
use App\Herdr\TransitionRecorder;
use App\Models\HerdrEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeHerdrServer;

uses(RefreshDatabase::class);

/**
 * Two panes in ORB-15; the first goes idle, the second goes back to work.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{agents: list<array<string, mixed>>, workspaces: list<array<string, mixed>>, events: list<array<string, mixed>>, later_agents?: list<array<string, mixed>>}
 */
function herdrScenario(array $overrides = []): array
{
    return [
        'agents' => [
            ['pane_id' => 'w1:p1', 'workspace_id' => 'w1', 'tab_id' => 'w1:t1', 'terminal_id' => 't1', 'focused' => false, 'revision' => 1, 'agent' => 'codex', 'name' => 'orb15-impl', 'agent_status' => 'working'],
            ['pane_id' => 'w1:p2', 'workspace_id' => 'w1', 'tab_id' => 'w1:t1', 'terminal_id' => 't2', 'focused' => true, 'revision' => 1, 'agent' => 'claude', 'name' => 'orb15-review', 'agent_status' => 'idle'],
        ],
        'workspaces' => [
            ['workspace_id' => 'w1', 'label' => 'ORB-15', 'number' => 1, 'focused' => true, 'pane_count' => 2, 'tab_count' => 1, 'active_tab_id' => 'w1:t1', 'agent_status' => 'working'],
        ],
        'events' => [
            ['event' => 'pane.agent_status_changed', 'data' => ['pane_id' => 'w1:p1', 'workspace_id' => 'w1', 'agent' => 'codex', 'agent_status' => 'idle']],
            ['event' => 'pane.agent_status_changed', 'data' => ['pane_id' => 'w1:p2', 'workspace_id' => 'w1', 'agent' => 'claude', 'agent_status' => 'working']],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->server = FakeHerdrServer::start(herdrScenario());

    config([
        'herdr.socket' => $this->server->socketPath,
        'herdr.slack_channel' => 'C0TESTCHAN',
        'herdr.webhook_url' => 'https://agents.example.test/webhooks/orbit-herdr',
        'herdr.webhook_secret' => 'test-secret',
        'herdr.notify_statuses' => ['idle', 'done', 'blocked'],
        'herdr.debounce_seconds' => 0,
    ]);

    Http::fake([
        'agents.example.test/webhooks/orbit-herdr' => Http::response(['status' => 'accepted'], 202),
    ]);
    Notification::fake();
});

afterEach(function () {
    $this->server->stop();
});

it('records and sends exactly the transition into idle', function () {
    $log = [];
    $socket = $this->server->socketPath;

    $listener = new Listener(
        client: fn (): SocketClient => new SocketClient($socket),
        tracker: new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(0.0)),
        recorder: app(TransitionRecorder::class),
        log: function (string $line) use (&$log): void {
            $log[] = $line;
        },
    );

    $listener->run(timeout: 1.0);

    expect(HerdrEvent::count())->toBe(1);

    $event = HerdrEvent::sole();

    expect($event->pane_id)->toBe('w1:p1')
        ->and($event->workspace_id)->toBe('w1')
        ->and($event->workspace_label)->toBe('ORB-15')
        ->and($event->agent)->toBe('codex')
        ->and($event->agent_name)->toBe('orb15-impl')
        ->and($event->from_status)->toBe('working')
        ->and($event->to_status)->toBe('idle')
        ->and($event->notified_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://agents.example.test/webhooks/orbit-herdr'
        && $request['event_id'] === $event->id
        && $request['workspace_label'] === 'ORB-15'
        && $request['pane_id'] === 'w1:p1'
        && $request['to_status'] === 'idle');
    Notification::assertNothingSent();

    $requests = $this->server->requests();

    expect(array_column($requests, 'method'))->toBe(['agent.list', 'workspace.list', 'events.subscribe'])
        ->and($requests[2]['params']['subscriptions'])->toBe([
            ['type' => 'pane.created'],
            ['type' => 'pane.agent_detected'],
            ['type' => 'pane.exited'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p1'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p2'],
        ])
        ->and(implode("\n", $log))->toContain('w1:p1')->toContain('w1:p2')->toContain('working -> idle');
});

it('opens a new subscription that includes a pane created after the first one', function () {
    $this->server->stop();
    $this->server = FakeHerdrServer::start(herdrScenario([
        'events' => [
            ['event' => 'pane.created', 'data' => ['pane_id' => 'w1:p3', 'workspace_id' => 'w1', 'tab_id' => 'w1:t1', 'terminal_id' => 't3']],
        ],
        'later_agents' => [
            ...herdrScenario()['agents'],
            ['pane_id' => 'w1:p3', 'workspace_id' => 'w1', 'tab_id' => 'w1:t1', 'terminal_id' => 't3', 'focused' => false, 'revision' => 1, 'agent' => 'claude', 'name' => 'orb15-review2', 'agent_status' => 'working'],
        ],
    ]));

    $log = [];
    $socket = $this->server->socketPath;
    $tracker = new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(0.0));

    $listener = new Listener(
        client: fn (): SocketClient => new SocketClient($socket),
        tracker: $tracker,
        recorder: app(TransitionRecorder::class),
        log: function (string $line) use (&$log): void {
            $log[] = $line;
        },
    );

    $listener->run(timeout: 1.0);

    $subscriptions = array_values(array_filter(
        $this->server->requests(),
        fn (array $request): bool => $request['method'] === 'events.subscribe',
    ));

    expect($subscriptions)->toHaveCount(2)
        ->and($subscriptions[0]['params']['subscriptions'])->toBe([
            ['type' => 'pane.created'],
            ['type' => 'pane.agent_detected'],
            ['type' => 'pane.exited'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p1'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p2'],
        ])
        ->and($subscriptions[1]['params']['subscriptions'])->toBe([
            ['type' => 'pane.created'],
            ['type' => 'pane.agent_detected'],
            ['type' => 'pane.exited'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p1'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p2'],
            ['type' => 'pane.agent_status_changed', 'pane_id' => 'w1:p3'],
        ])
        ->and($tracker->knownPanes())->toBe(['w1:p1', 'w1:p2', 'w1:p3'])
        ->and(implode("\n", $log))->toContain('subscribed to 2 panes')
        ->toContain('new panes w1:p3; re-subscribing')
        ->toContain('subscribed to 3 panes')
        ->not->toContain('failed')
        ->not->toContain('retrying')
        ->and(HerdrEvent::count())->toBe(0);

    Notification::assertNothingSent();
});

it('forgets a pane that exited and keeps the subscription', function () {
    $this->server->stop();
    $this->server = FakeHerdrServer::start(herdrScenario([
        'events' => [
            ['event' => 'pane.exited', 'data' => ['pane_id' => 'w1:p2', 'workspace_id' => 'w1', 'tab_id' => 'w1:t1', 'terminal_id' => 't2']],
        ],
        'later_agents' => [herdrScenario()['agents'][0]],
    ]));

    $log = [];
    $socket = $this->server->socketPath;
    $tracker = new StatusTracker(['idle', 'done', 'blocked'], new Debouncer(0.0));

    $listener = new Listener(
        client: fn (): SocketClient => new SocketClient($socket),
        tracker: $tracker,
        recorder: app(TransitionRecorder::class),
        log: function (string $line) use (&$log): void {
            $log[] = $line;
        },
    );

    $listener->run(timeout: 1.0);

    expect($tracker->knownPanes())->toBe(['w1:p1'])
        ->and(array_column($this->server->requests(), 'method'))->toBe(['agent.list', 'workspace.list', 'events.subscribe', 'agent.list', 'workspace.list'])
        ->and(implode("\n", $log))->toContain('subscribed to 2 panes')
        ->not->toContain('re-subscribing')
        ->not->toContain('failed')
        ->not->toContain('retrying')
        ->and(HerdrEvent::count())->toBe(0);

    Notification::assertNothingSent();
});

it('requires the direct Tom webhook outside dry run', function () {
    config(['herdr.webhook_url' => null]);
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:listen', ['--timeout' => 0]))->toBe(1)
        ->and(Artisan::output())->toContain('HERDR_TOM_WEBHOOK');

    Http::assertNothingSent();
    Notification::assertNothingSent();
});

it('logs instead of recording or notifying in a dry run', function () {
    $this->withoutMockingConsoleOutput();

    $exitCode = Artisan::call('herdr:listen', ['--dry-run' => true, '--timeout' => 1]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('subscribed to 2 panes')
        ->toContain('orb15-impl (ORB-15, w1:p1): working -> idle')
        ->toContain('dry run: would send Herdr: orb15-impl just went idle')
        ->toContain('orb15-review (ORB-15, w1:p2): idle -> working')
        ->not->toContain('just went working')
        ->and(HerdrEvent::count())->toBe(0);

    Notification::assertNothingSent();
});
