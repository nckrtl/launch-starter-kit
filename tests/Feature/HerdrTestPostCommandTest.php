<?php

use App\Models\HerdrEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'herdr.webhook_url' => 'https://agents.example.test/webhooks/orbit-herdr',
        'herdr.webhook_secret' => 'test-secret',
    ]);
});

it('sends the latest recorded Herdr event directly to Tom', function () {
    Http::fake([
        'agents.example.test/webhooks/orbit-herdr' => Http::response(['status' => 'accepted'], 202),
    ]);

    $event = HerdrEvent::create([
        'occurred_at' => now(),
        'workspace_id' => 'wW',
        'workspace_label' => 'ORB-121',
        'pane_id' => 'wW:p1',
        'agent' => 'codex',
        'agent_name' => 'orb121-builder',
        'from_status' => 'working',
        'to_status' => 'done',
    ]);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(0)
        ->and(Artisan::output())->toContain('ok: true')->toContain("event {$event->id}")
        ->and($event->refresh()->notified_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request['event_id'] === $event->id);
});

it('reports a direct webhook failure', function () {
    Http::fake([
        'agents.example.test/webhooks/orbit-herdr' => Http::response(['status' => 'rejected'], 401),
    ]);

    HerdrEvent::create([
        'occurred_at' => now(),
        'workspace_id' => 'wW',
        'workspace_label' => 'ORB-121',
        'pane_id' => 'wW:p1',
        'to_status' => 'idle',
    ]);

    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(1)
        ->and(Artisan::output())->toContain('ok: false')->toContain('401');
});

it('refuses to post when no Herdr event has been recorded', function () {
    Http::fake();
    $this->withoutMockingConsoleOutput();

    expect(Artisan::call('herdr:test-post'))->toBe(1)
        ->and(Artisan::output())->toContain('No recorded Herdr event');

    Http::assertNothingSent();
});
