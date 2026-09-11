<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class OrbitProofCloseout
{
    public const int SCHEMA = 1;

    public function __construct(
        public string $state,
        public string $issueKey,
        public string $attemptId,
        public string $candidateSha,
        public string $artifactSha,
        public string $mergeCommitSha,
        public string $mainSha,
        public ?string $generationId,
        public ?string $error,
        public string $recordedAt,
    ) {
        if (! in_array($state, ['refresh-failed', 'replacement-failed', 'complete'], true)) {
            throw new InvalidArgumentException('The Orbit proof closeout state is invalid.');
        }

        if (preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $attemptId) !== 1) {
            throw new InvalidArgumentException('The Orbit proof closeout identity is invalid.');
        }

        foreach ([$candidateSha, $artifactSha, $mergeCommitSha, $mainSha] as $sha) {
            if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('The Orbit proof closeout contains an invalid SHA.');
            }
        }

        if ($generationId !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $generationId) !== 1) {
            throw new InvalidArgumentException('The Orbit proof closeout generation is invalid.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $recordedAt) !== 1) {
            throw new InvalidArgumentException('The Orbit proof closeout time is invalid.');
        }

        if ($this->complete()) {
            if ($generationId === null || $error !== null) {
                throw new InvalidArgumentException('A completed Orbit proof closeout requires its generation.');
            }

            return;
        }

        if (! is_string($error) || trim($error) === '') {
            throw new InvalidArgumentException('A failed Orbit proof closeout requires its error.');
        }
    }

    public function complete(): bool
    {
        return $this->state === 'complete';
    }

    /** @return array{schema: int, state: string, issue: string, attempt_id: string, candidate_sha: string, artifact_sha: string, merge_sha: string, main_sha: string, generation_id: string|null, error: string|null, recorded_at: string} */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => $this->state,
            'issue' => $this->issueKey,
            'attempt_id' => $this->attemptId,
            'candidate_sha' => $this->candidateSha,
            'artifact_sha' => $this->artifactSha,
            'merge_sha' => $this->mergeCommitSha,
            'main_sha' => $this->mainSha,
            'generation_id' => $this->generationId,
            'error' => $this->error,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
