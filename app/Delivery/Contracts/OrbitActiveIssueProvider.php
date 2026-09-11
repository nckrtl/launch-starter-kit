<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitActiveIssueProvider
{
    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot;
}
