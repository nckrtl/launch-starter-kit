<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\DispatchOrbitPullRequestReview as DispatchAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchOrbitPullRequestReview implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 240;

    public const int LOCK_SECONDS = 270;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $phaseRunId,
    ) {
        $this->retryDeadline = now()->addMinutes(10)->toImmutable();
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(DispatchAction $dispatch): void
    {
        $lock = Cache::lock(
            "delivery:pr-review-dispatch:{$this->deliveryId}:{$this->phaseRunId}",
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $delivery = Delivery::query()->find($this->deliveryId);
            $failure = $delivery?->failure_details;

            if ($delivery?->status === DeliveryStatus::Blocked
                && is_array($failure)
                && ($failure['code'] ?? null) === 'herdr_start_ambiguous') {
                $dispatch->recoverAmbiguousStart($this->deliveryId, $this->phaseRunId);
            } else {
                $dispatch->handle($this->deliveryId, $this->phaseRunId);
            }
        } catch (OrbitPullRequestReviewDispatchFailed $exception) {
            $delivery = Delivery::query()->find($this->deliveryId);

            if ($delivery?->status !== DeliveryStatus::Blocked) {
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null
                || $delivery->status->isTerminal()
                || in_array($delivery->status, [DeliveryStatus::Blocked, DeliveryStatus::Paused], true)
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE) {
                return;
            }

            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->latest('attempt')
                ->lockForUpdate()
                ->first();

            if ($phase === null
                || $phase->id !== $this->phaseRunId
                || $phase->attempt < 1
                || ! in_array($phase->status, [PhaseRunStatus::Pending, PhaseRunStatus::Running], true)) {
                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => 'pr_review_dispatch_exhausted',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }
}
