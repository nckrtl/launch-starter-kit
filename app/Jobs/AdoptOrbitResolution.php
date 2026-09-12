<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\AdoptOrbitResolution as AdoptAction;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdoptOrbitResolution implements ShouldBeUniqueUntilProcessing, ShouldQueue, ShouldQueueAfterCommit
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

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function uniqueId(): string
    {
        return "delivery:resolution-adopt:{$this->deliveryId}:{$this->phaseRunId}";
    }

    public function handle(AdoptAction $adopt): void
    {
        $lock = Cache::lock(
            "delivery:resolution-adopt:{$this->deliveryId}:{$this->phaseRunId}",
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            $adopt->handle($this->deliveryId, $this->phaseRunId);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $delivery = Delivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null) {
                return;
            }

            $delivery->projectOrchestration()->lockForUpdate()->first();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $receipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $this->phaseRunId);
            $failure = $delivery->failure_details;
            $dispatchId = is_array($failure) ? ($failure['dispatch_id'] ?? null) : null;
            $receiptId = is_array($failure) ? ($failure['receipt_id'] ?? null) : null;
            $output = $phase?->output;

            if ($delivery->status !== DeliveryStatus::Blocked
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase === null
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->status !== PhaseRunStatus::Completed
                || $phase->current_block !== 'resolution_adoption'
                || ! is_array($failure)
                || ! in_array($failure['code'] ?? null, [
                    'resolution_adoption_ready',
                    'resolution_adoption_reconciliation_required',
                ], true)
                || ($failure['phase_run_id'] ?? null) !== $phase->id
                || ! is_int($dispatchId)
                || ! is_int($receiptId)
                || $dispatches->firstWhere('id', $dispatchId)?->phase_run_id !== $phase->id
                || $receipts->firstWhere('id', $receiptId)?->phase_run_id !== $phase->id
                || ! is_array($output)
                || ($output['adopted'] ?? null) !== false
                || ($output['automatic_adoption_eligible'] ?? null) !== true) {
                return;
            }

            $failure['code'] = 'resolution_adoption_reconciliation_required';
            $failure['message'] = $exception?->getMessage();
            $delivery->failure_details = $failure;
            $delivery->save();
        });
    }
}
