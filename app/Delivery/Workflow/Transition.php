<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Enums\DeliveryStatus;

final readonly class Transition
{
    public function __construct(
        public ?Phase $nextPhase,
        public DeliveryStatus $status,
    ) {}

    public static function to(Phase $phase): self
    {
        return new self($phase, DeliveryStatus::Queued);
    }

    public static function complete(): self
    {
        return new self(null, DeliveryStatus::Completed);
    }
}
