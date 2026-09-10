<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitIssueSnapshot
{
    public const int SCHEMA = 1;

    public const string PROVIDER = 'linear';

    public const int CONTRACT_SCHEMA = 1;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $issueId,
        public string $issueKey,
        public array $payload,
        public string $contractHash,
    ) {}
}
