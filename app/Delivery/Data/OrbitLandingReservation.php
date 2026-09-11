<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitLandingReservation
{
    public function __construct(
        public bool $owned,
        public string $issueId,
        public string $pullRequestUrl,
        public string $reservedAt,
    ) {}
}
