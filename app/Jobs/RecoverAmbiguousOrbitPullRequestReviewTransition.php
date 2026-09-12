<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\RecoverOrbitPullRequestReviewTransition;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RecoverAmbiguousOrbitPullRequestReviewTransition implements ShouldBeUniqueUntilProcessing, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 240;

    public const int LOCK_SECONDS = 270;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(
        public readonly int $deliveryId,
        public readonly int $phaseRunId,
    ) {
        $this->retryDeadline = now()->addMinutes(10)->toImmutable();
    }

    public function uniqueId(): string
    {
        return "delivery:pr-review-transition-recovery:{$this->deliveryId}:{$this->phaseRunId}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(RecoverOrbitPullRequestReviewTransition $recover): void
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
            if ($recover->bindAmbiguousTransitionRecovery($this->deliveryId) !== $this->phaseRunId) {
                return;
            }

            try {
                $phase = $recover->handle($this->deliveryId);
            } catch (OrbitPullRequestReviewDispatchFailed $exception) {
                $delivery = Delivery::query()->find($this->deliveryId);

                if ($delivery?->status !== DeliveryStatus::Blocked
                    || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                    || ($delivery->failure_details['code'] ?? null) !== 'linear_pr_review_transition_ambiguous') {
                    throw $exception;
                }

                return;
            }
        } finally {
            $lock->release();
        }

        DispatchOrbitPullRequestReview::dispatch($this->deliveryId, $phase->id);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
