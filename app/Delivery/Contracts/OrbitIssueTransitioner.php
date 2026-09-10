<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueTransitioner
{
    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot;
}
