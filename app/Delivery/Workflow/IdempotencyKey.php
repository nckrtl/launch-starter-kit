<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

final readonly class IdempotencyKey
{
    public function __construct(public string $value) {}

    public static function forDispatch(int $deliveryId, string $phase, int $attempt, string $role): self
    {
        return new self(hash('sha256', implode('|', [$deliveryId, $phase, $attempt, $role])));
    }
}
