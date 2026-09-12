<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitPlanningResolutionCorrection
{
    public function __construct(
        public string $issueId,
        public string $issueKey,
        public string $resolutionReceiptHash,
        public string $currentContractHash,
        public string $correctedContractHash,
        public string $correctedDescription,
        public string $correctedDescriptionHash,
    ) {}
}
