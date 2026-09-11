<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CaptureHerdrEvent
{
    /** @param array<string, mixed> $envelope */
    public function handle(array $envelope): ?ExternalEvent
    {
        if (! config('herdr.orchestration.enabled', false)) {
            return null;
        }

        $event = ExternalEvent::query()->create([
            'ingestion_id' => (string) Str::uuid(),
            'provider' => 'herdr',
            'provider_event_id' => null,
            'event_kind' => is_string($envelope['event'] ?? null) ? $envelope['event'] : 'unknown',
            'payload' => $envelope,
            'payload_hash' => hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR)),
            'received_at' => now(),
        ]);

        $this->process($event);

        return $event->refresh();
    }

    private function process(ExternalEvent $event): void
    {
        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $kind = str_replace('.', '_', $event->event_kind);
        $status = is_string($data['agent_status'] ?? null) ? $data['agent_status'] : null;

        if ($kind !== 'pane_agent_status_changed' || ! in_array($status, ['idle', 'done'], true)) {
            $event->processed_at = now();
            $event->save();

            return;
        }

        $paneId = is_string($data['pane_id'] ?? null) ? $data['pane_id'] : null;
        $workspaceId = is_string($data['workspace_id'] ?? null) ? $data['workspace_id'] : null;
        $session = config('herdr.session');

        $dispatch = AgentDispatch::query()
            ->where('herdr_session', is_string($session) ? $session : '')
            ->when($paneId !== null, fn ($query) => $query->where('herdr_pane_id', $paneId))
            ->when($workspaceId !== null, fn ($query) => $query->where('herdr_workspace_id', $workspaceId))
            ->where(function ($query): void {
                $query->whereIn('status', [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled])
                    ->orWhere(function ($query): void {
                        $query->where('status', AgentDispatchStatus::Starting)
                            ->where('error_code', 'herdr_prompt_attempted');
                    });
            })
            ->latest('id')
            ->first();

        if ($paneId === null || $dispatch === null) {
            $event->failure_message = 'unmatched_dispatch';
            $event->save();

            return;
        }

        $deliveryId = PhaseRun::query()->whereKey($dispatch->phase_run_id)->value('delivery_id');

        if (! is_int($deliveryId)) {
            $event->failure_message = 'unmatched_dispatch';
            $event->save();

            return;
        }

        $shouldAdvance = DB::transaction(function () use ($event, $dispatch, $deliveryId): bool {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $phaseRun = PhaseRun::query()
                ->whereKey($dispatch->phase_run_id)
                ->where('delivery_id', $delivery->id)
                ->lockForUpdate()
                ->first();
            $locked = AgentDispatch::query()
                ->whereKey($dispatch->id)
                ->where('phase_run_id', $dispatch->phase_run_id)
                ->lockForUpdate()
                ->first();

            if ($phaseRun === null || $locked === null) {
                $event->failure_message = 'unmatched_dispatch';
                $event->save();

                return false;
            }

            $promptWasAttempted = $locked->status === AgentDispatchStatus::Starting
                && $locked->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted
                && ! in_array($locked->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                $event->failure_message = 'unmatched_dispatch';
                $event->save();

                return false;
            }

            if ($locked->status !== AgentDispatchStatus::Settled) {
                $locked->status = AgentDispatchStatus::Settled;
                $locked->settled_at = now();
                $locked->save();
            }

            $event->delivery_id = $phaseRun->delivery_id;
            $event->agent_dispatch_id = $locked->id;
            $event->failure_message = null;
            $event->save();

            if ($promptWasAttempted
                && $delivery->status === DeliveryStatus::Preparing) {
                $delivery->status = DeliveryStatus::WaitingForAgent;
                $delivery->save();
            }

            return true;
        });

        if ($shouldAdvance) {
            AdvanceDelivery::dispatch((int) $event->delivery_id)->afterCommit();
        }

        $event->processed_at = now();
        $event->save();
    }
}
