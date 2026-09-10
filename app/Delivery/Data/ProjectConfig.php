<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use Spatie\LaravelData\Data;

abstract class ProjectConfig extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly int $version,
    ) {}
}
