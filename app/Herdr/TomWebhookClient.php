<?php

namespace App\Herdr;

use App\Models\HerdrEvent;
use Illuminate\Support\Facades\Http;
use LogicException;

final readonly class TomWebhookClient
{
    public function send(HerdrEvent $event): void
    {
        $url = config('herdr.webhook_url');
        $secret = config('herdr.webhook_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            throw new LogicException('HERDR_TOM_WEBHOOK_URL and HERDR_TOM_WEBHOOK_SECRET must be configured.');
        }

        $eventId = $event->getKey();

        if (! is_int($eventId)) {
            throw new LogicException('A persisted Herdr event is required before webhook delivery.');
        }

        $payload = [
            'event_type' => 'herdr_agent_status_changed',
            'event_id' => $eventId,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'workspace_id' => $event->workspace_id,
            'workspace_label' => $event->workspace_label,
            'pane_id' => $event->pane_id,
            'agent' => $event->agent,
            'agent_name' => $event->agent_name,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        Http::acceptJson()
            ->withHeaders([
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Signature-V2' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
                'X-Request-ID' => 'commander-herdr-'.$eventId,
            ])
            ->withBody($body, 'application/json')
            ->timeout(10)
            ->retry(3, 250, throw: false)
            ->post($url)
            ->throw();
    }
}
