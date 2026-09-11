<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrForegroundProcess
{
    public function __construct(
        public int $processId,
        public string $name,
    ) {}
}
