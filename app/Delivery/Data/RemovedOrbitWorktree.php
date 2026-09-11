<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class RemovedOrbitWorktree
{
    public const int SCHEMA = 1;

    /** @param array<string, string> $evidenceArchives */
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
        public array $evidenceArchives,
        public string $removedAt,
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
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $removedAt) !== 1) {
            throw new InvalidArgumentException('The removed Orbit worktree evidence is invalid.');
        }

        $expectedArchives = $proofAttemptId === null ? [] : [
            ".e2e/proof-evidence/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review-evaluation/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-closeout/{$issueKey}/{$proofAttemptId}.json",
        ];

        if (array_keys($evidenceArchives) !== $expectedArchives) {
            throw new InvalidArgumentException('The removed Orbit worktree archive evidence is invalid.');
        }

        foreach ($evidenceArchives as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('The removed Orbit worktree archive evidence is invalid.');
            }
        }
    }

    /** @return array{schema: int, repository: string, worktree: string, issue_key: string, branch: string, candidate_sha: string, artifact_ref: string, artifact_sha: string, cleanup_attempt_id: string, proof_attempt_id: ?string, evidence_archives: array<string, string>, removed_at: string} */
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
            'evidence_archives' => $this->evidenceArchives,
            'removed_at' => $this->removedAt,
        ];
    }
}
