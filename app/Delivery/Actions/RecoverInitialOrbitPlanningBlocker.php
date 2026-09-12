<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitPlanningAdvancementFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanningReceiptValidator;
use App\Delivery\Workflow\OrbitPlanResolutionReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class RecoverInitialOrbitPlanningBlocker
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private OrbitPlanningReceiptValidator $planningReceipts,
        private OrbitPlanResolutionReceiptValidator $resolutionReceipts,
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
            $receipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phaseRunId = $delivery->failure_details['phase_run_id'] ?? null;
            $dispatchId = $delivery->failure_details['dispatch_id'] ?? null;
            $receiptId = $delivery->failure_details['receipt_id'] ?? null;
            $planning = is_int($phaseRunId) ? $phases->firstWhere('id', $phaseRunId) : null;
            $dispatch = is_int($dispatchId) ? $dispatches->firstWhere('id', $dispatchId) : null;
            $receipt = is_int($receiptId) ? $receipts->firstWhere('id', $receiptId) : null;
            $handoff = $receipt?->payload['handoff'] ?? null;

            $delivery->setRelation('projectOrchestration', $project);

            if (! $planning instanceof PhaseRun
                || ! $dispatch instanceof AgentDispatch
                || ! $receipt instanceof Receipt
                || $phases->count() !== 1
                || $dispatches->count() !== 1
                || $receipts->count() !== 1
                || $planning->delivery_id !== $delivery->id
                || $planning->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $planning->attempt !== 1
                || $planning->status !== PhaseRunStatus::Failed
                || $planning->current_block !== null
                || $planning->output !== ['receipt_id' => $receipt->id, 'result' => 'blocked']
                || $planning->failure_code !== 'planning_blocked'
                || ! is_string($handoff)
                || $planning->failure_message !== $handoff
                || $planning->failure_details !== null
                || $planning->started_at === null
                || $planning->finished_at === null
                || $dispatch->phase_run_id !== $planning->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
                || $dispatch->idempotency_key !== IdempotencyKey::forDispatch(
                    $delivery->id,
                    OrbitFeatureWorkflow::INITIAL_PHASE,
                    1,
                    OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                )->value
                || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
                || $dispatch->prompt_name !== 'orbit_planning'
                || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->dispatched_at === null
                || $dispatch->settled_at === null
                || $receipt->phase_run_id !== $planning->id
                || $receipt->kind !== 'orbit_planning'
                || ($receipt->payload['result'] ?? null) !== 'blocked'
                || $delivery->candidate_sha !== ($receipt->payload['candidate_sha'] ?? null)
                || ! $this->planningReceipts->matches($delivery, $planning, $dispatch, $receipt)
                || $delivery->failure_details !== [
                    'code' => 'planning_blocked',
                    'phase_run_id' => $planning->id,
                    'dispatch_id' => $dispatch->id,
                    'receipt_id' => $receipt->id,
                    'handoff' => $handoff,
                ]) {
                return false;
            }

            $active = Delivery::query()
                ->whereBelongsTo($project)
                ->occupiesCapacity()
                ->count();

            if ($active >= $config->concurrency) {
                return false;
            }

            $input = [
                'planning_receipt_id' => $receipt->id,
                'planning_receipt' => $receipt->payload,
            ];
            $resolution = PhaseRun::query()->create([
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
                'attempt' => 1,
                'status' => PhaseRunStatus::Pending,
                'input' => $input,
            ]);
            $resolver = AgentDispatch::query()->create([
                'phase_run_id' => $resolution->id,
                'agent_role' => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
                'idempotency_key' => IdempotencyKey::forDispatch(
                    $delivery->id,
                    OrbitFeatureWorkflow::RESOLUTION_PHASE,
                    1,
                    OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
                )->value,
                'herdr_agent_name' => strtolower((string) $delivery->external_issue_key).'-loop-resolution-1',
                'prompt_name' => 'orbit_resolution',
                'prompt_version' => OrbitFeatureWorkflow::RESOLUTION_PROMPT_VERSION,
                'prompt_hash' => str_repeat('0', 64),
                'status' => AgentDispatchStatus::Pending,
            ]);

            if (! $this->resolutionReceipts->matchesSource($delivery, $resolution, $resolver)) {
                throw new OrbitPlanningAdvancementFailed(
                    'The recovered initial planning resolution intent is inconsistent.',
                );
            }

            $delivery->forceFill([
                'current_phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
                'status' => DeliveryStatus::Queued,
                'failure_details' => null,
            ])->save();

            return true;
        });
    }

    private function hasRecoverableFailure(Delivery $delivery): bool
    {
        $failure = $delivery->failure_details;

        return $delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION
            && $delivery->status === DeliveryStatus::Blocked
            && $delivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
            && is_array($failure)
            && $this->hasExactKeys($failure, [
                'code',
                'phase_run_id',
                'dispatch_id',
                'receipt_id',
                'handoff',
            ])
            && ($failure['code'] ?? null) === 'planning_blocked';
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
