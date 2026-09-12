<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
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
            ->select([
                'deliveries.id',
                'deliveries.status',
                'deliveries.current_phase',
                'deliveries.failure_details',
            ])
            ->whereHas('projectOrchestration', function (Builder $query): void {
                $query->where('state', ProjectOrchestrationState::Enabled->value);
            })
            ->where(function (Builder $query): void {
                $query->whereIn('status', $this->reconcilableStatuses())
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                            ->whereIn('failure_details->code', [
                                'resolution_publication_reconciliation_required',
                                'resolution_adoption_ready',
                                'resolution_adoption_reconciliation_required',
                            ]);
                    });
            })
            ->orderBy('deliveries.id')
            ->chunkById(self::CHUNK_SIZE, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $this->dispatchRecovery($delivery);
                }
            }, 'deliveries.id', 'id');
    }

    private function dispatchRecovery(Delivery $delivery): void
    {
        if ($delivery->status === DeliveryStatus::Blocked) {
            $phaseRunId = $delivery->failure_details['phase_run_id'] ?? null;
            $failureCode = $delivery->failure_details['code'] ?? null;

            if ($delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
                && is_int($phaseRunId)) {
                if ($failureCode === 'resolution_publication_reconciliation_required') {
                    AdvanceOrbitResolution::dispatch($delivery->id, $phaseRunId);
                }

                if (in_array($failureCode, [
                    'resolution_adoption_ready',
                    'resolution_adoption_reconciliation_required',
                ], true)) {
                    AdoptOrbitResolution::dispatch($delivery->id, $phaseRunId);
                }
            }

            return;
        }

        if ($delivery->status !== DeliveryStatus::WaitingForAgent) {
            AdvanceDelivery::dispatch($delivery->id);

            return;
        }

        $phase = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->first();
        $dispatches = $phase === null
            ? collect()
            : AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->orderBy('id')
                ->get();

        if ($phase === null
            || $phase->status !== PhaseRunStatus::Running
            || $dispatches->count() !== 1) {
            AdvanceDelivery::dispatch($delivery->id);

            return;
        }

        ReconcileDelivery::dispatch(
            $delivery->id,
            $phase->id,
            $dispatches->firstOrFail()->id,
        );
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
