<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\DispatchOrbitPlanReview as DispatchAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Exceptions\OrbitPlanReviewDispatchFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchOrbitPlanReview implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(public readonly int $deliveryId)
    {
        $this->retryDeadline = now()->addMinutes(10)->toImmutable();
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(DispatchAction $dispatch): void
    {
        $lock = Cache::lock("delivery:plan-review-dispatch:{$this->deliveryId}", 270);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $dispatch->handle($this->deliveryId);
        } catch (OrbitPlanReviewDispatchFailed $exception) {
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
                || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE) {
                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => 'plan_review_dispatch_exhausted',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }
}
