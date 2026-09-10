<?php

use App\Operations\HermesKanbanSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Cache::forget('commander:hermes:kanban:v1');
    Cache::forget('commander:hermes:kanban:latest:v1');
    Process::preventStrayProcesses();
});

it('defers unified cards so the agents page loads without waiting for Hermes', function () {
    $this->get('/agents')->assertSuccessful()->assertInertia(fn ($page) => $page
        ->component('Agents/Kanban')->where('kanban', null)->missing('freshKanban'));
    Process::assertNothingRan();
});

it('reads every board and keeps matching card IDs distinct by project', function () {
    Process::fake([
        '*boards list*' => Process::result(output: '[{"slug":"launch","name":"Launch"},{"slug":"orbit","name":"Orbit"}]'),
        '*list --archived*' => Process::result(output: '[{"id":"same","title":"Work","assignee":"Tom","status":"running","body":"secret"}]'),
    ]);
    $result = app(HermesKanbanSnapshot::class)->get();
    expect($result['status'])->toBe('online')->and($result['boards'])->toHaveCount(2)
        ->and($result['cards'])->toHaveCount(2)
        ->and($result['cards'][0])->toMatchArray(['assignee' => 'tom', 'project' => 'launch'])
        ->and($result['cards'][1]['project'])->toBe('orbit')
        ->and($result['cards'][0])->not->toHaveKey('body');
    app(HermesKanbanSnapshot::class)->get();
    Process::assertRanTimes(fn () => true, 3);
    expect(app(HermesKanbanSnapshot::class)->cached())->toBe($result);
});

it('renders the last saved board without contacting Hermes', function () {
    $saved = ['status' => 'online', 'cards' => [['id' => 'saved-card']], 'fetched_at' => '2026-09-08T12:00:00Z'];
    Cache::put('commander:hermes:kanban:latest:v1', $saved, 3600);
    $this->get('/agents')->assertSuccessful()->assertInertia(fn ($page) => $page
        ->component('Agents/Kanban')->where('kanban', $saved)->missing('freshKanban'));
    Process::assertNothingRan();
});

it('keeps the saved state when a background refresh fails', function () {
    $saved = ['status' => 'online', 'cards' => [['id' => 'saved-card']], 'fetched_at' => '2026-09-08T12:00:00Z'];
    Cache::put('commander:hermes:kanban:latest:v1', $saved, 3600);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    expect(app(HermesKanbanSnapshot::class)->get())->toBe([...$saved, 'refresh_failed' => true])
        ->and(app(HermesKanbanSnapshot::class)->cached())->toBe($saved);
});

it('shows source failure and does not claim that boards are empty', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    expect(app(HermesKanbanSnapshot::class)->get()['status'])->toBe('unavailable');
});

it('retains healthy boards when one board fails', function () {
    Process::fake([
        '*boards list*' => Process::result(output: '[{"slug":"launch","name":"Launch"}]'),
        '*list --archived*' => Process::result(exitCode: 1),
    ]);
    $result = app(HermesKanbanSnapshot::class)->get();
    expect($result['status'])->toBe('partial')->and($result['unavailable'])->toBe(['Launch']);
});

it('retains the saved board if a project refresh only partially succeeds', function () {
    $saved = ['status' => 'online', 'cards' => [['id' => 'orbit-saved'], ['id' => 'launch-saved']], 'fetched_at' => '2026-09-08T12:00:00Z'];
    Cache::put('commander:hermes:kanban:latest:v1', $saved, 3600);
    Process::fake([
        '*boards list*' => Process::result(output: '[{"slug":"launch","name":"Launch"},{"slug":"orbit","name":"Orbit"}]'),
        '*launch*list --archived*' => Process::result(output: '[]'),
        '*orbit*list --archived*' => Process::result(exitCode: 1),
    ]);
    expect(app(HermesKanbanSnapshot::class)->get())->toBe([...$saved, 'refresh_failed' => true])
        ->and(app(HermesKanbanSnapshot::class)->cached())->toBe($saved);
});
