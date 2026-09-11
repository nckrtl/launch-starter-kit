<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;

interface OrbitProofTopologyCloser
{
    public function closeProofTopology(
        OrbitProjectConfig $config,
        string $worktree,
        string $issueKey,
        string $candidateSha,
        string $artifactSha,
        string $mergeCommitSha,
        string $mainSha,
    ): OrbitProofCloseout;
}
