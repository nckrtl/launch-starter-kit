<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;

interface OrbitRepository
{
    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree;
}
