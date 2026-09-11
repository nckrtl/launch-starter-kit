<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitCloseoutIssueProvider
{
    public function fetchForCloseout(string $issueId, string $issueKey): OrbitIssueSnapshot;
}
