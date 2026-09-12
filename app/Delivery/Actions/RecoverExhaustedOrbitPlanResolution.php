<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanResolutionReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class RecoverExhaustedOrbitPlanResolution
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private OrbitPlanResolutionReceiptValidator $receipts,
    ) {}

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
            $storedReceipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phaseRunId = $delivery->failure_details['phase_run_id'] ?? null;
            $resolution = is_int($phaseRunId) ? $phases->firstWhere('id', $phaseRunId) : null;
            $latestResolution = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $resolutionDispatches = $resolution instanceof PhaseRun
                ? $dispatches->where('phase_run_id', $resolution->id)->values()
                : collect();
            $dispatch = $resolutionDispatches->first();

            $delivery->setRelation('projectOrchestration', $project);

            if (! $resolution instanceof PhaseRun
                || $latestResolution?->id !== $resolution->id
                || $resolution->status !== PhaseRunStatus::Pending
                || $resolution->current_block !== null
                || $resolution->output !== null
                || $resolution->failure_code !== null
                || $resolution->failure_message !== null
                || $resolution->failure_details !== null
                || $resolution->started_at !== null
                || $resolution->finished_at !== null
                || $resolutionDispatches->count() !== 1
                || ! $dispatch instanceof AgentDispatch
                || $dispatch->status !== AgentDispatchStatus::Pending
                || $dispatch->prompt_hash !== str_repeat('0', 64)
                || $dispatch->herdr_session !== null
                || $dispatch->herdr_workspace_id !== null
                || $dispatch->herdr_tab_id !== null
                || $dispatch->herdr_pane_id !== null
                || $dispatch->herdr_terminal_id !== null
                || $dispatch->herdr_agent_id !== null
                || $dispatch->state_change_seq !== null
                || $dispatch->error_code !== null
                || $dispatch->error_message !== null
                || $dispatch->dispatched_at !== null
                || $dispatch->settled_at !== null
                || $storedReceipts->where('phase_run_id', $resolution->id)->isNotEmpty()
                || ! $this->receipts->matchesSource($delivery, $resolution, $dispatch)) {
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
        $failure = $delivery->failure_details;

        return $delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION
            && $delivery->status === DeliveryStatus::Failed
            && $delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
            && $delivery->failed_at !== null
            && is_array($failure)
            && $this->hasExactKeys($failure, ['code', 'phase_run_id', 'message'])
            && ($failure['code'] ?? null) === 'resolution_dispatch_exhausted'
            && is_int($failure['phase_run_id'] ?? null)
            && is_string($failure['message'] ?? null)
            && trim($failure['message']) !== '';
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
