<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueCompletionTransitioner
{
    public function transitionToDone(
        string $issueId,
        string $issueKey,
        string $expectedContractHash,
    ): OrbitIssueSnapshot;
}
