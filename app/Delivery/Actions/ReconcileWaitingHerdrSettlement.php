<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\HerdrSettlementObservationFailed;
use App\Delivery\Exceptions\HerdrSettlementReconciliationFailed;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Throwable;

final readonly class ReconcileWaitingHerdrSettlement
{
    public function __construct(
        private HerdrRuntime $herdr,
        private CaptureHerdrEvent $events,
    ) {}

    public function handle(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
    ): ?HerdrAgentIdentifiers {
        if (! config('herdr.orchestration.enabled', false)) {
            return null;
        }

        $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

        if ($delivery === null
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return null;
        }

        $phase = PhaseRun::query()
            ->whereKey($phaseRunId)
            ->where('delivery_id', $deliveryId)
            ->first();

        $latestPhaseId = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->value('id');

        if ($phase === null || $latestPhaseId !== $phase->id
            || $phase->phase_name !== $delivery->current_phase
            || $phase->status !== PhaseRunStatus::Running) {
            return null;
        }

        $dispatches = AgentDispatch::query()
            ->where('phase_run_id', $phase->id)
            ->orderBy('id')
            ->get();

        if ($dispatches->count() !== 1) {
            throw new HerdrSettlementReconciliationFailed(
                'The waiting delivery does not have exactly one current agent dispatch.',
            );
        }

        $dispatch = $dispatches->firstOrFail();

        if ($dispatch->id !== $dispatchId || $dispatch->status !== AgentDispatchStatus::Waiting) {
            return null;
        }

        if ($dispatch->herdr_session !== config('herdr.session')
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->herdr_agent_id === null
            || $dispatch->herdr_agent_name === null
            || $dispatch->state_change_seq === null
            || $dispatch->dispatched_at === null) {
            throw new HerdrSettlementReconciliationFailed(
                'The waiting agent dispatch does not retain complete Herdr identity.',
            );
        }

        try {
            $agent = $this->herdr->getAgent($dispatch->herdr_agent_name);
        } catch (Throwable $exception) {
            throw new HerdrSettlementObservationFailed(
                'Commander could not observe the retained Herdr reviewer.',
                previous: $exception,
            );
        }

        if (! $this->sameAgent($delivery, $dispatch, $agent)) {
            throw new HerdrSettlementReconciliationFailed(
                'Herdr returned an agent outside the waiting dispatch identity.',
            );
        }

        if (! in_array($agent->agentStatus, ['idle', 'done'], true)
            || $agent->stateChangeSeq === null
            || $agent->stateChangeSeq <= $dispatch->state_change_seq) {
            return $agent;
        }

        $event = $this->events->reconcile($dispatch, $agent);
        $reconciled = AgentDispatch::query()->findOrFail($dispatch->id);

        if ($event?->agent_dispatch_id !== $dispatch->id
            || $reconciled->status !== AgentDispatchStatus::Settled) {
            throw new HerdrSettlementReconciliationFailed(
                'The terminal Herdr reviewer observation did not settle the retained dispatch.',
            );
        }

        return $agent;
    }

    private function sameAgent(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $agent,
    ): bool {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && $agent->agentId === $dispatch->herdr_agent_id
            && $agent->agentName === $dispatch->herdr_agent_name
            && ($agent->workingDirectory === null
                || $agent->workingDirectory === $delivery->worktree_path);
    }
}
