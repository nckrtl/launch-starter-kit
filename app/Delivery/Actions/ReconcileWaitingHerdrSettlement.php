<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
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

    public function handle(int $deliveryId): bool
    {
        if (! config('herdr.orchestration.enabled', false)) {
            return false;
        }

        $delivery = Delivery::query()->find($deliveryId);

        if ($delivery === null || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return false;
        }

        $phase = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->first();

        if ($phase === null || $phase->status !== PhaseRunStatus::Running) {
            return false;
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

        if ($dispatch->status !== AgentDispatchStatus::Waiting) {
            return false;
        }

        if ($dispatch->herdr_session !== config('herdr.session')
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->herdr_agent_id === null
            || $dispatch->herdr_agent_name === null
            || $dispatch->state_change_seq === null) {
            throw new HerdrSettlementReconciliationFailed(
                'The waiting agent dispatch does not retain complete Herdr identity.',
            );
        }

        try {
            $agent = $this->herdr->getAgent($dispatch->herdr_agent_name);
        } catch (Throwable) {
            return false;
        }

        if (! $this->sameAgent($delivery, $dispatch, $agent)) {
            throw new HerdrSettlementReconciliationFailed(
                'Herdr returned an agent outside the waiting dispatch identity.',
            );
        }

        if (! in_array($agent->agentStatus, ['idle', 'done'], true)
            || $agent->stateChangeSeq === null
            || $agent->stateChangeSeq <= $dispatch->state_change_seq) {
            return false;
        }

        $event = $this->events->reconcile($dispatch, $agent);
        $reconciled = AgentDispatch::query()->findOrFail($dispatch->id);

        return $event?->agent_dispatch_id === $dispatch->id
            && $reconciled->status === AgentDispatchStatus::Settled;
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
