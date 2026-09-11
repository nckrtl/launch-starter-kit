<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\CleanedOrbitAbandonedWorktree;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedOrbitAbandonedWorktreeCleanup;

interface OrbitAbandonedWorktreeCleaner
{
    public function prepareAbandonedWorktreeCleanup(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
    ): PreparedOrbitAbandonedWorktreeCleanup;

    public function cleanupAbandonedWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
        bool $resume,
    ): CleanedOrbitAbandonedWorktree;
}
