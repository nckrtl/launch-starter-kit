<?php

declare(strict_types=1);

namespace App\Delivery\Config;

use App\Delivery\Contracts\ProjectConfigUpcaster;

final readonly class OrbitConfigV0ToV1 implements ProjectConfigUpcaster
{
    public function upcast(array $config): array
    {
        $config['defaultFlow'] ??= 'discovery';

        return $config;
    }
}
