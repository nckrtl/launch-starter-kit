<?php

declare(strict_types=1);

namespace App\Delivery\Config;

use App\Delivery\Contracts\ProjectConfigUpcaster;
use App\Delivery\Data\ProjectConfig;

final readonly class ProjectConfigDefinition
{
    /**
     * @param  class-string<ProjectConfig>  $dataClass
     * @param  list<string>  $allowedKeys
     * @param  array<int, ProjectConfigUpcaster>  $upcasters
     */
    public function __construct(
        public string $type,
        public int $currentVersion,
        public string $dataClass,
        public array $allowedKeys,
        public array $upcasters = [],
    ) {}
}
