<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use Carbon\CarbonImmutable;

final readonly class VerifiedIssueSnapshot
{
    public function __construct(
        public PreparedIssueSnapshot $snapshot,
        public CarbonImmutable $verifiedAt,
    ) {}
}
