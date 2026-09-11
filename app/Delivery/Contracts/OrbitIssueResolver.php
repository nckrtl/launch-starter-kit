<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueResolver
{
    public function resolve(string $issueKey): OrbitIssueSnapshot;
}
