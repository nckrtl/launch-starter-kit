<?php

declare(strict_types=1);

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Queries\HerdrShadowParity;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\HerdrEvent;
use App\Models\ProjectOrchestration;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function parityRawEvent(
    CarbonImmutable $receivedAt,
    string $pane,
    string $status,
    ?int $deliveryId = null,
    ?string $failure = null,
): ExternalEvent {
    $payload = [
        'event' => 'pane.agent_status_changed',
        'data' => [
            'workspace_id' => 'workspace-1',
            'pane_id' => $pane,
            'agent_status' => $status,
        ],
    ];

    return ExternalEvent::create([
        'ingestion_id' => (string) Str::uuid(),
        'provider' => 'herdr',
        'provider_event_id' => null,
        'event_kind' => 'pane.agent_status_changed',
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'delivery_id' => $deliveryId,
        'received_at' => $receivedAt,
        'processed_at' => $failure === null ? $receivedAt : null,
        'failure_message' => $failure,
    ]);
}

function parityCompatibilityEvent(
    CarbonImmutable $occurredAt,
    string $pane,
    string $status,
    bool $notified = true,
): HerdrEvent {
    return HerdrEvent::create([
        'occurred_at' => $occurredAt,
        'workspace_id' => 'workspace-1',
        'workspace_label' => 'ORB-1',
        'pane_id' => $pane,
        'agent' => 'codex',
        'agent_name' => 'worker',
        'from_status' => 'working',
        'to_status' => $status,
        'notified_at' => $notified ? $occurredAt : null,
    ]);
}

it('proves one-to-one raw capture for every compatibility notification', function (): void {
    $since = CarbonImmutable::parse('2026-09-11T10:00:00Z');
    $project = ProjectOrchestration::create([
        'manifest_project_id' => 'parity-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $delivery = Delivery::create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'test',
        'external_issue_id' => 'parity-issue',
        'external_issue_key' => 'TEST-1',
        'workflow_type' => 'test',
        'workflow_version' => 1,
        'status' => DeliveryStatus::Queued,
        'current_phase' => 'test',
    ]);
    parityRawEvent($since, 'pane-lifecycle', 'working');
    $firstRaw = parityRawEvent($since->addSeconds(5), 'pane-1', 'idle', $delivery->id);
    $secondRaw = parityRawEvent(
        $since->addSeconds(15),
        'pane-2',
        'done',
        failure: 'unmatched_dispatch',
    );
    parityCompatibilityEvent($since->addSeconds(10), 'pane-1', 'idle');
    parityCompatibilityEvent($since->addSeconds(20), 'pane-2', 'done');

    $report = app(HerdrShadowParity::class)->report($since);

    expect($report)->toMatchArray([
        'schema' => 1,
        'capture_started_at' => $since->format(DATE_ATOM),
        'observed_from' => $since->format(DATE_ATOM),
        'compatibility_events' => 2,
        'matched_compatibility_events' => 2,
        'missing_compatibility_event_ids' => [],
        'pending_notification_event_ids' => [],
        'raw_terminal_events' => 2,
        'correlated_terminal_events' => 1,
        'unmatched_terminal_events' => 1,
        'passed' => true,
    ])->and($firstRaw->id)->not->toBe($secondRaw->id);
});

it('fails closed for missing raw capture or pending compatibility notification', function (): void {
    $since = CarbonImmutable::parse('2026-09-11T11:00:00Z');
    parityRawEvent($since, 'pane-lifecycle', 'working');
    $missing = parityCompatibilityEvent($since->addSeconds(10), 'pane-missing', 'idle');
    $pending = parityCompatibilityEvent($since->addSeconds(20), 'pane-pending', 'done', false);

    $report = app(HerdrShadowParity::class)->report($since);

    expect($report['passed'])->toBeFalse()
        ->and($report['matched_compatibility_events'])->toBe(0)
        ->and($report['missing_compatibility_event_ids'])->toBe([$missing->id, $pending->id])
        ->and($report['pending_notification_event_ids'])->toBe([$pending->id]);
});

it('matches repeated pane transitions to the oldest eligible raw event', function (): void {
    $since = CarbonImmutable::parse('2026-09-11T11:30:00Z');
    parityRawEvent($since->addSecond(), 'pane-repeated', 'idle');
    parityRawEvent($since->addSeconds(11), 'pane-repeated', 'idle');
    parityCompatibilityEvent($since->addSeconds(10), 'pane-repeated', 'idle');
    parityCompatibilityEvent($since->addSeconds(20), 'pane-repeated', 'idle');

    $report = app(HerdrShadowParity::class)->report($since);

    expect($report['matched_compatibility_events'])->toBe(2)
        ->and($report['missing_compatibility_event_ids'])->toBe([])
        ->and($report['passed'])->toBeTrue();
});

it('bounds the default observation window to the past 24 hours', function (): void {
    Carbon::setTestNow('2026-09-11T12:00:00Z');

    try {
        $old = CarbonImmutable::parse('2026-09-09T12:00:00Z');
        $recent = CarbonImmutable::parse('2026-09-11T11:00:00Z');
        parityRawEvent($old, 'pane-old', 'idle');
        parityCompatibilityEvent($old->addSeconds(5), 'pane-old', 'idle');
        parityRawEvent($recent, 'pane-recent', 'done');
        parityCompatibilityEvent($recent->addSeconds(5), 'pane-recent', 'done');

        $report = app(HerdrShadowParity::class)->report();

        expect($report)->toMatchArray([
            'capture_started_at' => $old->format(DATE_ATOM),
            'observed_from' => CarbonImmutable::parse('2026-09-10T12:00:00Z')->format(DATE_ATOM),
            'compatibility_events' => 1,
            'matched_compatibility_events' => 1,
            'raw_terminal_events' => 1,
            'passed' => true,
        ]);
    } finally {
        Carbon::setTestNow();
    }
});

it('reports parity as JSON for Z and offset observation bounds', function (): void {
    $since = CarbonImmutable::parse('2026-09-11T12:00:00Z');
    parityRawEvent($since, 'pane-1', 'idle');
    parityCompatibilityEvent($since->addSeconds(5), 'pane-1', 'idle');

    $this->artisan('delivery:shadow-parity', ['--since' => $since->toISOString()])
        ->expectsOutputToContain('"passed": true')
        ->assertSuccessful();

    $this->artisan('delivery:shadow-parity', ['--since' => '2026-09-11T14:00:00+02:00'])
        ->expectsOutputToContain('"passed": true')
        ->assertSuccessful();
});

it('rejects relative observation bounds', function (): void {
    $this->artisan('delivery:shadow-parity', ['--since' => 'tomorrow'])
        ->expectsOutput('The shadow parity lower bound must be a valid ISO-8601 timestamp.')
        ->assertFailed();
});
