<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskWorkspace;

interface TaskLandingReviewer
{
    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function observe(TaskWorkspace $workspace, array $session, bool $yielded): array;

    /** @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function promptOnce(TaskWorkspace $workspace, array $session, string $prompt): array;
}
