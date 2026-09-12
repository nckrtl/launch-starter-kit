<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\ReconcileOrbitPullRequestReviewWait;
use App\Delivery\Actions\ReconcileOrbitSettledReceiptWait;
use App\Delivery\Actions\ReconcileWaitingHerdrSettlement;
use App\Delivery\Exceptions\HerdrSettlementObservationFailed;
use App\Delivery\Exceptions\HerdrSettlementReconciliationFailed;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReconcileDelivery implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 0;

    public int $uniqueFor = 120;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $phaseRunId,
        public readonly int $dispatchId,
    ) {
        $this->retryDeadline = now()->addMinutes(5)->toImmutable();
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'commander-delivery-reconciliation',
            $this->deliveryId,
            $this->phaseRunId,
            $this->dispatchId,
        ]);
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(
        ReconcileWaitingHerdrSettlement $settlements,
        ReconcileOrbitSettledReceiptWait $settledReceipts,
        ReconcileOrbitPullRequestReviewWait $pullRequestReviews,
    ): void {
        $lock = Cache::lock(implode(':', [
            'delivery',
            'reconcile',
            $this->deliveryId,
            $this->phaseRunId,
            $this->dispatchId,
        ]), 60);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            try {
                $observation = $settlements->handle(
                    $this->deliveryId,
                    $this->phaseRunId,
                    $this->dispatchId,
                );
            } catch (HerdrSettlementObservationFailed $exception) {
                if (! $pullRequestReviews->waitIsOverdue(
                    $this->deliveryId,
                    $this->phaseRunId,
                    $this->dispatchId,
                )) {
                    return;
                }

                throw $exception;
            } catch (HerdrSettlementReconciliationFailed $exception) {
                if (! $pullRequestReviews->isActiveReview(
                    $this->deliveryId,
                    $this->phaseRunId,
                    $this->dispatchId,
                )) {
                    throw $exception;
                }

                $pullRequestReviews->blockIdentityFailure(
                    $this->deliveryId,
                    $this->phaseRunId,
                    $this->dispatchId,
                    $exception,
                );

                return;
            }

            if ($settledReceipts->handle(
                $this->deliveryId,
                $this->phaseRunId,
                $this->dispatchId,
            )) {
                return;
            }

            $pullRequestReviews->handle(
                $this->deliveryId,
                $this->phaseRunId,
                $this->dispatchId,
                $observation,
            );
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof HerdrSettlementObservationFailed) {
            app(ReconcileOrbitPullRequestReviewWait::class)->blockObservationFailure(
                $this->deliveryId,
                $this->phaseRunId,
                $this->dispatchId,
                $exception,
            );
        }

        if ($exception !== null) {
            report($exception);
        }
    }
}
