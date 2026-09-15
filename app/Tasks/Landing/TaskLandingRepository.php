<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskWorkspace;

interface TaskLandingRepository
{
    /** @return array<string, mixed> */
    public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array;

    /** @param array<string, mixed> $inputs */
    public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string;

    /** @param array<string, mixed> $inputs */
    public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void;
}
