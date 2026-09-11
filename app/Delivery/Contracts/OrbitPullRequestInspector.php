<?php

declare(strict_types=1);

namespace App\Delivery\Contracts;

use App\Delivery\Data\PublishedOrbitPullRequest;

interface OrbitPullRequestInspector
{
    public function inspect(
        int $number,
        string $issueKey,
        string $candidateSha,
        string $pullRequestBody,
    ): PublishedOrbitPullRequest;
}
