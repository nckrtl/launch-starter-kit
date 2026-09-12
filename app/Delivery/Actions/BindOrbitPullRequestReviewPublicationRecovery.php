<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class BindOrbitPullRequestReviewPublicationRecovery
{
    public function __construct(
        private OrbitPullRequestReviewReceiptValidator $receipts,
        private OrbitPullRequestReviewSourceValidator $sources,
    ) {}

    public function handle(int $deliveryId): ?int
    {
        return DB::transaction(function () use ($deliveryId): ?int {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

            if ($delivery === null) {
                return null;
            }

            $project = $delivery->projectOrchestration()->lockForUpdate()->first();
            $phases = $delivery->phaseRuns()->orderBy('id')->lockForUpdate()->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $allReceipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $phase === null
                ? collect()
                : $dispatches->where('phase_run_id', $phase->id);
            $phaseReceipts = $phase === null
                ? collect()
                : $allReceipts->where('phase_run_id', $phase->id);
            $reviewReceipts = $phaseReceipts->where('kind', 'orbit_pr_review');
            $dispatch = $phaseDispatches->first();
            $receipt = $reviewReceipts->first();
            $failure = $delivery->failure_details;

            if ($project !== null) {
                $delivery->setRelation('projectOrchestration', $project);
            }

            if ($project === null || $phase === null
                || ! $dispatch instanceof AgentDispatch
                || ! $receipt instanceof Receipt
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->status !== DeliveryStatus::Blocked
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $project->state !== ProjectOrchestrationState::Enabled
                || ! is_array($failure)
                || array_diff(array_keys($failure), [
                    'code',
                    'phase_run_id',
                    'dispatch_id',
                    'receipt_id',
                    'message',
                ]) !== []
                || ($failure['code'] ?? null) !== 'pr_review_publication_reconciliation_required'
                || $phase->status !== PhaseRunStatus::Running
                || $phase->started_at === null
                || $phase->finished_at !== null
                || $phase->current_block !== 'review_publication'
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->count() !== 1
                || $reviewReceipts->count() !== 1
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->settled_at === null
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)
                || $this->sources->sourceReceipt($delivery, $phase)?->id === null
                || ! $this->matchesOptionalIdentity($failure, 'phase_run_id', $phase->id)
                || ! $this->matchesOptionalIdentity($failure, 'dispatch_id', $dispatch->id)
                || ! $this->matchesOptionalIdentity($failure, 'receipt_id', $receipt->id)) {
                return null;
            }

            $details = [
                'code' => 'pr_review_publication_reconciliation_required',
                'phase_run_id' => $phase->id,
                'dispatch_id' => $dispatch->id,
                'receipt_id' => $receipt->id,
                'message' => is_string($failure['message'] ?? null) ? $failure['message'] : null,
            ];

            if ($delivery->failure_details !== $details) {
                $delivery->failure_details = $details;
                $delivery->save();
            }

            return $phase->id;
        });
    }

    /** @param array<string, mixed> $failure */
    private function matchesOptionalIdentity(array $failure, string $key, int $expected): bool
    {
        return ! array_key_exists($key, $failure) || $failure[$key] === $expected;
    }
}
