<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;

interface OrbitIssueReader
{
    /** Read a validated issue without assigning it to any delivery controller. */
    public function read(string $issueId, string $issueKey): OrbitIssueSnapshot;
}
