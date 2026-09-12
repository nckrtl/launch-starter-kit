<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewWaitPolicy;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ReconcileOrbitSettledReceiptWait
{
    public function __construct(private OrbitPullRequestReviewWaitPolicy $policy) {}

    public function handle(int $deliveryId, int $phaseRunId, int $dispatchId): bool
    {
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use ($deliveryId, $phaseRunId, $dispatchId, $observedAt): bool {
            $state = $this->lockedState($deliveryId, $phaseRunId, $dispatchId);

            if ($state === null) {
                return false;
            }

            [$delivery, $phase, $dispatch, $receipts, $expectation] = $state;
            $settledAt = $dispatch->settled_at;

            if ($dispatch->agent_role !== $expectation['role']) {
                return $this->block(
                    $delivery,
                    $phase,
                    $dispatch,
                    $expectation,
                    'orbit_receipt_settlement_inconsistent',
                    'The settled Orbit agent dispatch has the wrong retained role.',
                    $observedAt,
                    $observedAt,
                );
            }

            $expectedReceipts = $receipts->where('kind', $expectation['receipt_kind']);

            if ($expectedReceipts->count() === 1
                && $expectedReceipts->first()?->validation_status === ReceiptValidationStatus::Valid
                && $receipts->count() === 1) {
                return false;
            }

            if ($receipts->isNotEmpty()) {
                return $this->block(
                    $delivery,
                    $phase,
                    $dispatch,
                    $expectation,
                    'orbit_receipt_settlement_inconsistent',
                    'The settled Orbit agent has unexpected or invalid retained receipt evidence.',
                    $observedAt,
                    $observedAt,
                );
            }

            if (! $this->hasCompleteIdentity($dispatch) || $settledAt === null) {
                return $this->block(
                    $delivery,
                    $phase,
                    $dispatch,
                    $expectation,
                    'orbit_receipt_settlement_inconsistent',
                    'The settled Orbit agent dispatch has incomplete retained identity.',
                    $observedAt,
                    $observedAt,
                );
            }

            $deadline = $this->policy->receiptDeadline($settledAt);

            if (! $this->policy->isOverdue($deadline, $observedAt)) {
                return false;
            }

            return $this->block(
                $delivery,
                $phase,
                $dispatch,
                $expectation,
                'orbit_receipt_missing',
                'The settled Orbit agent did not submit its receipt within the grace period.',
                $deadline,
                $observedAt,
            );
        });
    }

    public function canRecoverLateReceipt(
        Delivery $delivery,
        ProjectOrchestration $project,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        int $phaseDispatchCount,
        int $phaseReceiptCount,
    ): bool {
        $expectation = $this->expectation($phase->phase_name);
        $details = $phase->failure_details;
        $settledAt = $dispatch->settled_at;

        if ($expectation === null
            || ! is_array($details)
            || $project->id !== $delivery->project_orchestration_id
            || $project->state !== ProjectOrchestrationState::Enabled
            || $delivery->status !== DeliveryStatus::Blocked
            || $delivery->failure_details !== $details
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== $phase->phase_name
            || $phase->delivery_id !== $delivery->id
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Failed
            || $phase->current_block !== null
            || $phase->output !== null
            || $phase->failure_code !== 'orbit_receipt_missing'
            || $phase->failure_message !== 'The settled Orbit agent did not submit its receipt within the grace period.'
            || $phase->failure_details !== $details
            || $phase->started_at === null
            || $phase->finished_at === null
            || $phaseDispatchCount !== 1
            || $phaseReceiptCount !== 0
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== $expectation['role']
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $settledAt === null
            || ! $this->hasCompleteIdentity($dispatch)) {
            return false;
        }

        $latestPhaseId = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $phase->phase_name)
            ->latest('attempt')
            ->value('id');

        if ($latestPhaseId !== $phase->id) {
            return false;
        }

        $observedAt = $details['observed_at'] ?? null;

        if (! is_string($observedAt)) {
            return false;
        }

        try {
            $observed = CarbonImmutable::parse($observedAt);
        } catch (Throwable) {
            return false;
        }

        if (! $observed->startOfSecond()->equalTo($phase->finished_at->startOfSecond())) {
            return false;
        }

        return $details === $this->details(
            $phase,
            $dispatch,
            $expectation,
            'orbit_receipt_missing',
            $this->policy->receiptDeadline($settledAt),
            $observed,
        );
    }

    public function restoreAfterLateReceipt(Delivery $delivery, PhaseRun $phase): void
    {
        $phase->status = PhaseRunStatus::Running;
        $phase->failure_code = null;
        $phase->failure_message = null;
        $phase->failure_details = null;
        $phase->finished_at = null;
        $phase->save();

        $delivery->status = DeliveryStatus::WaitingForAgent;
        $delivery->failure_details = null;
        $delivery->save();
    }

    /**
     * @return array{
     *   Delivery,
     *   PhaseRun,
     *   AgentDispatch,
     *   Collection<int, Receipt>,
     *   array{role: string, receipt_kind: string}
     * }|null
     */
    private function lockedState(int $deliveryId, int $phaseRunId, int $dispatchId): ?array
    {
        $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

        if ($delivery === null) {
            return null;
        }

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
        $receipts = Receipt::query()
            ->whereIn('phase_run_id', $phases->modelKeys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $phase = $phases->firstWhere('id', $phaseRunId);
        $dispatch = $dispatches->firstWhere('id', $dispatchId);
        $phaseDispatches = $dispatches->where('phase_run_id', $phaseRunId);
        $latest = $phases
            ->where('phase_name', $delivery->current_phase)
            ->sortByDesc('attempt')
            ->first();
        $expectation = $phase instanceof PhaseRun
            ? $this->expectation($phase->phase_name)
            : null;

        if ($project === null
            || $project->state !== ProjectOrchestrationState::Enabled
            || $delivery->project_orchestration_id !== $project->id
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->status !== DeliveryStatus::WaitingForAgent
            || $delivery->failure_details !== null
            || $phase === null
            || $expectation === null
            || $latest?->id !== $phase->id
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== $delivery->current_phase
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->failure_code !== null
            || $phase->failure_message !== null
            || $phase->failure_details !== null
            || $phase->started_at === null
            || $phase->finished_at !== null
            || $phaseDispatches->count() !== 1
            || $dispatch === null
            || $phaseDispatches->first()?->id !== $dispatch->id
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Settled) {
            return null;
        }

        return [
            $delivery,
            $phase,
            $dispatch,
            $receipts->where('phase_run_id', $phase->id)->values(),
            $expectation,
        ];
    }

    /** @return array{role: string, receipt_kind: string}|null */
    private function expectation(string $phase): ?array
    {
        return match ($phase) {
            OrbitFeatureWorkflow::INITIAL_PHASE => [
                'role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                'receipt_kind' => 'orbit_planning',
            ],
            OrbitFeatureWorkflow::PLAN_REVIEW_PHASE => [
                'role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
                'receipt_kind' => 'orbit_plan_review',
            ],
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => [
                'role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                'receipt_kind' => 'orbit_implementation',
            ],
            OrbitFeatureWorkflow::RESOLUTION_PHASE => [
                'role' => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
                'receipt_kind' => 'orbit_resolution',
            ],
            default => null,
        };
    }

    private function hasCompleteIdentity(AgentDispatch $dispatch): bool
    {
        return is_string($dispatch->herdr_session) && $dispatch->herdr_session !== ''
            && is_string($dispatch->herdr_workspace_id) && $dispatch->herdr_workspace_id !== ''
            && is_string($dispatch->herdr_tab_id) && $dispatch->herdr_tab_id !== ''
            && is_string($dispatch->herdr_pane_id) && $dispatch->herdr_pane_id !== ''
            && is_string($dispatch->herdr_terminal_id) && $dispatch->herdr_terminal_id !== ''
            && is_string($dispatch->herdr_agent_name) && $dispatch->herdr_agent_name !== ''
            && $dispatch->state_change_seq !== null
            && $dispatch->dispatched_at !== null
            && $dispatch->settled_at !== null;
    }

    /** @param array{role: string, receipt_kind: string} $expectation */
    private function block(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $expectation,
        string $code,
        string $message,
        CarbonImmutable $deadline,
        CarbonImmutable $observedAt,
    ): bool {
        $details = $this->details(
            $phase,
            $dispatch,
            $expectation,
            $code,
            $deadline,
            $observedAt,
        );

        $phase->status = PhaseRunStatus::Failed;
        $phase->failure_code = $code;
        $phase->failure_message = $message;
        $phase->failure_details = $details;
        $phase->finished_at ??= $observedAt;
        $phase->save();

        $delivery->status = DeliveryStatus::Blocked;
        $delivery->failure_details = $details;
        $delivery->save();

        return true;
    }

    /**
     * @param  array{role: string, receipt_kind: string}  $expectation
     * @return array<string, mixed>
     */
    private function details(
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $expectation,
        string $code,
        CarbonImmutable $deadline,
        CarbonImmutable $observedAt,
    ): array {
        return [
            'code' => $code,
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
            'phase_name' => $phase->phase_name,
            'phase_attempt' => $phase->attempt,
            'agent_role' => $expectation['role'],
            'dispatch_agent_role' => $dispatch->agent_role,
            'expected_receipt_kind' => $expectation['receipt_kind'],
            'dispatch_status' => $dispatch->status->value,
            'herdr_session' => $dispatch->herdr_session,
            'herdr_workspace_id' => $dispatch->herdr_workspace_id,
            'herdr_tab_id' => $dispatch->herdr_tab_id,
            'herdr_pane_id' => $dispatch->herdr_pane_id,
            'herdr_terminal_id' => $dispatch->herdr_terminal_id,
            'herdr_agent_id' => $dispatch->herdr_agent_id,
            'herdr_agent_name' => $dispatch->herdr_agent_name,
            'dispatched_at' => $dispatch->dispatched_at?->toISOString(),
            'settled_at' => $dispatch->settled_at?->toISOString(),
            'deadline_at' => $deadline->toISOString(),
            'observed_at' => $observedAt->toISOString(),
            'state_change_seq' => $dispatch->state_change_seq,
        ];
    }
}
