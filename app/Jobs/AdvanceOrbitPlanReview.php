<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitPlanReview as AdvanceAction;
use App\Delivery\Enums\DeliveryStatus;
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

final class AdvanceOrbitPlanReview implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 480;

    public const int LOCK_SECONDS = 510;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(public readonly int $deliveryId)
    {
        $this->retryDeadline = now()->addMinutes(15)->toImmutable();
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(AdvanceAction $advance): void
    {
        $lock = Cache::lock("delivery:plan-review-advance:{$this->deliveryId}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $advance->handle($this->deliveryId);
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
                || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE) {
                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => 'plan_review_advancement_exhausted',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }

    private function queueContinuation(): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null
            || $delivery->status->isTerminal()
            || in_array($delivery->status, [DeliveryStatus::Blocked, DeliveryStatus::Paused], true)
            || $delivery->current_phase === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE) {
            return;
        }

        AdvanceDelivery::dispatch($delivery->id)->afterCommit();
    }
}
