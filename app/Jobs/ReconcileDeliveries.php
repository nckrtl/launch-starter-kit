<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Models\Delivery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ReconcileDeliveries implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int CHUNK_SIZE = 100;

    public int $timeout = 45;

    public int $tries = 0;

    public int $uniqueFor = 120;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct()
    {
        $this->retryDeadline = now()->addMinutes(5)->toImmutable();
    }

    public function uniqueId(): string
    {
        return 'commander-delivery-reconciliation';
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(): void
    {
        Delivery::query()
            ->select('deliveries.id')
            ->whereHas('projectOrchestration', function (Builder $query): void {
                $query->where('state', ProjectOrchestrationState::Enabled->value);
            })
            ->whereIn('status', $this->reconcilableStatuses())
            ->orderBy('deliveries.id')
            ->chunkById(self::CHUNK_SIZE, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    AdvanceDelivery::dispatch($delivery->id);
                }
            }, 'deliveries.id', 'id');
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }

    /** @return list<string> */
    private function reconcilableStatuses(): array
    {
        return array_map(
            static fn (DeliveryStatus $status): string => $status->value,
            [
                DeliveryStatus::Queued,
                DeliveryStatus::Preparing,
                DeliveryStatus::WaitingForAgent,
                DeliveryStatus::ValidatingReceipt,
                DeliveryStatus::WaitingForChanges,
                DeliveryStatus::ReadyToMerge,
                DeliveryStatus::Merging,
                DeliveryStatus::Landed,
                DeliveryStatus::Cleaning,
            ],
        );
    }
}
