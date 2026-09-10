<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

final readonly class Phase
{
    public function __construct(
        public string $name,
        public string $agentRole,
        public string $prompt,
    ) {}
}
