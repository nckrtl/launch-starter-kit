<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Contracts\OrbitDeliveryLoopStarter;
use App\Delivery\Contracts\OrbitIssueOwnershipClaimer;
use App\Delivery\Queries\NextEligibleIssue;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class AdmitNextOrbitDelivery implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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
        return 'commander-orbit-delivery-admission';
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(
        NextEligibleIssue $issues,
        OrbitIssueOwnershipClaimer $ownership,
        OrbitDeliveryLoopStarter $loop,
    ): void {
        if (config('commander.delivery.orbit_auto_admission', false) !== true) {
            return;
        }

        $issue = $issues->candidate('orbit');

        if ($issue === null) {
            return;
        }

        $owned = $ownership->claim($issue);
        $loop->start('orbit', $owned->issueKey);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }
}
