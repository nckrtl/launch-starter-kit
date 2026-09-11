<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class PreparedOrbitAbandonedWorktreeCleanup
{
    public const int SCHEMA = 1;

    /**
     * @param  list<array{worktree: string, head: string, branch: ?string, prunable: bool}>  $protectedWorktrees
     * @param  array<string, string>  $protectedBranches
     */
    public function __construct(
        public string $repository,
        public string $worktree,
        public string $issueKey,
        public string $branch,
        public string $candidateSha,
        public string $cleanupAttemptId,
        public string $disposition,
        public array $protectedWorktrees,
        public array $protectedBranches,
        public string $authorizedAt,
    ) {
        if (! str_starts_with($repository, '/')
            || ! str_starts_with($worktree, '/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || $branch !== strtolower($issueKey)
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $cleanupAttemptId) !== 1
            || ! in_array($disposition, ['remove', 'already_absent'], true)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $authorizedAt) !== 1) {
            throw new InvalidArgumentException('The prepared abandoned Orbit worktree cleanup evidence is invalid.');
        }

        self::validateProtectedWorktrees($protectedWorktrees);

        foreach ($protectedBranches as $ref => $sha) {
            if (preg_match('/^refs\/heads\/[^\s]+$/', $ref) !== 1
                || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('The protected abandoned Orbit branch evidence is invalid.');
            }
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== [
            'schema', 'repository', 'worktree', 'issue_key', 'branch', 'candidate_sha',
            'cleanup_attempt_id', 'disposition', 'protected_worktrees', 'protected_branches',
            'authorized_at',
        ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['repository'] ?? null)
            || ! is_string($value['worktree'] ?? null)
            || ! is_string($value['issue_key'] ?? null)
            || ! is_string($value['branch'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['cleanup_attempt_id'] ?? null)
            || ! is_string($value['disposition'] ?? null)
            || ! is_array($value['protected_worktrees'] ?? null)
            || ! array_is_list($value['protected_worktrees'])
            || ! is_array($value['protected_branches'] ?? null)
            || array_is_list($value['protected_branches']) && $value['protected_branches'] !== []
            || ! is_string($value['authorized_at'] ?? null)) {
            throw new InvalidArgumentException('The prepared abandoned Orbit worktree cleanup schema is invalid.');
        }

        return new self(
            repository: $value['repository'],
            worktree: $value['worktree'],
            issueKey: $value['issue_key'],
            branch: $value['branch'],
            candidateSha: $value['candidate_sha'],
            cleanupAttemptId: $value['cleanup_attempt_id'],
            disposition: $value['disposition'],
            protectedWorktrees: $value['protected_worktrees'],
            protectedBranches: $value['protected_branches'],
            authorizedAt: $value['authorized_at'],
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
            'protected_worktrees' => $this->protectedWorktrees,
            'protected_branches' => $this->protectedBranches,
            'authorized_at' => $this->authorizedAt,
        ];
    }

    /** @param array<array-key, mixed> $worktrees */
    private static function validateProtectedWorktrees(array $worktrees): void
    {
        if (! array_is_list($worktrees)) {
            throw new InvalidArgumentException('The protected abandoned Orbit worktree evidence is invalid.');
        }

        $paths = [];

        foreach ($worktrees as $worktree) {
            if (! is_array($worktree)
                || array_keys($worktree) !== ['worktree', 'head', 'branch', 'prunable']
                || ! is_string($worktree['worktree'] ?? null)
                || ! str_starts_with($worktree['worktree'], '/')
                || ! is_string($worktree['head'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/', $worktree['head']) !== 1
                || ! array_key_exists('branch', $worktree)
                || ($worktree['branch'] !== null && ! is_string($worktree['branch']))
                || (is_string($worktree['branch'])
                    && preg_match('/^refs\/heads\/[^\s]+$/', $worktree['branch']) !== 1)
                || ($worktree['prunable'] ?? null) !== false
                || in_array($worktree['worktree'], $paths, true)) {
                throw new InvalidArgumentException('The protected abandoned Orbit worktree evidence is invalid.');
            }

            $paths[] = $worktree['worktree'];
        }
    }
}
