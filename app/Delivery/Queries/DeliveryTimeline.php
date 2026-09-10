<?php

declare(strict_types=1);

namespace App\Delivery\Queries;

use App\Delivery\Data\TimelineEntry;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\MaintenanceRun;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final readonly class DeliveryTimeline
{
    /** @return list<array{occurred_at: string, type: string, source: string, source_id: int, details: array<string, scalar|null>}> */
    public function for(Delivery $delivery): array
    {
        $entries = collect();
        $phases = PhaseRun::query()->where('delivery_id', $delivery->id)->get();

        foreach ($phases as $phase) {
            $this->add($entries, $phase->started_at, 'phase.started', 'phase_run', $phase->id, ['phase' => $phase->phase_name, 'attempt' => $phase->attempt], 10, 10);
            $this->add($entries, $phase->finished_at, 'phase.finished', 'phase_run', $phase->id, ['phase' => $phase->phase_name, 'status' => $phase->status->value], 10, 20);
        }

        $phaseIds = $phases->modelKeys();

        foreach (AgentDispatch::query()->whereIn('phase_run_id', $phaseIds)->get() as $dispatch) {
            $this->add($entries, $dispatch->dispatched_at, 'dispatch.sent', 'agent_dispatch', $dispatch->id, ['role' => $dispatch->agent_role], 20, 10);
            $this->add($entries, $dispatch->settled_at, 'dispatch.settled', 'agent_dispatch', $dispatch->id, ['role' => $dispatch->agent_role], 20, 20);
        }

        foreach (Receipt::query()->whereIn('phase_run_id', $phaseIds)->get() as $receipt) {
            $this->add($entries, $receipt->captured_at, 'receipt.captured', 'receipt', $receipt->id, ['kind' => $receipt->kind], 30, 10);
            $this->add($entries, $receipt->validated_at, 'receipt.validated', 'receipt', $receipt->id, ['status' => $receipt->validation_status->value], 30, 20);
        }

        foreach (ExternalEvent::query()->where('delivery_id', $delivery->id)->get() as $event) {
            $this->add($entries, $event->received_at, 'event.received', 'external_event', $event->id, ['provider' => $event->provider, 'kind' => $event->event_kind], 40, 10);
            $this->add($entries, $event->processed_at, 'event.processed', 'external_event', $event->id, ['provider' => $event->provider], 40, 20);
            $this->add($entries, $event->failed_at, 'event.failed', 'external_event', $event->id, ['provider' => $event->provider], 40, 30);
        }

        foreach (MaintenanceRun::query()->where('delivery_id', $delivery->id)->get() as $run) {
            $this->add($entries, $run->started_at, 'maintenance.started', 'maintenance_run', $run->id, ['kind' => $run->kind], 50, 10);
            $this->add($entries, $run->finished_at, 'maintenance.finished', 'maintenance_run', $run->id, ['status' => $run->status->value], 50, 20);
        }

        return array_values($entries
            ->sortBy(fn (TimelineEntry $entry): string => implode('|', [
                $entry->occurredAt->format('Y-m-d H:i:s.u'),
                str_pad((string) $entry->sourcePriority, 3, '0', STR_PAD_LEFT),
                str_pad((string) $entry->sourceId, 20, '0', STR_PAD_LEFT),
                str_pad((string) $entry->milestonePriority, 3, '0', STR_PAD_LEFT),
            ]))
            ->map(fn (TimelineEntry $entry): array => $entry->toArray())
            ->values()
            ->all());
    }

    /** @param Collection<int, TimelineEntry> $entries
     * @param  array<string, scalar|null>  $details
     */
    private function add(Collection $entries, ?CarbonInterface $at, string $type, string $source, int $id, array $details, int $sourcePriority, int $milestonePriority): void
    {
        if ($at !== null) {
            $entries->push(new TimelineEntry($at, $type, $source, $id, $details, $sourcePriority, $milestonePriority));
        }
    }
}
