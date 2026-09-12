<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use Carbon\CarbonImmutable;

final readonly class OrbitPullRequestReviewWaitPolicy
{
    public const int WAIT_SECONDS = 3600;

    public const int RECEIPT_GRACE_SECONDS = 300;

    public function waitDeadline(CarbonImmutable $dispatchedAt): CarbonImmutable
    {
        return $dispatchedAt->addSeconds(self::WAIT_SECONDS);
    }

    public function receiptDeadline(CarbonImmutable $settledAt): CarbonImmutable
    {
        return $settledAt->addSeconds(self::RECEIPT_GRACE_SECONDS);
    }

    public function isOverdue(CarbonImmutable $deadline, ?CarbonImmutable $at = null): bool
    {
        return ($at ?? now()->toImmutable())->greaterThanOrEqualTo($deadline);
    }
}
