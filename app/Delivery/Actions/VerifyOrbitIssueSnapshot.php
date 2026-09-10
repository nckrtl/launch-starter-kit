<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueContractChanged;

final readonly class VerifyOrbitIssueSnapshot
{
    public function __construct(private OrbitRepository $repository) {}

    public function handle(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $prepared,
        OrbitIssueSnapshot $current,
    ): VerifiedIssueSnapshot {
        $this->repository->verifyIssueSnapshot($config, $worktree, $prepared);

        if ($current->issueId !== $prepared->issueId
            || $current->issueKey !== $prepared->issueKey
            || ! hash_equals($prepared->contractHash, $current->contractHash)) {
            throw new OrbitIssueContractChanged('The Orbit issue contract changed before dispatch.');
        }

        return new VerifiedIssueSnapshot($prepared, now()->toImmutable());
    }
}
