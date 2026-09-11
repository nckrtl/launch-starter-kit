<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;

interface HerdrWorkspaceRuntime
{
    public function snapshot(): HerdrSessionSnapshot;

    public function readAgent(string $name): HerdrAgentOutput;

    /** @param list<string> $keys */
    public function sendAgentKeys(string $name, array $keys): void;

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo;

    public function closeWorkspace(string $workspaceId, int $protocol): void;
}
