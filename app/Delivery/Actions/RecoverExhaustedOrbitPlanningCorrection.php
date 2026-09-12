<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class RecoverExhaustedOrbitPlanningCorrection
{
    public function __construct(private ProjectConfigRegistry $configs) {}

    public function handle(int $deliveryId): bool
    {
        return DB::transaction(function () use ($deliveryId): bool {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if (! $delivery instanceof Delivery || ! $this->hasRecoverableFailure($delivery)) {
                return false;
            }

            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();

            try {
                $config = $this->configs->hydrate($project->config);
            } catch (InvalidArgumentException|ValidationException) {
                return false;
            }

            if (! $config instanceof OrbitProjectConfig
                || $project->state !== ProjectOrchestrationState::Enabled) {
                return false;
            }

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
            $correction = $phases->first(
                fn (PhaseRun $phase): bool => $phase->phase_name === OrbitFeatureWorkflow::INITIAL_PHASE
                    && $phase->attempt === 2,
            );
            $correctionDispatches = $correction instanceof PhaseRun
                ? $dispatches->where('phase_run_id', $correction->id)->values()
                : collect();
            $dispatch = $correctionDispatches->first();

            if (! $correction instanceof PhaseRun
                || $correction->status !== PhaseRunStatus::Pending
                || $correctionDispatches->count() !== 1
                || ! $dispatch instanceof AgentDispatch
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
                || $dispatch->idempotency_key !== IdempotencyKey::forDispatch(
                    $delivery->id,
                    OrbitFeatureWorkflow::INITIAL_PHASE,
                    2,
                    OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                )->value
                || $dispatch->prompt_name !== 'orbit_planning_correction'
                || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLANNING_CORRECTION_PROMPT_VERSION
                || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
                || $dispatch->status !== AgentDispatchStatus::Pending
                || $dispatch->herdr_session !== null
                || $dispatch->herdr_workspace_id !== null
                || $dispatch->herdr_tab_id !== null
                || $dispatch->herdr_pane_id !== null
                || $dispatch->herdr_terminal_id !== null
                || $dispatch->herdr_agent_id !== null
                || $dispatch->state_change_seq !== null
                || $dispatch->error_code !== null
                || $dispatch->dispatched_at !== null) {
                return false;
            }

            $active = Delivery::query()
                ->whereBelongsTo($project)
                ->occupiesCapacity()
                ->count();

            if ($active >= $config->concurrency) {
                return false;
            }

            $delivery->forceFill([
                'status' => DeliveryStatus::Queued,
                'failure_details' => null,
                'failed_at' => null,
            ])->save();

            return true;
        });
    }

    private function hasRecoverableFailure(Delivery $delivery): bool
    {
        return $delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION
            && $delivery->status === DeliveryStatus::Failed
            && $delivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
            && $delivery->failed_at !== null
            && ($delivery->failure_details['code'] ?? null) === 'planning_correction_dispatch_exhausted';
    }
}
