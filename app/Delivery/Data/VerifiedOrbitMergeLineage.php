<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class VerifiedOrbitMergeLineage
{
    public function __construct(
        public string $flow,
        public string $candidateSha,
        public string $mergeCommitSha,
        public string $treeSha,
    ) {
        if (! in_array($flow, ['discovery', 'proof'], true)) {
            throw new InvalidArgumentException('The verified Orbit merge flow is invalid.');
        }

        foreach ([$candidateSha, $mergeCommitSha, $treeSha] as $sha) {
            if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('The verified Orbit merge lineage contains an invalid SHA.');
            }
        }
    }
}
