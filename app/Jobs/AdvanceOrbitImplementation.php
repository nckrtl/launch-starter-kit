<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitImplementation as AdvanceAction;
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

final class AdvanceOrbitImplementation implements ShouldQueue, ShouldQueueAfterCommit
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
        $lock = Cache::lock("delivery:implementation-advance:{$this->deliveryId}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $mergeabilityPending = $advance->handle($this->deliveryId, $this->phaseRunId);
        } finally {
            $lock->release();
        }

        if ($mergeabilityPending) {
            $this->release(5);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null
                || $delivery->status->isTerminal()
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE) {
                return;
            }

            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->lockForUpdate()
                ->first();

            if ($phase === null
                || $phase->id !== $this->phaseRunId
                || $phase->status !== PhaseRunStatus::Running) {
                return;
            }

            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => 'implementation_advancement_exhausted',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }
}
