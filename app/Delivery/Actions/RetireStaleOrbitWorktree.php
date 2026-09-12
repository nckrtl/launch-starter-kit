<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitStaleWorktreeRetirer;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitStaleWorktree;
use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Throwable;

final readonly class RetireStaleOrbitWorktree
{
    public function __construct(
        private OrbitStaleWorktreeRetirer $worktrees,
        private HerdrWorkspaceRuntime $herdr,
    ) {}

    public function handle(
        OrbitProjectConfig $config,
        OrbitIssueSnapshot $issue,
    ): ?RetiredOrbitStaleWorktree {
        if (! $this->isTodo($issue)) {
            return null;
        }

        $stale = $this->worktrees->inspectStaleWorktree($config, $issue->issueKey);

        if ($stale === null) {
            return null;
        }

        $this->assertInactive($this->snapshot(), $stale);
        $this->assertInactive($this->snapshot(), $stale);

        return $this->worktrees->retireStaleWorktree($config, $stale);
    }

    private function snapshot(): HerdrSessionSnapshot
    {
        try {
            return $this->herdr->snapshot();
        } catch (Throwable $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree activity could not be verified.',
                previous: $exception,
            );
        }
    }

    private function isTodo(OrbitIssueSnapshot $issue): bool
    {
        $state = $issue->payload['state'] ?? null;

        return is_array($state)
            && ($state['name'] ?? null) === 'Todo'
            && ($state['type'] ?? null) === 'unstarted';
    }

    private function assertInactive(HerdrSessionSnapshot $snapshot, OrbitStaleWorktree $stale): void
    {
        foreach ($snapshot->workspaces as $workspace) {
            if ($this->inside($workspace->checkoutPath, $stale->worktree)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree still has a Herdr workspace.');
            }
        }

        foreach ($snapshot->panes as $pane) {
            if ($this->inside($pane->workingDirectory, $stale->worktree)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree still has a Herdr pane.');
            }
        }

        foreach ($snapshot->agents as $agent) {
            if ($this->inside($agent->workingDirectory, $stale->worktree)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree still has a Herdr agent.');
            }
        }
    }

    private function inside(?string $path, string $worktree): bool
    {
        if ($path === null) {
            return false;
        }

        $resolvedPath = realpath($path);
        $resolvedWorktree = realpath($worktree);
        $candidate = $resolvedPath === false ? rtrim($path, '/') : $resolvedPath;
        $root = $resolvedWorktree === false ? rtrim($worktree, '/') : $resolvedWorktree;

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }
}
