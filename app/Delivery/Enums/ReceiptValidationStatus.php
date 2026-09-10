<?php

declare(strict_types=1);

namespace App\Delivery\Enums;

enum ReceiptValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
}
