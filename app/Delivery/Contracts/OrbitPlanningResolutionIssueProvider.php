<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitPlanningResolutionIssueProvider
{
    public function fetchForPlanningResolution(string $issueId, string $issueKey): OrbitIssueSnapshot;
}
