<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

final readonly class OrbitFeatureWorkflow
{
    public const string TYPE = 'orbit-feature';

    public const int VERSION = 1;

    public const string INITIAL_PHASE = 'planning';
}
