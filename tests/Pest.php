<?php

// Isolate DB before any Laravel boot (Process/.env can leak DB_DATABASE).
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('DB_URL=');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['DB_URL'] = '';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
$_SERVER['DB_URL'] = '';

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
