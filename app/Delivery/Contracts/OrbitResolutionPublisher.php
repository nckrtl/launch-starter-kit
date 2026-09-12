<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PublishedOrbitResolution;

interface OrbitResolutionPublisher
{
    public function publish(
        OrbitIssueSnapshot $issue,
        int $dispatchId,
        string $handoff,
        bool $adopted,
        string $resumePhase,
    ): PublishedOrbitResolution;
}
