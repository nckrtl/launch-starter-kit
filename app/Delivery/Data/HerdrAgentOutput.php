<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrAgentOutput
{
    public function __construct(
        public string $workspaceId,
        public string $tabId,
        public string $paneId,
        public string $text,
    ) {}
}
