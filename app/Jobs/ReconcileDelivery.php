<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\ReconcileWaitingHerdrSettlement;
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

    public function __construct(public readonly int $deliveryId)
    {
        $this->retryDeadline = now()->addMinutes(5)->toImmutable();
    }

    public function uniqueId(): string
    {
        return "commander-delivery-reconciliation:{$this->deliveryId}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(ReconcileWaitingHerdrSettlement $settlements): void
    {
        $lock = Cache::lock("delivery:reconcile:{$this->deliveryId}", 60);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $settled = $settlements->handle($this->deliveryId);
        } finally {
            $lock->release();
        }

        if (! $settled) {
            AdvanceDelivery::dispatch($this->deliveryId)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
