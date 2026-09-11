<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitLanding as AdvanceAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
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

final class AdvanceOrbitLanding implements ShouldQueue, ShouldQueueAfterCommit
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
            "delivery:landing-advance:{$this->deliveryId}:{$this->phaseRunId}",
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $delay = $advance->handle($this->deliveryId, $this->phaseRunId);
        } finally {
            $lock->release();
        }

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null
                || $delivery->status->isTerminal()
                || in_array($delivery->status, [DeliveryStatus::Blocked, DeliveryStatus::Paused], true)
                || $delivery->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE) {
                return;
            }

            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)
                ->where('attempt', 1)
                ->lockForUpdate()
                ->first();

            if ($phase === null || $phase->id !== $this->phaseRunId
                || ! $this->isActiveLandingState($delivery, $phase)) {
                return;
            }

            if ($delivery->status === DeliveryStatus::Merging) {
                $delivery->failure_details = [
                    'code' => 'landing_merge_reconciliation_required',
                    'phase_run_id' => $phase->id,
                    'message' => $exception?->getMessage(),
                ];
                $delivery->save();

                return;
            }

            if ($delivery->status === DeliveryStatus::Landed) {
                $delivery->failure_details = [
                    'code' => 'landing_reservation_release_required',
                    'phase_run_id' => $phase->id,
                    'message' => $exception?->getMessage(),
                ];
                $delivery->save();

                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => 'landing_advancement_exhausted',
                'phase_run_id' => $phase->id,
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }

    private function isActiveLandingState(Delivery $delivery, PhaseRun $phase): bool
    {
        if ($delivery->status === DeliveryStatus::ReadyToMerge) {
            return $phase->status === PhaseRunStatus::Pending
                && $phase->current_block === null && $phase->finished_at === null;
        }

        if ($delivery->status === DeliveryStatus::Merging) {
            return $phase->status === PhaseRunStatus::Running
                && $phase->current_block === 'merge' && $phase->finished_at === null;
        }

        return $delivery->status === DeliveryStatus::Landed
            && $phase->status === PhaseRunStatus::Running
            && $phase->current_block === 'reservation_release'
            && $phase->finished_at === null;
    }
}
