<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitPullRequestReview as AdvanceAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitPullRequestReviewPublicationFailed;
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
                || $phase->attempt !== 1 || $phase->status !== PhaseRunStatus::Running) {
                return;
            }

            $publication = $phase->current_block === 'review_publication'
                || $this->hasPublicationFailure($exception);
            $delivery->status = $publication ? DeliveryStatus::Blocked : DeliveryStatus::Failed;
            $delivery->failed_at = $publication ? null : now();
            $delivery->failure_details = [
                'code' => $publication
                    ? 'pr_review_publication_reconciliation_required'
                    : 'pr_review_advancement_exhausted',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }

    private function hasPublicationFailure(?Throwable $exception): bool
    {
        while ($exception !== null) {
            if ($exception instanceof OrbitPullRequestReviewPublicationFailed) {
                return true;
            }

            $exception = $exception->getPrevious();
        }

        return false;
    }
}
