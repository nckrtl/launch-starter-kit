<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;

interface OrbitMainCorrectnessInspector
{
    public function inspectMainCorrectness(OrbitProjectConfig $config): OrbitMainCorrectness;
}
