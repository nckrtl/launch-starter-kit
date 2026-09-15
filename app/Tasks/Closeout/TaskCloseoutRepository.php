<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Models\TaskLanding;

interface TaskCloseoutRepository
{
    /** @return array<string,mixed>|null */
    public function branch(TaskLanding $landing): ?array;

    public function push(TaskLanding $landing): void;

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function branchRevision(TaskLanding $landing, array $request): array;

    /** @param array<string,mixed> $request */
    public function reviseBranch(TaskLanding $landing, array $request): void;

    public function main(OrbitProjectConfig $configuration): OrbitMainCorrectness;

    /** Explicit post-merge verification may fetch the authoritative main objects.
     * @param  array<string,mixed>  $merge
     * @return array<string,mixed>
     */
    public function verify(TaskLanding $landing, array $merge): array;

    public function contains(OrbitProjectConfig $configuration, string $ancestor, string $main): bool;
}
