<?php

declare(strict_types=1);

namespace App\Tasks\Completion;

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Models\TaskLanding;

interface TaskCompletionIssue
{
    public function identityHash(): string;

    public function read(TaskLanding $landing): OrbitIssueSnapshot;

    public function complete(TaskLanding $landing, string $stateId): void;

    /** Completion needs a validated own/foreign distinction, not a swallowed legacy reservation error.
     * @param  array<string,mixed>  $pr
     * @return array<string,mixed>
     */
    public function reservation(TaskLanding $landing, array $pr): array;
}
