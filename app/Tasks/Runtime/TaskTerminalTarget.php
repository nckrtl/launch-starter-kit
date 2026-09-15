<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

final readonly class TaskTerminalTarget
{
    public function __construct(
        public string $role,
        public string $workspaceId,
        public string $paneId,
        public string $terminalId,
        public string $status,
        public ?string $agentName,
        public ?string $workingDirectory,
        public int $orbitHerdrSessionId,
        public string $orbitHerdrObserverOrigin,
        public int $orbitNodeId,
        public string $orbitHerdrSession,
    ) {}
}
