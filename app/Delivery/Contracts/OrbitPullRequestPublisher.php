<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\PublishedOrbitPullRequest;

interface OrbitPullRequestPublisher
{
    public function publish(
        string $issueKey,
        string $issueTitle,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest;
}
