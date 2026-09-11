<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class PreparedOrbitWorktreeRemoval
{
    public const int SCHEMA = 1;

    /**
     * @param  list<array{worktree: string, head: string, branch: ?string, prunable: bool}>  $protectedWorktrees
     * @param  array<string, string>  $protectedBranches
     * @param  array<string, string>  $evidenceArchives
     */
    public function __construct(
        public string $repository,
        public string $worktree,
        public string $issueKey,
        public string $branch,
        public string $candidateSha,
        public string $artifactRef,
        public string $artifactSha,
        public string $cleanupAttemptId,
        public ?string $proofAttemptId,
        public array $protectedWorktrees,
        public array $protectedBranches,
        public array $evidenceArchives,
        public string $authorizedAt,
    ) {
        if (! str_starts_with($repository, '/')
            || ! str_starts_with($worktree, '/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || $branch !== strtolower($issueKey)
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || $artifactRef !== "refs/tags/loop/{$branch}/{$candidateSha}"
            || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $cleanupAttemptId) !== 1
            || ($proofAttemptId !== null && preg_match('/^[a-f0-9]{32}$/', $proofAttemptId) !== 1)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $authorizedAt) !== 1) {
            throw new InvalidArgumentException('The prepared Orbit worktree removal evidence is invalid.');
        }

        self::validateProtectedWorktrees($protectedWorktrees);

        foreach ($protectedBranches as $ref => $sha) {
            if (preg_match('/^refs\/heads\/[^\s]+$/', $ref) !== 1
                || preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('The protected Orbit branch evidence is invalid.');
            }
        }

        $expectedArchives = $proofAttemptId === null ? [] : [
            ".e2e/proof-evidence/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review-evaluation/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-closeout/{$issueKey}/{$proofAttemptId}.json",
        ];

        if (array_keys($evidenceArchives) !== $expectedArchives) {
            throw new InvalidArgumentException('The prepared Orbit proof archive evidence is invalid.');
        }

        foreach ($evidenceArchives as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('The prepared Orbit proof archive evidence is invalid.');
            }
        }
    }

    /** @param array<array-key, mixed> $protectedWorktrees */
    private static function validateProtectedWorktrees(array $protectedWorktrees): void
    {
        if (! array_is_list($protectedWorktrees)) {
            throw new InvalidArgumentException('The protected Orbit worktree evidence is invalid.');
        }

        $paths = [];

        foreach ($protectedWorktrees as $protected) {
            if (! is_array($protected)
                || array_keys($protected) !== ['worktree', 'head', 'branch', 'prunable']
                || ! is_string($protected['worktree'] ?? null)
                || ! str_starts_with($protected['worktree'], '/')
                || ! is_string($protected['head'] ?? null)
                || preg_match('/^[a-f0-9]{40}$/', $protected['head']) !== 1
                || ! array_key_exists('branch', $protected)
                || ($protected['branch'] !== null && ! is_string($protected['branch']))
                || (is_string($protected['branch'])
                    && preg_match('/^refs\/heads\/[^\s]+$/', $protected['branch']) !== 1)
                || ($protected['prunable'] ?? null) !== false
                || in_array($protected['worktree'], $paths, true)) {
                throw new InvalidArgumentException('The protected Orbit worktree evidence is invalid.');
            }

            $paths[] = $protected['worktree'];
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (array_keys($value) !== [
            'schema',
            'repository',
            'worktree',
            'issue_key',
            'branch',
            'candidate_sha',
            'artifact_ref',
            'artifact_sha',
            'cleanup_attempt_id',
            'proof_attempt_id',
            'protected_worktrees',
            'protected_branches',
            'evidence_archives',
            'authorized_at',
        ]
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ! is_string($value['repository'] ?? null)
            || ! is_string($value['worktree'] ?? null)
            || ! is_string($value['issue_key'] ?? null)
            || ! is_string($value['branch'] ?? null)
            || ! is_string($value['candidate_sha'] ?? null)
            || ! is_string($value['artifact_ref'] ?? null)
            || ! is_string($value['artifact_sha'] ?? null)
            || ! is_string($value['cleanup_attempt_id'] ?? null)
            || ($value['proof_attempt_id'] !== null && ! is_string($value['proof_attempt_id']))
            || ! is_array($value['protected_worktrees'] ?? null)
            || ! array_is_list($value['protected_worktrees'])
            || ! is_array($value['protected_branches'] ?? null)
            || array_is_list($value['protected_branches']) && $value['protected_branches'] !== []
            || ! is_array($value['evidence_archives'] ?? null)
            || array_is_list($value['evidence_archives']) && $value['evidence_archives'] !== []
            || ! is_string($value['authorized_at'] ?? null)) {
            throw new InvalidArgumentException('The prepared Orbit worktree removal schema is invalid.');
        }

        return new self(
            repository: $value['repository'],
            worktree: $value['worktree'],
            issueKey: $value['issue_key'],
            branch: $value['branch'],
            candidateSha: $value['candidate_sha'],
            artifactRef: $value['artifact_ref'],
            artifactSha: $value['artifact_sha'],
            cleanupAttemptId: $value['cleanup_attempt_id'],
            proofAttemptId: $value['proof_attempt_id'],
            protectedWorktrees: $value['protected_worktrees'],
            protectedBranches: $value['protected_branches'],
            evidenceArchives: $value['evidence_archives'],
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
            'artifact_ref' => $this->artifactRef,
            'artifact_sha' => $this->artifactSha,
            'cleanup_attempt_id' => $this->cleanupAttemptId,
            'proof_attempt_id' => $this->proofAttemptId,
            'protected_worktrees' => $this->protectedWorktrees,
            'protected_branches' => $this->protectedBranches,
            'evidence_archives' => $this->evidenceArchives,
            'authorized_at' => $this->authorizedAt,
        ];
    }
}
