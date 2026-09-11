<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitMainCorrectness
{
    /** @param array<string, mixed> $failures */
    public function __construct(
        public string $mainSha,
        public array $failures,
    ) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }
}
