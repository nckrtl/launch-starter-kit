<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueProvider
{
    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot;
}
