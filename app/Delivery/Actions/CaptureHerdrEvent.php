<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Models\Receipt;
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

    public function reconcile(
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $agent,
    ): ?ExternalEvent {
        if (! config('herdr.orchestration.enabled', false)
            || ! in_array($agent->agentStatus, ['idle', 'done'], true)
            || $agent->stateChangeSeq === null) {
            return null;
        }

        $providerEventId = implode(':', [
            'reconciliation',
            $dispatch->id,
            $agent->stateChangeSeq,
            $agent->agentStatus,
        ]);
        $envelope = [
            'event' => 'pane.agent_status_changed',
            'data' => [
                'workspace_id' => $agent->workspaceId,
                'tab_id' => $agent->tabId,
                'pane_id' => $agent->paneId,
                'terminal_id' => $agent->terminalId,
                'agent_id' => $agent->agentId,
                'agent_name' => $agent->agentName,
                'agent_status' => $agent->agentStatus,
                'state_change_seq' => $agent->stateChangeSeq,
            ],
            'observed_by' => 'scheduled_reconciliation',
        ];
        $payloadHash = hash('sha256', json_encode($envelope, JSON_THROW_ON_ERROR));
        $event = ExternalEvent::query()->firstOrCreate(
            ['provider' => 'herdr', 'provider_event_id' => $providerEventId],
            [
                'ingestion_id' => (string) Str::uuid(),
                'event_kind' => 'pane.agent_status_changed',
                'payload' => $envelope,
                'payload_hash' => $payloadHash,
                'received_at' => now(),
            ],
        );

        if (! hash_equals($event->payload_hash, $payloadHash)) {
            throw new \RuntimeException('The retained Herdr reconciliation event changed.');
        }

        if ($event->processed_at === null) {
            $this->process($event);
        }

        return $event->refresh();
    }

    private function process(ExternalEvent $event): void
    {
        $payload = $event->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $kind = str_replace('.', '_', $event->event_kind);
        $status = is_string($data['agent_status'] ?? null) ? $data['agent_status'] : null;
        $stateChangeSeq = is_int($data['state_change_seq'] ?? null)
            ? $data['state_change_seq']
            : null;

        if ($kind !== 'pane_agent_status_changed' || ! in_array($status, ['idle', 'done'], true)) {
            $event->processed_at = now();
            $event->save();

            return;
        }

        $paneId = is_string($data['pane_id'] ?? null) ? $data['pane_id'] : null;
        $workspaceId = is_string($data['workspace_id'] ?? null) ? $data['workspace_id'] : null;
        $session = config('herdr.session');

        $dispatches = AgentDispatch::query()
            ->with('phaseRun.delivery')
            ->where('herdr_session', is_string($session) ? $session : '')
            ->when($paneId !== null, fn ($query) => $query->where('herdr_pane_id', $paneId))
            ->when($workspaceId !== null, fn ($query) => $query->where('herdr_workspace_id', $workspaceId))
            ->whereIn('status', [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled])
            ->get()
            ->filter(static function (AgentDispatch $candidate): bool {
                $phase = $candidate->phaseRun;
                $delivery = $phase->delivery;

                return $phase->status === PhaseRunStatus::Running
                    && $delivery->current_phase === $phase->phase_name
                    && in_array($delivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true);
            });

        if ($paneId === null || $dispatches->count() !== 1) {
            $event->failure_message = $dispatches->count() > 1
                ? 'ambiguous_dispatch'
                : 'unmatched_dispatch';
            $event->save();

            return;
        }

        $dispatch = $dispatches->firstOrFail();

        $deliveryId = PhaseRun::query()->whereKey($dispatch->phase_run_id)->value('delivery_id');

        if (! is_int($deliveryId)) {
            $event->failure_message = 'unmatched_dispatch';
            $event->save();

            return;
        }

        $shouldAdvance = DB::transaction(function () use ($event, $dispatch, $deliveryId, $stateChangeSeq): bool {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->first();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phaseRun = $phases->firstWhere('id', $dispatch->phase_run_id);
            $locked = $dispatches->firstWhere('id', $dispatch->id);
            $latestPhase = $phases
                ->where('phase_name', $delivery->current_phase)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $dispatch->phase_run_id);

            if ($project === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $phaseRun === null
                || $locked === null
                || $latestPhase?->id !== $phaseRun->id
                || $phaseRun->delivery_id !== $delivery->id
                || $phaseRun->phase_name !== $delivery->current_phase
                || $phaseRun->status !== PhaseRunStatus::Running
                || $phaseRun->finished_at !== null
                || $phaseDispatches->count() !== 1
                || $phaseDispatches->first()?->id !== $locked->id
                || ! in_array($delivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)) {
                $event->failure_message = 'unmatched_dispatch';
                $event->save();

                return false;
            }

            if (! in_array($locked->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                $event->failure_message = 'unmatched_dispatch';
                $event->save();

                return false;
            }

            if ($stateChangeSeq === null
                || $locked->state_change_seq === null
                || $stateChangeSeq <= $locked->state_change_seq) {
                $event->delivery_id = $phaseRun->delivery_id;
                $event->agent_dispatch_id = $locked->id;
                $event->failure_message = $stateChangeSeq === null
                    ? 'unsequenced_dispatch_event'
                    : 'stale_dispatch_event';
                $event->save();

                return false;
            }

            if ($locked->status !== AgentDispatchStatus::Settled) {
                $locked->status = AgentDispatchStatus::Settled;
                $locked->settled_at = now();
            }

            $locked->state_change_seq = $stateChangeSeq;

            $locked->save();

            $event->delivery_id = $phaseRun->delivery_id;
            $event->agent_dispatch_id = $locked->id;
            $event->failure_message = null;
            $event->save();

            return true;
        });

        if ($shouldAdvance) {
            AdvanceDelivery::dispatch((int) $event->delivery_id)->afterCommit();
        }

        $event->processed_at = now();
        $event->save();
    }
}
