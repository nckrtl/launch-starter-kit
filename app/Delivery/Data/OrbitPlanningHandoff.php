<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use Carbon\CarbonImmutable;

final readonly class OrbitPlanningHandoff
{
    public const int SCHEMA = 1;

    public int $schema;

    public string $phase;

    public string $flow;

    public bool $dispatchable;

    /** @param array<string, mixed> $issue */
    public function __construct(
        public int $deliveryId,
        public string $provider,
        public string $issueId,
        public string $issueKey,
        public array $issue,
        public string $issueUpdatedAt,
        public string $worktreePath,
        public string $branch,
        public string $candidateSha,
        public string $treeSha,
        public string $qualityReceiptPath,
        public string $snapshotPath,
        public string $snapshotContentsHash,
        public int $contractSchema,
        public string $contractHash,
        public CarbonImmutable $verifiedAt,
    ) {
        $this->schema = self::SCHEMA;
        $this->phase = 'planning';
        $this->flow = 'discovery';
        $this->dispatchable = false;
    }
}
