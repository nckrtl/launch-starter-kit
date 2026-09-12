<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitResolutionAdoption
{
    /** @param list<string> $requirements */
    public function __construct(
        public bool $adopt,
        public string $expectedResumePhase,
        public array $requirements,
        public string $reason,
    ) {}
}
