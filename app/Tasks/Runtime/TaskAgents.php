<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskWorkspace;

interface TaskAgents
{
    /** @param array<string, mixed> $session */
    public function assertSession(TaskWorkspace $workspace, array $session): void;

    /** @return array<string, mixed> */
    public function start(TaskWorkspace $workspace, string $name): array;

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array;
}
