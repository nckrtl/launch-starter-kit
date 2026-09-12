<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitEligibleIssue
{
    /** @param list<string> $labels */
    public function __construct(
        public OrbitIssueSnapshot $snapshot,
        public string $title,
        public string $url,
        public array $labels,
    ) {}
}
