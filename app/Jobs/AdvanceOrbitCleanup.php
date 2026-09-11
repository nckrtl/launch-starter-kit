<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceOrbitCleanup as AdvanceAction;
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

final class AdvanceOrbitCleanup implements ShouldQueue, ShouldQueueAfterCommit
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
            "delivery:cleanup-advance:{$this->deliveryId}:{$this->phaseRunId}",
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
            $phase = PhaseRun::query()->whereKey($this->phaseRunId)->lockForUpdate()->first();

            if ($delivery === null || $phase === null
                || $delivery->status !== DeliveryStatus::Cleaning
                || $delivery->current_phase !== OrbitFeatureWorkflow::CLEANUP_PHASE
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::CLEANUP_PHASE
                || $phase->attempt !== 1
                || ! in_array($phase->status, [PhaseRunStatus::Pending, PhaseRunStatus::Running], true)
                || $phase->finished_at !== null) {
                return;
            }

            $delivery->failure_details = [
                'code' => match ($phase->current_block) {
                    'workspace_shutdown' => 'cleanup_workspace_shutdown_required',
                    'worktree_cleanup' => 'cleanup_worktree_reconciliation_required',
                    default => 'cleanup_start_required',
                },
                'phase_run_id' => $phase->id,
                'message' => $exception?->getMessage(),
            ];
            $delivery->save();
        });
    }
}
