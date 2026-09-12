<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueOwnershipClaimer
{
    public function claim(OrbitEligibleIssue $issue): OrbitIssueSnapshot;
}
