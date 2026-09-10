<?php

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\VerifiedIssueSnapshot;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        config(['inertia.ssr.enabled' => false]);

        $this->withoutVite();
    })
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Browser');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function verifiedOrbitIssueSnapshot(string $issueId, string $issueKey, string $path): VerifiedIssueSnapshot
{
    return new VerifiedIssueSnapshot(
        snapshot: new PreparedIssueSnapshot(
            schema: OrbitIssueSnapshot::SCHEMA,
            provider: OrbitIssueSnapshot::PROVIDER,
            path: $path,
            contentsHash: str_repeat('c', 64),
            contractSchema: OrbitIssueSnapshot::CONTRACT_SCHEMA,
            contractHash: str_repeat('d', 64),
            issueId: $issueId,
            issueKey: $issueKey,
        ),
        verifiedAt: now()->toImmutable(),
    );
}
