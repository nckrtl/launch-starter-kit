<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitEligibleIssue;

interface OrbitEligibleIssueProvider
{
    public function next(): ?OrbitEligibleIssue;
}
