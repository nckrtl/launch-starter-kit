<?php

declare(strict_types=1);

namespace App\Tasks\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case AwaitingReview = 'awaiting_review';
    case AwaitingCommit = 'awaiting_commit';
    case ChangesRequested = 'changes_requested';
    case Completed = 'completed';
    case Failed = 'failed';
}
