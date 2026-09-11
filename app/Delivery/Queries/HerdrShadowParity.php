<?php

declare(strict_types=1);

namespace App\Delivery\Queries;

use App\Models\ExternalEvent;
use App\Models\HerdrEvent;
use Carbon\CarbonImmutable;

final readonly class HerdrShadowParity
{
    private const int DEFAULT_OBSERVATION_HOURS = 24;

    private const int MATCH_LOOKBACK_SECONDS = 10;

    private const int MATCH_LOOKAHEAD_SECONDS = 1;

    /**
     * @return array{
     *     schema: int,
     *     capture_started_at: string|null,
     *     observed_from: string|null,
     *     observed_until: string,
     *     compatibility_events: int,
     *     matched_compatibility_events: int,
     *     missing_compatibility_event_ids: list<int>,
     *     pending_notification_event_ids: list<int>,
     *     raw_terminal_events: int,
     *     correlated_terminal_events: int,
     *     unmatched_terminal_events: int,
     *     passed: bool
     * }
     */
    public function report(?CarbonImmutable $since = null): array
    {
        $until = now()->toImmutable();
        $firstCapture = ExternalEvent::query()
            ->select('received_at')
            ->where('provider', 'herdr')
            ->oldest('received_at')
            ->oldest('id')
            ->first();

        if ($firstCapture === null) {
            return [
                'schema' => 1,
                'capture_started_at' => null,
                'observed_from' => null,
                'observed_until' => $until->format(DATE_ATOM),
                'compatibility_events' => 0,
                'matched_compatibility_events' => 0,
                'missing_compatibility_event_ids' => [],
                'pending_notification_event_ids' => [],
                'raw_terminal_events' => 0,
                'correlated_terminal_events' => 0,
                'unmatched_terminal_events' => 0,
                'passed' => false,
            ];
        }

        $captureStartedAt = $firstCapture->received_at;
        $defaultObservedFrom = $until->subHours(self::DEFAULT_OBSERVATION_HOURS);
        $observedFrom = $since?->utc()
            ?? ($captureStartedAt->isAfter($defaultObservedFrom)
                ? $captureStartedAt
                : $defaultObservedFrom);
        $compatibility = HerdrEvent::query()
            ->select(['id', 'occurred_at', 'workspace_id', 'pane_id', 'to_status', 'notified_at'])
            ->whereBetween('occurred_at', [$observedFrom, $until])
            ->oldest('occurred_at')
            ->oldest('id')
            ->get();
        $raw = ExternalEvent::query()
            ->select(['id', 'payload', 'delivery_id', 'received_at', 'failure_message'])
            ->where('provider', 'herdr')
            ->where('event_kind', 'pane.agent_status_changed')
            ->whereBetween('received_at', [
                $observedFrom->subSeconds(self::MATCH_LOOKBACK_SECONDS),
                $until,
            ])
            ->oldest('received_at')
            ->oldest('id')
            ->get();
        /** @var array<string, list<ExternalEvent>> $rawByCorrelation */
        $rawByCorrelation = [];
        $rawTerminalEvents = 0;
        $correlatedTerminalEvents = 0;
        $unmatchedTerminalEvents = 0;

        foreach ($raw as $event) {
            $correlation = $this->terminalCorrelation($event);

            if ($correlation === null) {
                continue;
            }

            $key = $this->correlationKey(...$correlation);
            $rawByCorrelation[$key][] = $event;

            if (! $event->received_at->betweenIncluded($observedFrom, $until)) {
                continue;
            }

            $rawTerminalEvents++;
            $correlatedTerminalEvents += $event->delivery_id === null ? 0 : 1;
            $unmatchedTerminalEvents += $event->failure_message === 'unmatched_dispatch' ? 1 : 0;
        }

        /** @var array<string, int> $rawOffsets */
        $rawOffsets = [];
        $missing = [];

        foreach ($compatibility as $legacy) {
            $key = $this->correlationKey(
                $legacy->workspace_id,
                $legacy->pane_id,
                $legacy->to_status,
            );
            $events = $rawByCorrelation[$key] ?? [];
            $offset = $rawOffsets[$key] ?? 0;
            $from = $legacy->occurred_at->copy()->subSeconds(self::MATCH_LOOKBACK_SECONDS);
            $untilForMatch = $legacy->occurred_at->copy()->addSeconds(self::MATCH_LOOKAHEAD_SECONDS);

            while (isset($events[$offset]) && $events[$offset]->received_at->isBefore($from)) {
                $offset++;
            }

            if (! isset($events[$offset]) || $events[$offset]->received_at->isAfter($untilForMatch)) {
                $missing[] = $legacy->id;
            } else {
                $offset++;
            }

            $rawOffsets[$key] = $offset;
        }

        $pendingNotifications = array_values(array_map(
            static fn (HerdrEvent $event): int => $event->id,
            $compatibility->whereNull('notified_at')->all(),
        ));

        return [
            'schema' => 1,
            'capture_started_at' => $captureStartedAt->format(DATE_ATOM),
            'observed_from' => $observedFrom->format(DATE_ATOM),
            'observed_until' => $until->format(DATE_ATOM),
            'compatibility_events' => $compatibility->count(),
            'matched_compatibility_events' => $compatibility->count() - count($missing),
            'missing_compatibility_event_ids' => $missing,
            'pending_notification_event_ids' => $pendingNotifications,
            'raw_terminal_events' => $rawTerminalEvents,
            'correlated_terminal_events' => $correlatedTerminalEvents,
            'unmatched_terminal_events' => $unmatchedTerminalEvents,
            'passed' => $compatibility->isNotEmpty()
                && $missing === []
                && $pendingNotifications === [],
        ];
    }

    /** @return array{string, string, string}|null */
    private function terminalCorrelation(ExternalEvent $event): ?array
    {
        $data = $event->payload['data'] ?? null;

        if (! is_array($data)
            || ! is_string($data['workspace_id'] ?? null)
            || ! is_string($data['pane_id'] ?? null)
            || ! is_string($data['agent_status'] ?? null)
            || ! in_array($data['agent_status'], ['idle', 'done'], true)) {
            return null;
        }

        return [$data['workspace_id'], $data['pane_id'], $data['agent_status']];
    }

    private function correlationKey(string $workspace, string $pane, string $status): string
    {
        return json_encode([$workspace, $pane, $status], JSON_THROW_ON_ERROR);
    }
}
