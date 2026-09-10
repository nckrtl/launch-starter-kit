<?php

declare(strict_types=1);

namespace App\Delivery\Enums;

enum PhaseRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
}
