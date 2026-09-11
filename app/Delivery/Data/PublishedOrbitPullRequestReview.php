<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class PublishedOrbitPullRequestReview
{
    public function __construct(
        public int $id,
        public int $pullRequestNumber,
        public string $reviewerLogin,
        public string $candidateSha,
        public string $state,
        public string $reviewBodyHash,
        public string $pullRequestBodyHash,
    ) {}
}
