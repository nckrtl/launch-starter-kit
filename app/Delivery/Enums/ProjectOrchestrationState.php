<?php

declare(strict_types=1);

namespace App\Delivery\Enums;

enum ProjectOrchestrationState: string
{
    case Enabled = 'enabled';
    case Paused = 'paused';
}
