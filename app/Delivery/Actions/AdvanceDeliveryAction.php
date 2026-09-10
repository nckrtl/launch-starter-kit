<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\ValidatedReceipt;
use App\Delivery\Workflow\WorkflowRegistry;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AdvanceDeliveryAction
{
    public function __construct(
        private WorkflowRegistry $workflows,
        private HerdrRuntime $herdr,
        private ProjectConfigRegistry $configs,
    ) {}

    /** Return true when a continuation should be queued after the caller releases its lock. */
    public function handle(int $deliveryId): bool
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->status->isTerminal()
            || $delivery->status === DeliveryStatus::Paused
            || $delivery->status === DeliveryStatus::Blocked
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            return false;
        }

        $workflow = $this->workflows->for($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            return false;
        }

        $phase = $workflow->phase($delivery->current_phase);

        $phaseRun = DB::transaction(function () use ($delivery, $phase): PhaseRun {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $latest = PhaseRun::query()
                ->where('delivery_id', $locked->id)
                ->where('phase_name', $phase->name)
                ->latest('attempt')
                ->first();
            $attempt = $latest === null
                ? 1
                : $latest->attempt + ($latest->status === PhaseRunStatus::Completed ? 1 : 0);

            $phaseRun = PhaseRun::query()->firstOrCreate(
                ['delivery_id' => $locked->id, 'phase_name' => $phase->name, 'attempt' => $attempt],
                ['status' => PhaseRunStatus::Running, 'started_at' => now()],
            );

            AgentDispatch::query()->firstOrCreate(
                ['phase_run_id' => $phaseRun->id, 'agent_role' => $phase->agentRole],
                [
                    'idempotency_key' => IdempotencyKey::forDispatch($locked->id, $phase->name, $phaseRun->attempt, $phase->agentRole)->value,
                    'herdr_agent_name' => "commander-{$locked->id}-{$phase->name}-{$phaseRun->attempt}",
                    'prompt_name' => $phase->name,
                    'prompt_version' => 1,
                    'prompt_hash' => hash('sha256', $phase->prompt),
                    'status' => AgentDispatchStatus::Pending,
                ],
            );

            return $phaseRun;
        });

        $dispatch = $phaseRun->agentDispatches()->firstOrFail();

        if ($dispatch->status === AgentDispatchStatus::Ambiguous) {
            $this->reconcileAmbiguous($delivery, $dispatch, $phase->prompt, $config);

            return false;
        }

        if ($dispatch->status === AgentDispatchStatus::Pending) {
            $this->startAgent($delivery, $dispatch, $phase->prompt, $config);

            return false;
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled) {
            return false;
        }

        $receipt = $phaseRun->receipts()
            ->where('validation_status', ReceiptValidationStatus::Valid)
            ->first();

        if ($receipt === null) {
            $delivery->status = DeliveryStatus::Blocked;
            $delivery->failure_details = ['code' => 'receipt_missing', 'phase_run_id' => $phaseRun->id];
            $delivery->save();

            return false;
        }

        if ($phaseRun->status === PhaseRunStatus::Completed) {
            return false;
        }

        $receipt->load('phaseRun');
        $transition = $workflow->nextPhase($delivery, new ValidatedReceipt($receipt));

        return DB::transaction(function () use ($delivery, $phaseRun, $receipt, $transition): bool {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedPhase = PhaseRun::query()->whereKey($phaseRun->id)->lockForUpdate()->firstOrFail();

            if ($lockedPhase->status === PhaseRunStatus::Completed) {
                return false;
            }

            $lockedPhase->status = PhaseRunStatus::Completed;
            $lockedPhase->output = ['receipt_id' => $receipt->id];
            $lockedPhase->finished_at = now();
            $lockedPhase->save();

            $locked->status = $transition->status;
            if ($transition->nextPhase !== null) {
                $locked->current_phase = $transition->nextPhase->name;
            }

            if ($transition->status === DeliveryStatus::Completed) {
                $locked->completed_at = now();
                $locked->completion_details = ['receipt_id' => $receipt->id];
            }

            $locked->save();

            return $transition->nextPhase !== null;
        });
    }

    private function startAgent(Delivery $delivery, AgentDispatch $dispatch, string $prompt, OrbitProjectConfig $config): void
    {
        $claimed = AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update(['status' => AgentDispatchStatus::Starting]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $opened = $this->herdr->openWorktree((string) $delivery->worktree_path);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_worktree_open_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'herdr_session' => $config->herdrSession,
            'herdr_workspace_id' => $opened->workspaceId,
            'herdr_tab_id' => $opened->tabId,
            'herdr_pane_id' => $opened->paneId,
            'herdr_terminal_id' => $opened->terminalId,
        ])->save();

        try {
            $started = $this->herdr->startAgent($opened->paneId, (string) $dispatch->herdr_agent_name);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_start_ambiguous', $exception);

            throw $exception;
        }

        $this->persistStartedAgent($dispatch, $started);
        $this->submitPrompt($delivery, $dispatch, $started->agentName, $prompt);
    }

    private function reconcileAmbiguous(Delivery $delivery, AgentDispatch $dispatch, string $prompt, OrbitProjectConfig $config): void
    {
        if ($dispatch->error_code === 'herdr_prompt_ambiguous') {
            return;
        }

        if ($dispatch->error_code === 'herdr_worktree_open_ambiguous') {
            $dispatch->forceFill(['status' => AgentDispatchStatus::Pending])->save();
            $this->startAgent($delivery, $dispatch, $prompt, $config);

            return;
        }

        if ($dispatch->error_code !== 'herdr_start_ambiguous' || $dispatch->herdr_agent_name === null) {
            return;
        }

        $started = $this->herdr->getAgent($dispatch->herdr_agent_name);

        if ($started->agentName !== $dispatch->herdr_agent_name) {
            return;
        }

        $this->persistStartedAgent($dispatch, $started);
        $this->submitPrompt($delivery, $dispatch, $started->agentName, $prompt);
    }

    private function persistStartedAgent(AgentDispatch $dispatch, HerdrAgentIdentifiers $started): void
    {
        $dispatch->forceFill([
            'herdr_workspace_id' => $started->workspaceId,
            'herdr_tab_id' => $started->tabId,
            'herdr_pane_id' => $started->paneId,
            'herdr_terminal_id' => $started->terminalId,
            'herdr_agent_id' => $started->agentId,
            'herdr_agent_name' => $started->agentName,
            'state_change_seq' => $started->stateChangeSeq,
            'dispatched_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    private function submitPrompt(Delivery $delivery, AgentDispatch $dispatch, string $agentName, string $prompt): void
    {
        try {
            $prompted = $this->herdr->promptAgent($agentName, $prompt);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_prompt_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'status' => AgentDispatchStatus::Waiting,
            'state_change_seq' => $prompted->stateChangeSeq,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $delivery->status = DeliveryStatus::WaitingForAgent;
        $delivery->save();
    }

    private function markAmbiguous(AgentDispatch $dispatch, string $code, Throwable $exception): void
    {
        $dispatch->forceFill([
            'status' => AgentDispatchStatus::Ambiguous,
            'error_code' => $code,
            'error_message' => $exception->getMessage(),
        ])->save();
    }
}
