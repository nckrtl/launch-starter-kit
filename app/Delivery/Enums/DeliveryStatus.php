<?php

declare(strict_types=1);

namespace App\Delivery\Enums;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case WaitingForAgent = 'waiting_for_agent';
    case ValidatingReceipt = 'validating_receipt';
    case WaitingForChanges = 'waiting_for_changes';
    case ReadyToMerge = 'ready_to_merge';
    case Merging = 'merging';
    case Landed = 'landed';
    case Cleaning = 'cleaning';
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Paused = 'paused';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }
}
