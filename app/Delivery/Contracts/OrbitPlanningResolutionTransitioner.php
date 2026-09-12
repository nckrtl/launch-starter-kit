<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitPlanningResolutionCorrection;

interface OrbitPlanningResolutionTransitioner
{
    public function applyPlanningResolution(
        OrbitIssueSnapshot $current,
        OrbitPlanningResolutionCorrection $correction,
    ): OrbitIssueSnapshot;
}
