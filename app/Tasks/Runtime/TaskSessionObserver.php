<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskWorkspace;

interface TaskSessionObserver
{
    /**
     * @param  array<string, mixed>  $session
     * @return array{session: array<string, mixed>, process: array<string, mixed>}
     */
    public function observe(TaskWorkspace $workspace, array $session, string $conversation, bool $yielded): array;
}
