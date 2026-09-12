<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPlanReviewReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanReviewReceiptValidator;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class CaptureOrbitPlanReviewReceipt
{
    public function __construct(
        private OrbitPlanReviewReceiptValidator $receipts,
        private ReconcileOrbitSettledReceiptWait $settledReceipts,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(PhaseRun $phaseRun, AgentDispatch $dispatch, array $payload): Receipt
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $receipt = DB::transaction(function () use ($phaseRun, $dispatch, $payload, $hash): Receipt {
            $delivery = Delivery::query()->whereKey($phaseRun->delivery_id)->lockForUpdate()->first();
            $project = $delivery?->projectOrchestration()->lockForUpdate()->first();
            $lockedPhase = PhaseRun::query()->lockForUpdate()->find($phaseRun->id);
            $lockedDispatch = AgentDispatch::query()
                ->whereKey($dispatch->id)
                ->where('phase_run_id', $phaseRun->id)
                ->lockForUpdate()
                ->first();
            $phaseDispatches = AgentDispatch::query()
                ->where('phase_run_id', $phaseRun->id)
                ->lockForUpdate()
                ->get();
            $phaseReceipts = Receipt::query()
                ->where('phase_run_id', $phaseRun->id)
                ->lockForUpdate()
                ->get();
            $recovering = $delivery !== null && $project !== null
                && $lockedPhase !== null && $lockedDispatch !== null
                && $this->settledReceipts->canRecoverLateReceipt(
                    $delivery,
                    $project,
                    $lockedPhase,
                    $lockedDispatch,
                    $phaseDispatches->count(),
                    $phaseReceipts->count(),
                );

            if ($delivery !== null && $lockedPhase !== null) {
                $lockedPhase->setRelation('delivery', $delivery);
            }

            if ($delivery === null || $lockedPhase === null || $lockedDispatch === null
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                || (! $recovering && $delivery->status !== DeliveryStatus::WaitingForAgent
                    && ! ($delivery->status === DeliveryStatus::Preparing
                        && $lockedDispatch->status === AgentDispatchStatus::Starting
                        && $lockedDispatch->error_code === 'herdr_prompt_attempted'))
                || $lockedPhase->phase_name !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                || $lockedPhase->delivery_id !== $delivery->id
                || (! $recovering && $lockedPhase->status !== PhaseRunStatus::Running)
                || $phaseDispatches->count() !== 1
                || $lockedDispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
                || (! ($lockedDispatch->status === AgentDispatchStatus::Starting
                    && $lockedDispatch->error_code === 'herdr_prompt_attempted')
                    && ! in_array($lockedDispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))
                || ! $this->receipts->matchesInput($lockedPhase->delivery, $lockedPhase)
                || ! $this->receipts->matchesPayload($lockedPhase->delivery, $lockedPhase, $lockedDispatch, $payload)) {
                throw new OrbitPlanReviewReceiptFailed('The plan-review receipt no longer matches the active dispatch.');
            }

            $existing = $phaseReceipts->firstWhere('kind', 'orbit_plan_review');

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new OrbitPlanReviewReceiptFailed('A different plan-review receipt was already captured for this phase.');
                }

                return $existing;
            }

            $receipt = Receipt::query()->create([
                'phase_run_id' => $lockedPhase->id,
                'kind' => 'orbit_plan_review',
                'schema_version' => 1,
                'payload' => $payload,
                'payload_hash' => $hash,
                'candidate_sha' => $payload['candidate_sha'],
                'validation_status' => ReceiptValidationStatus::Valid,
                'validation_errors' => null,
                'captured_at' => now(),
                'validated_at' => now(),
            ]);

            if ($recovering) {
                $this->settledReceipts->restoreAfterLateReceipt($delivery, $lockedPhase);
            }

            return $receipt;
        });

        AdvanceDelivery::dispatch($phaseRun->delivery_id)->afterCommit();

        return $receipt;
    }
}
