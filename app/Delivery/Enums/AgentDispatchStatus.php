<?php

declare(strict_types=1);

namespace App\Delivery\Enums;

enum AgentDispatchStatus: string
{
    case Pending = 'pending';
    case Starting = 'starting';
    case Waiting = 'waiting';
    case Settled = 'settled';
    case Ambiguous = 'ambiguous';
    case Failed = 'failed';
}
