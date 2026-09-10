<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Models\Delivery;
use App\Models\PhaseRun;

final readonly class BlockContext
{
    public function __construct(
        public Delivery $delivery,
        public PhaseRun $phaseRun,
        public IdempotencyKey $idempotencyKey,
    ) {}
}
