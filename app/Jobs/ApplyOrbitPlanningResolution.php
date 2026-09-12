<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\ApplyOrbitPlanningResolution as ApplyAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ApplyOrbitPlanningResolution implements ShouldBeUniqueUntilProcessing, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 240;

    public const int LOCK_SECONDS = 270;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    public int $uniqueFor = self::LOCK_SECONDS;

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
        return "delivery:planning-resolution:{$this->deliveryId}:{$this->phaseRunId}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(ApplyAction $apply): void
    {
        $lock = Cache::lock($this->uniqueId(), self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $apply->handle($this->deliveryId, $this->phaseRunId);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();
            $phase = PhaseRun::query()->whereKey($this->phaseRunId)->lockForUpdate()->first();
            $failure = $delivery?->failure_details;

            if ($delivery === null || $phase === null
                || $delivery->status !== DeliveryStatus::Blocked
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->delivery_id !== $delivery->id
                || $phase->current_block !== 'planning_resolution_correction'
                || ! is_array($failure)
                || ! in_array($failure['code'] ?? null, [
                    'resolution_decision_required',
                    'planning_resolution_reconciliation_required',
                ], true)) {
                return;
            }

            $delivery->failure_details = [
                ...$failure,
                'code' => 'planning_resolution_reconciliation_required',
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });

    }
}
