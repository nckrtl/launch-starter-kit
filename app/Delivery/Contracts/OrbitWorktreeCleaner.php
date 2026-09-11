<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\PreparedOrbitWorktreeRemoval;
use App\Delivery\Data\RemovedOrbitWorktree;

interface OrbitWorktreeCleaner
{
    public function prepareWorktreeRemoval(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
    ): PreparedOrbitWorktreeRemoval;

    public function removeWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
        PreparedOrbitWorktreeRemoval $authorization,
        bool $resume,
    ): RemovedOrbitWorktree;
}
