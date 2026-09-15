<?php

declare(strict_types=1);

namespace App\Tasks\Enums;

enum TaskReviewVerdict: string
{
    case Pass = 'pass';
    case Revise = 'revise';
}
