<?php

use App\Operations\HermesSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Cache::forget('commander:hermes:snapshot');
    config()->set('commander.hermes.profiles', [
        'anna' => '/profiles/anna',
        'tom' => '/profiles/tom',
    ]);
});

it('normalizes safe Hermes profile, board, and signal fields', function () {
    $sequence = Process::sequence()
        ->push(json_encode(['gateway_state' => 'running', 'active_agents' => 0, 'code_version' => '1.2.3']))
        ->push(json_encode(['entries' => []]))
        ->push(json_encode(['gateway_state' => 'running', 'active_agents' => 1, 'secret' => 'never expose']))
        ->push(json_encode(['entries' => [['session_id' => 'session-1', 'surface' => 'cli', 'token' => 'secret']]]))
        ->push(json_encode([['slug' => 'orbit', 'name' => 'Orbit', 'counts' => ['running' => 1], 'total' => 3, 'db_path' => '/private/path']]))
        ->push(json_encode([['kind' => 'stale', 'message' => 'Card is stale', 'severity' => 'warning', 'body' => 'private']]));

    Process::fake(['*' => $sequence])->preventStrayProcesses();

    $snapshot = app(HermesSnapshot::class)->get();

    expect($snapshot['status'])->toBe('online')
        ->and($snapshot['profiles'][0]['name'])->toBe('Anna')
        ->and($snapshot['profiles'][0]['busy'])->toBeFalse()
        ->and($snapshot['profiles'][1]['busy'])->toBeTrue()
        ->and($snapshot['profiles'][1]['active_sessions'][0])->not->toHaveKey('token')
        ->and($snapshot['boards'][0])->not->toHaveKey('db_path')
        ->and($snapshot['signals'][0])->toBe(['kind' => 'stale', 'title' => 'Card is stale', 'severity' => 'warning']);
});

it('degrades cleanly when Mini is unavailable', function () {
    Process::fake(['*' => Process::result(errorOutput: 'offline', exitCode: 255)])
        ->preventStrayProcesses();

    $snapshot = app(HermesSnapshot::class)->get();

    expect($snapshot['status'])->toBe('unavailable')
        ->and($snapshot['profiles'])->toBe([])
        ->and($snapshot['error'])->toBe('Hermes on Mini could not be reached.');
});
