<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OpenedHerdrWorktree
{
    public function __construct(
        public string $workspaceId,
        public string $tabId,
        public string $paneId,
        public string $terminalId,
        public bool $alreadyOpen,
    ) {}
}
