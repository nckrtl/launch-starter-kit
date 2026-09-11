<?php

declare(strict_types=1);

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Jobs\AdvanceDelivery;
use App\Jobs\ReconcileDeliveries;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues authoritative advancement only for enabled recoverable deliveries', function (): void {
    Queue::fake([AdvanceDelivery::class]);
    $enabled = ProjectOrchestration::create([
        'manifest_project_id' => 'enabled-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $paused = ProjectOrchestration::create([
        'manifest_project_id' => 'paused-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Paused,
    ]);
    $sequence = 0;
    $delivery = static function (
        ProjectOrchestration $project,
        DeliveryStatus $status,
    ) use (&$sequence): Delivery {
        $sequence++;

        return Delivery::create([
            'project_orchestration_id' => $project->id,
            'external_issue_provider' => 'test',
            'external_issue_id' => "issue-{$sequence}",
            'external_issue_key' => "TEST-{$sequence}",
            'workflow_type' => 'test',
            'workflow_version' => 1,
            'status' => $status,
            'current_phase' => 'test',
        ]);
    };
    $recoverableStatuses = [
        DeliveryStatus::Queued,
        DeliveryStatus::Preparing,
        DeliveryStatus::WaitingForAgent,
        DeliveryStatus::ValidatingReceipt,
        DeliveryStatus::WaitingForChanges,
        DeliveryStatus::ReadyToMerge,
        DeliveryStatus::Merging,
        DeliveryStatus::Landed,
        DeliveryStatus::Cleaning,
    ];
    $recoverable = array_map(
        static fn (DeliveryStatus $status): Delivery => $delivery($enabled, $status),
        $recoverableStatuses,
    );
    $excluded = [
        $delivery($enabled, DeliveryStatus::Paused),
        $delivery($enabled, DeliveryStatus::Blocked),
        $delivery($enabled, DeliveryStatus::Completed),
        $delivery($enabled, DeliveryStatus::Failed),
        $delivery($paused, DeliveryStatus::Queued),
        $delivery($paused, DeliveryStatus::Landed),
    ];

    $job = new ReconcileDeliveries;
    $job->handle();

    Queue::assertPushed(AdvanceDelivery::class, count($recoverable));

    foreach ($recoverable as $candidate) {
        Queue::assertPushed(
            AdvanceDelivery::class,
            fn (AdvanceDelivery $queued): bool => $queued->deliveryId === $candidate->id,
        );
    }

    foreach ($excluded as $candidate) {
        Queue::assertNotPushed(
            AdvanceDelivery::class,
            fn (AdvanceDelivery $queued): bool => $queued->deliveryId === $candidate->id,
        );
    }

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('commander-delivery-reconciliation')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('registers the reconciliation job on the one-minute schedule', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);
    $schedule = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($schedule)->toHaveCount(1)
        ->and($schedule[0]['expression'])->toBe('* * * * *')
        ->and($schedule[0]['command'])->toBe('deliveries:reconcile');
});
