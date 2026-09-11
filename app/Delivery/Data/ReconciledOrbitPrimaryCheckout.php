<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class ReconciledOrbitPrimaryCheckout
{
    public function __construct(
        public string $repository,
        public string $mergeCommitSha,
        public string $mainSha,
        public string $originMainSha,
    ) {
        foreach ([$mergeCommitSha, $mainSha, $originMainSha] as $sha) {
            if (preg_match('/^[a-f0-9]{40}$/', $sha) !== 1) {
                throw new InvalidArgumentException('The reconciled Orbit primary checkout contains an invalid SHA.');
            }
        }

        if ($mainSha !== $originMainSha) {
            throw new InvalidArgumentException('The reconciled Orbit primary checkout is not at origin/main.');
        }
    }
}
