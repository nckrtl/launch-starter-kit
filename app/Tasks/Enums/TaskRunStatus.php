<?php

declare(strict_types=1);

namespace App\Tasks\Enums;

enum TaskRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
