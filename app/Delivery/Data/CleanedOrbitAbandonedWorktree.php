<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class CleanedOrbitAbandonedWorktree
{
    public const int SCHEMA = 1;

    public function __construct(
        public string $repository,
        public string $worktree,
        public string $issueKey,
        public string $branch,
        public string $candidateSha,
        public string $cleanupAttemptId,
        public string $disposition,
        public string $recordedAt,
    ) {
        if (! str_starts_with($repository, '/')
            || ! str_starts_with($worktree, '/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || $branch !== strtolower($issueKey)
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $cleanupAttemptId) !== 1
            || ! in_array($disposition, ['removed', 'already_absent'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $recordedAt) !== 1) {
            throw new InvalidArgumentException('The abandoned Orbit worktree cleanup evidence is invalid.');
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== [
            'schema', 'repository', 'worktree', 'issue_key', 'branch', 'candidate_sha',
            'cleanup_attempt_id', 'disposition', 'recorded_at',
        ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['repository'] ?? null)
            || ! is_string($value['worktree'] ?? null)
            || ! is_string($value['issue_key'] ?? null)
            || ! is_string($value['branch'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['cleanup_attempt_id'] ?? null)
            || ! is_string($value['disposition'] ?? null)
            || ! is_string($value['recorded_at'] ?? null)) {
            throw new InvalidArgumentException('The abandoned Orbit worktree cleanup evidence schema is invalid.');
        }

        return new self(
            repository: $value['repository'],
            worktree: $value['worktree'],
            issueKey: $value['issue_key'],
            branch: $value['branch'],
            candidateSha: $value['candidate_sha'],
            cleanupAttemptId: $value['cleanup_attempt_id'],
            disposition: $value['disposition'],
            recordedAt: $value['recorded_at'],
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
            'candidate_sha' => $this->candidateSha,
            'cleanup_attempt_id' => $this->cleanupAttemptId,
            'disposition' => $this->disposition,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
