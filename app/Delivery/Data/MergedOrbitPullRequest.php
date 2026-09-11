<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class MergedOrbitPullRequest
{
    public function __construct(
        public int $number,
        public string $url,
        public string $candidateSha,
        public string $mergeCommitSha,
    ) {}
}
