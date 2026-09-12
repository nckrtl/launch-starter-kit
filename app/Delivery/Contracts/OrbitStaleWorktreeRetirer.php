<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitStaleWorktree;
use App\Delivery\Data\RetiredOrbitStaleWorktree;

interface OrbitStaleWorktreeRetirer
{
    public function inspectStaleWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
    ): ?OrbitStaleWorktree;

    public function retireStaleWorktree(
        OrbitProjectConfig $config,
        OrbitStaleWorktree $worktree,
    ): RetiredOrbitStaleWorktree;
}
