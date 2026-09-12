<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitResolution as AdvanceAction;
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

final class AdvanceOrbitResolution implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 90;

    public const int LOCK_SECONDS = 120;

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

    public function handle(AdvanceAction $advance): void
    {
        $lock = Cache::lock(
            "delivery:resolution-advance:{$this->deliveryId}:{$this->phaseRunId}",
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

            if ($delivery === null || $delivery->status !== DeliveryStatus::Blocked
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE) {
                return;
            }

            $failure = $delivery->failure_details;

            if (! is_array($failure) || ($failure['phase_run_id'] ?? null) !== $this->phaseRunId
                || ! in_array($failure['code'] ?? null, [
                    'resolution_proposal_ready',
                    'resolution_publication_reconciliation_required',
                ], true)) {
                return;
            }

            $delivery->failure_details = [
                'code' => 'resolution_publication_reconciliation_required',
                'phase_run_id' => $this->phaseRunId,
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }
}
