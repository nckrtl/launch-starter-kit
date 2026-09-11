<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrAgentIdentifiers
{
    public function __construct(
        public string $workspaceId,
        public string $tabId,
        public string $paneId,
        public string $terminalId,
        public ?string $agentId,
        public string $agentName,
        public ?int $stateChangeSeq = null,
        public ?string $workingDirectory = null,
        public ?string $agentStatus = null,
    ) {}
}
