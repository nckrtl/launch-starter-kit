<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;

interface OrbitRepository
{
    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree;

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck;

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot;
}
