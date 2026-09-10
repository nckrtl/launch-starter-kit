<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AdvanceDelivery implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(public readonly int $deliveryId) {}

    public function handle(AdvanceDeliveryAction $advance): void
    {
        $lock = Cache::lock("delivery:advance:{$this->deliveryId}", 60);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $continue = $advance->handle($this->deliveryId);
        } finally {
            $lock->release();
        }

        if ($continue) {
            self::dispatch($this->deliveryId)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->status->isTerminal()) {
            return;
        }

        $delivery->status = DeliveryStatus::Failed;
        $delivery->failed_at = now();
        $delivery->failure_details = [
            'code' => 'advancement_exhausted',
            'message' => $exception?->getMessage(),
        ];
        $delivery->save();
    }
}
