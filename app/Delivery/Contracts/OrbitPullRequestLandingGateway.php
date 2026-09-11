<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\ApprovedOrbitPullRequest;
use App\Delivery\Data\MergedOrbitPullRequest;
use App\Delivery\Data\OrbitLandingReservation;

interface OrbitPullRequestLandingGateway
{
    public function reserve(string $issueId, string $pullRequestUrl): OrbitLandingReservation;

    public function inspectApproved(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
        int $publishedReviewId,
    ): ApprovedOrbitPullRequest;

    public function merge(int $number, string $candidateSha): MergedOrbitPullRequest;

    public function release(string $issueId, string $pullRequestUrl): void;
}
