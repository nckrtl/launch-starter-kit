<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\PublishedOrbitPullRequestReview;

interface OrbitPullRequestReviewPublisher
{
    public function publishReview(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $submittedPullRequestBody,
        string $result,
        string $handoff,
        ?string $approvedPullRequestBody,
    ): PublishedOrbitPullRequestReview;
}
