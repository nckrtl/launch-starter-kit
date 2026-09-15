<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskWorkspace;
use LogicException;

final readonly class TaskEvidenceSafety
{
    public function assertNoSecrets(TaskWorkspace $workspace, mixed $value): void
    {
        $json = TaskLandingData::json($value);
        if (strlen($json) > 8_388_608) {
            throw new LogicException('The landing evidence package is too large.');
        }
        foreach ($workspace->dispatches()->get() as $dispatch) {
            foreach ([$dispatch->handoff_token, $dispatch->prompt] as $private) {
                if ($private !== '' && (str_contains($json, $private)
                    || str_contains($json, trim(TaskLandingData::json($private), "\n\"")))) {
                    throw new LogicException('Landing evidence includes a private dispatch token or raw prompt.');
                }
            }
        }
    }
}
