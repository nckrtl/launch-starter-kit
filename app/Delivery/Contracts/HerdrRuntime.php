<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OpenedHerdrWorktree;

interface HerdrRuntime
{
    public function openWorktree(string $path): OpenedHerdrWorktree;

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers;

    public function startAgent(string $paneId, string $name): HerdrAgentIdentifiers;

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers;

    public function getAgent(string $name): HerdrAgentIdentifiers;
}
