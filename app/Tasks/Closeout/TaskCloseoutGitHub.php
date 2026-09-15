<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskLanding;

interface TaskCloseoutGitHub
{
    public function identityHash(): string;

    /** @return array<string,mixed>|null */
    public function publication(TaskLanding $landing): ?array;

    public function publish(TaskLanding $landing): void;

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function publicationRevision(TaskLanding $landing, array $request): array;

    /** @param array<string,mixed> $request */
    public function revisePublication(TaskLanding $landing, array $request): void;

    /** @param array<string,mixed> $pr
     * @return array<string,mixed>|null
     */
    public function approval(TaskLanding $landing, array $pr, string $marker, bool $forMerge = false): ?array;

    /** @param array<string,mixed> $pr */
    public function approve(TaskLanding $landing, array $pr, string $marker): void;

    /** @param array<string,mixed> $pr
     * @return array<string,mixed>|null
     */
    public function reservation(TaskLanding $landing, array $pr): ?array;

    /** @param array<string,mixed> $pr */
    public function reserve(TaskLanding $landing, array $pr): void;

    /** @param array<string,mixed> $pr
     * @return array<string,mixed>|null
     */
    public function merged(TaskLanding $landing, array $pr): ?array;

    /** @param array<string,mixed> $pr */
    public function merge(TaskLanding $landing, array $pr): void;

    /** @param array<string,mixed> $pr */
    public function release(TaskLanding $landing, array $pr): void;
}
