<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPullRequestReviewReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class CaptureOrbitPullRequestReviewReceipt
{
    public function __construct(private OrbitPullRequestReviewReceiptValidator $receipts) {}

    /** @param array<string, mixed> $payload */
    public function handle(
        PhaseRun $phaseRun,
        AgentDispatch $dispatch,
        OrbitProjectConfig $expectedConfig,
        array $payload,
    ): Receipt {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $receipt = DB::transaction(function () use (
            $phaseRun,
            $dispatch,
            $expectedConfig,
            $payload,
            $hash,
        ): Receipt {
            $delivery = Delivery::query()->whereKey($phaseRun->delivery_id)->lockForUpdate()->first();
            $project = $delivery?->projectOrchestration()->lockForUpdate()->first();
            $phases = PhaseRun::query()
                ->where('delivery_id', $phaseRun->delivery_id)
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
            $lockedPhase = $phases->firstWhere('id', $phaseRun->id);
            $lockedDispatch = $dispatches->firstWhere('id', $dispatch->id);
            $latestReview = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();

            if ($delivery !== null && $project !== null) {
                $delivery->setRelation('projectOrchestration', $project);
            }

            if ($delivery === null || $project === null || $lockedPhase === null || $lockedDispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $expectedConfig->toArray()
                || $latestReview?->id !== $lockedPhase->id
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || ($delivery->status !== DeliveryStatus::WaitingForAgent
                    && ! ($delivery->status === DeliveryStatus::Preparing
                        && $lockedDispatch->status === AgentDispatchStatus::Starting
                        && $lockedDispatch->error_code === 'herdr_prompt_attempted'))
                || $lockedPhase->delivery_id !== $delivery->id
                || $lockedPhase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $lockedPhase->attempt !== 1
                || $lockedPhase->status !== PhaseRunStatus::Running
                || $lockedPhase->agentDispatches()->count() !== 1
                || $lockedDispatch->phase_run_id !== $lockedPhase->id
                || $lockedDispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
                || (! ($lockedDispatch->status === AgentDispatchStatus::Starting
                    && $lockedDispatch->error_code === 'herdr_prompt_attempted')
                    && ! in_array($lockedDispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))
                || ! $this->receipts->matchesPayload($delivery, $lockedPhase, $lockedDispatch, $payload)) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The pull request review receipt no longer matches the active dispatch.',
                );
            }

            $existing = $storedReceipts
                ->where('phase_run_id', $lockedPhase->id)
                ->firstWhere('kind', 'orbit_pr_review');

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new OrbitPullRequestReviewReceiptFailed(
                        'A different pull request review receipt was already captured for this phase.',
                    );
                }

                return $existing;
            }

            return Receipt::query()->create([
                'phase_run_id' => $lockedPhase->id,
                'kind' => 'orbit_pr_review',
                'schema_version' => 1,
                'payload' => $payload,
                'payload_hash' => $hash,
                'candidate_sha' => $payload['candidate_sha'],
                'validation_status' => ReceiptValidationStatus::Valid,
                'validation_errors' => null,
                'captured_at' => now(),
                'validated_at' => now(),
            ]);
        });

        AdvanceDelivery::dispatch($phaseRun->delivery_id)->afterCommit();

        return $receipt;
    }
}
