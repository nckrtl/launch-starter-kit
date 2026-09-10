<?php

use App\Herdr\TomWebhookClient;
use App\Models\HerdrEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use LogicException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'herdr.webhook_url' => 'https://agents.example.test/webhooks/orbit-herdr',
        'herdr.webhook_secret' => 'test-secret',
    ]);
});

it('posts a compact replay-protected signed event to Tom', function () {
    Http::fake([
        'agents.example.test/webhooks/orbit-herdr' => Http::response(['status' => 'accepted'], 202),
    ]);

    $event = HerdrEvent::create([
        'occurred_at' => '2026-09-04 08:15:00',
        'workspace_id' => 'wW',
        'workspace_label' => 'ORB-121',
        'pane_id' => 'wW:p1',
        'agent' => 'codex',
        'agent_name' => 'orb121-builder',
        'from_status' => 'working',
        'to_status' => 'done',
    ]);

    app(TomWebhookClient::class)->send($event);

    Http::assertSent(function (Request $request) use ($event): bool {
        $timestamp = $request->header('X-Webhook-Timestamp')[0] ?? '';
        $signature = $request->header('X-Webhook-Signature-V2')[0] ?? '';
        $deliveryId = $request->header('X-Request-ID')[0] ?? '';
        $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $request->url() === 'https://agents.example.test/webhooks/orbit-herdr'
            && ctype_digit($timestamp)
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->body(), 'test-secret'), $signature)
            && $deliveryId === 'commander-herdr-'.$event->id
            && $payload === [
                'event_type' => 'herdr_agent_status_changed',
                'event_id' => $event->id,
                'occurred_at' => '2026-09-04T08:15:00+00:00',
                'workspace_id' => 'wW',
                'workspace_label' => 'ORB-121',
                'pane_id' => 'wW:p1',
                'agent' => 'codex',
                'agent_name' => 'orb121-builder',
                'from_status' => 'working',
                'to_status' => 'done',
            ];
    });
});

it('fails closed when the webhook rejects an event', function () {
    Http::fake([
        'agents.example.test/webhooks/orbit-herdr' => Http::response(['status' => 'rejected'], 401),
    ]);

    $event = HerdrEvent::create([
        'occurred_at' => now(),
        'workspace_id' => 'wW',
        'pane_id' => 'wW:p1',
        'to_status' => 'idle',
    ]);

    expect(fn () => app(TomWebhookClient::class)->send($event))
        ->toThrow(RequestException::class);
});

it('refuses to send without both endpoint and secret', function () {
    config(['herdr.webhook_secret' => null]);
    Http::fake();

    $event = new HerdrEvent([
        'occurred_at' => now(),
        'workspace_id' => 'wW',
        'pane_id' => 'wW:p1',
        'to_status' => 'idle',
    ]);

    expect(fn () => app(TomWebhookClient::class)->send($event))
        ->toThrow(LogicException::class, 'HERDR_TOM_WEBHOOK');

    Http::assertNothingSent();
});
