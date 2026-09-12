<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class RetiredOrbitStaleWorktree
{
    public const int SCHEMA = 1;

    public function __construct(
        public string $repository,
        public string $worktree,
        public string $issueKey,
        public string $branch,
        public string $headSha,
        public string $treeSha,
        public string $retainedRef,
        public string $archive,
        public string $archiveDigest,
        public string $disposition,
        public string $retiredAt,
    ) {
        $prefix = strtolower($issueKey).'-';
        $expectedRef = 'refs/orbit-delivery/retired-worktrees/'.strtolower($issueKey).'/'.$headSha;

        if (! str_starts_with($repository, '/')
            || ! str_starts_with($worktree, '/')
            || ! str_starts_with($archive, '/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || ! str_starts_with($branch, $prefix)
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $branch) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $headSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $treeSha) !== 1
            || $retainedRef !== $expectedRef
            || preg_match('/^[a-f0-9]{64}$/', $archiveDigest) !== 1
            || ! in_array($disposition, ['retired', 'already_retired'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $retiredAt) !== 1) {
            throw new InvalidArgumentException('The retired Orbit stale worktree evidence is invalid.');
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== [
            'schema', 'repository', 'worktree', 'issue_key', 'branch', 'head_sha',
            'tree_sha', 'retained_ref', 'archive', 'archive_digest', 'disposition',
            'retired_at',
        ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['repository'] ?? null)
            || ! is_string($value['worktree'] ?? null)
            || ! is_string($value['issue_key'] ?? null)
            || ! is_string($value['branch'] ?? null)
            || ! is_string($value['head_sha'] ?? null)
            || ! is_string($value['tree_sha'] ?? null)
            || ! is_string($value['retained_ref'] ?? null)
            || ! is_string($value['archive'] ?? null)
            || ! is_string($value['archive_digest'] ?? null)
            || ! is_string($value['disposition'] ?? null)
            || ! is_string($value['retired_at'] ?? null)) {
            throw new InvalidArgumentException('The retired Orbit stale worktree evidence schema is invalid.');
        }

        return new self(
            repository: $value['repository'],
            worktree: $value['worktree'],
            issueKey: $value['issue_key'],
            branch: $value['branch'],
            headSha: $value['head_sha'],
            treeSha: $value['tree_sha'],
            retainedRef: $value['retained_ref'],
            archive: $value['archive'],
            archiveDigest: $value['archive_digest'],
            disposition: $value['disposition'],
            retiredAt: $value['retired_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'repository' => $this->repository,
            'worktree' => $this->worktree,
            'issue_key' => $this->issueKey,
            'branch' => $this->branch,
            'head_sha' => $this->headSha,
            'tree_sha' => $this->treeSha,
            'retained_ref' => $this->retainedRef,
            'archive' => $this->archive,
            'archive_digest' => $this->archiveDigest,
            'disposition' => $this->disposition,
            'retired_at' => $this->retiredAt,
        ];
    }
}
