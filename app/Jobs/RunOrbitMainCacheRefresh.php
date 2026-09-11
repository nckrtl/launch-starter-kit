<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\QueueOrbitMainCacheRefresh;
use App\Delivery\Actions\RunOrbitMainCacheRefresh as RunAction;
use App\Delivery\Enums\MaintenanceRunStatus;
use App\Models\MaintenanceRun;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunOrbitMainCacheRefresh implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public const int TIMEOUT_SECONDS = 60;

    public const int LOCK_SECONDS = 90;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct(public readonly int $maintenanceRunId)
    {
        $this->retryDeadline = now()->addMinutes(10)->toImmutable();
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(RunAction $run): void
    {
        $lock = Cache::lock(
            "maintenance:orbit-main-cache-refresh:{$this->maintenanceRunId}",
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $run->handle($this->maintenanceRunId);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $run = MaintenanceRun::query()->whereKey($this->maintenanceRunId)->lockForUpdate()->first();

            if ($run === null || $run->kind !== QueueOrbitMainCacheRefresh::KIND
                || in_array($run->status, [MaintenanceRunStatus::Completed, MaintenanceRunStatus::Failed], true)) {
                return;
            }

            $run->status = MaintenanceRunStatus::Failed;
            $run->failure_code = 'orbit_main_cache_refresh_enqueue_failed';
            $run->failure_message = $exception?->getMessage();
            $run->finished_at = now();
            $run->save();
        });
    }
}
