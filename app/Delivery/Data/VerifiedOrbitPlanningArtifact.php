<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class VerifiedOrbitPlanningArtifact
{
    public function __construct(
        public string $artifactSha,
        public string $planContentsHash,
    ) {}
}
