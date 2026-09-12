<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class OrbitStaleWorktree
{
    public function __construct(
        public string $repository,
        public string $worktree,
        public string $issueKey,
        public string $branch,
        public string $headSha,
        public string $treeSha,
    ) {
        $prefix = strtolower($issueKey).'-';

        if (! str_starts_with($repository, '/')
            || ! str_starts_with($worktree, '/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || ! str_starts_with($branch, $prefix)
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $branch) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $headSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $treeSha) !== 1) {
            throw new InvalidArgumentException('The stale Orbit worktree evidence is invalid.');
        }
    }
}
