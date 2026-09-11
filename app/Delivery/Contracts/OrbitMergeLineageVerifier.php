<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\VerifiedOrbitMergeLineage;

interface OrbitMergeLineageVerifier
{
    public function verifyMergeLineage(
        OrbitProjectConfig $config,
        string $worktree,
        string $candidateSha,
        string $mergeCommitSha,
    ): VerifiedOrbitMergeLineage;
}
