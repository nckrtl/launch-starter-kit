<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Models\Receipt;

final readonly class ValidatedReceipt
{
    public function __construct(public Receipt $receipt) {}
}
