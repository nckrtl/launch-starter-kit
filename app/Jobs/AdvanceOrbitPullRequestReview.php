<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitPullRequestReview as AdvanceAction;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdvanceOrbitPullRequestReview implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 540;

    public const int LOCK_SECONDS = 570;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $phaseRunId,
    ) {
        $this->retryDeadline = now()->addMinutes(20)->toImmutable();
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(AdvanceAction $advance): void
    {
        $lock = Cache::lock(
            "delivery:pr-review-advance:{$this->deliveryId}:{$this->phaseRunId}",
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $advance->handle($this->deliveryId, $this->phaseRunId);
        } finally {
            $lock->release();
        }

        $this->queueContinuation();
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null
                || $delivery->status->isTerminal()
                || in_array($delivery->status, [DeliveryStatus::Blocked, DeliveryStatus::Paused], true)
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE) {
                return;
            }

            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->latest('attempt')
                ->lockForUpdate()
                ->first();

            if ($phase === null || $phase->id !== $this->phaseRunId
                || $phase->attempt < 1 || $phase->status !== PhaseRunStatus::Running) {
                return;
            }

            $dispatches = $phase->agentDispatches()->get();
            $receipts = $phase->receipts()->where('kind', 'orbit_pr_review')->get();
            $dispatch = $dispatches->first();
            $receipt = $receipts->first();
            $publicationAttempted = $phase->current_block === 'review_publication';
            $recoverablePublication = $publicationAttempted
                && $dispatches->count() === 1
                && $receipts->count() === 1
                && $dispatch instanceof AgentDispatch
                && $receipt instanceof Receipt
                && $dispatch->status === AgentDispatchStatus::Settled
                && $dispatch->settled_at !== null
                && $receipt->validation_status === ReceiptValidationStatus::Valid;
            $delivery->status = $publicationAttempted ? DeliveryStatus::Blocked : DeliveryStatus::Failed;
            $delivery->failed_at = $publicationAttempted ? null : now();
            if ($recoverablePublication) {
                $delivery->failure_details = [
                    'code' => 'pr_review_publication_reconciliation_required',
                    'phase_run_id' => $phase->id,
                    'dispatch_id' => $dispatch->id,
                    'receipt_id' => $receipt->id,
                    'message' => $exception?->getMessage(),
                ];
            } elseif ($publicationAttempted) {
                $delivery->failure_details = [
                    'code' => 'pr_review_publication_reconciliation_required',
                    'message' => $exception?->getMessage(),
                ];
            } else {
                $delivery->failure_details = [
                    'code' => 'pr_review_advancement_exhausted',
                    'message' => $exception?->getMessage(),
                ];
            }
            $delivery->save();
        });
    }

    private function queueContinuation(): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null
            || $delivery->status->isTerminal()
            || in_array($delivery->status, [DeliveryStatus::Blocked, DeliveryStatus::Paused], true)
            || $delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE) {
            return;
        }

        AdvanceDelivery::dispatch($delivery->id)->afterCommit();
    }
}
