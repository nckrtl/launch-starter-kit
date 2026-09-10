<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class VerifiedOrbitPlanningRepository
{
    public function __construct(
        public string $worktreePath,
        public string $branch,
        public string $candidateSha,
        public string $treeSha,
        public string $flow,
        public string $qualityReceiptPath,
        public string $snapshotPath,
        public string $snapshotContentsHash,
    ) {}
}
