<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class VerifiedOrbitImplementationOutcome
{
    public function __construct(
        public string $candidateSha,
        public string $treeSha,
        public string $artifactSha,
        public string $gateReceiptPath,
        public string $pullRequestBodyHash,
        public string $flow,
    ) {}
}
