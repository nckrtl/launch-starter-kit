<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

enum BlockResult: string
{
    case Completed = 'completed';
    case Waiting = 'waiting';
    case RetryableFailure = 'retryable_failure';
    case Blocked = 'blocked';
    case PermanentFailure = 'permanent_failure';
}
