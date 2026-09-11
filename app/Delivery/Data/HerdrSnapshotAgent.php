<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrSnapshotAgent
{
    public function __construct(
        public string $workspaceId,
        public string $tabId,
        public string $paneId,
        public string $terminalId,
        public ?string $kind,
        public ?string $name,
        public string $status,
        public ?string $workingDirectory,
    ) {}
}
