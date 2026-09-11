<?php

declare(strict_types=1);

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedOrbitAbandonedWorktreeCleanup;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-abandoned-cleanup-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktree = $this->worktreeRoot.'/orb-234';
    $this->otherWorktree = $this->worktreeRoot.'/orb-999';
    $this->candidateSha = str_repeat('a', 40);
    $this->mainSha = str_repeat('b', 40);
    $this->otherSha = str_repeat('c', 40);
    $this->cleanupAttemptId = str_repeat('d', 32);

    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->repository.'/.git', 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    File::makeDirectory($this->otherWorktree, 0755, true);
    File::put($this->worktree.'/.git', "gitdir: {$this->repository}/.git/worktrees/orb-234\n");
    File::put($this->repository.'/bin/worktree-remove', "#!/usr/bin/env bash\n");
    chmod($this->repository.'/bin/worktree-remove', 0755);

    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: $this->repository,
        worktreeRoot: $this->worktreeRoot,
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * @param  list<array{worktree: string, head: string, branch: ?string, prunable?: bool}>  $worktrees
 */
function abandonedCleanupWorktrees(array $worktrees): string
{
    return implode("\0\0", array_map(static function (array $worktree): string {
        $fields = [
            'worktree '.$worktree['worktree'],
            'HEAD '.$worktree['head'],
            $worktree['branch'] === null ? 'detached' : 'branch '.$worktree['branch'],
        ];

        if ($worktree['prunable'] ?? false) {
            $fields[] = 'prunable gitdir file points to non-existent location';
        }

        return implode("\0", $fields);
    }, $worktrees))."\0\0";
}

/** @param array<string, string> $branches */
function abandonedCleanupBranches(array $branches): string
{
    return implode("\n", array_map(
        static fn (string $sha, string $ref): string => $sha.' '.$ref,
        $branches,
        array_keys($branches),
    ))."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return object{commands: list<array<int, string>>}
 */
function fakeAbandonedCleanup(object $test, array $overrides = []): object
{
    $primary = [
        'worktree' => $test->repository,
        'head' => $test->mainSha,
        'branch' => 'refs/heads/main',
    ];
    $target = [
        'worktree' => $test->worktree,
        'head' => $test->candidateSha,
        'branch' => 'refs/heads/orb-234',
        'prunable' => $overrides['target_prunable_initial'] ?? false,
    ];
    $other = [
        'worktree' => $test->otherWorktree,
        'head' => $test->otherSha,
        'branch' => 'refs/heads/orb-999',
    ];
    $presentBefore = $overrides['present_before'] ?? true;
    $presentAfter = $overrides['present_after'] ?? false;
    $branchBefore = $overrides['branch_before'] ?? $presentBefore;
    $branchAfter = $overrides['branch_after'] ?? false;
    $beforeWorktrees = abandonedCleanupWorktrees([
        $primary,
        ...($presentBefore ? [$target] : []),
        $other,
    ]);
    $cleanupBeforeOther = [
        ...$other,
        'head' => $overrides['other_head_before_cleanup'] ?? $test->otherSha,
        'prunable' => $overrides['other_prunable_before_cleanup'] ?? false,
    ];
    $cleanupBeforeTarget = [
        ...$target,
        'prunable' => $overrides['target_prunable_before_cleanup'] ?? false,
    ];
    $cleanupBeforeWorktrees = abandonedCleanupWorktrees([
        $primary,
        ...($presentBefore ? [$cleanupBeforeTarget] : []),
        $cleanupBeforeOther,
    ]);
    $afterPruneWorktrees = abandonedCleanupWorktrees([
        $primary,
        $other,
    ]);
    $afterWorktrees = abandonedCleanupWorktrees([
        $primary,
        ...($presentAfter ? [$target] : []),
        $other,
    ]);
    $beforeBranches = abandonedCleanupBranches([
        'refs/heads/main' => $test->mainSha,
        ...($branchBefore ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        ...($overrides['extra_branches'] ?? []),
        'refs/heads/orb-999' => $test->otherSha,
    ]);
    $cleanupBeforeBranches = abandonedCleanupBranches([
        'refs/heads/main' => $test->mainSha,
        ...($branchBefore ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        ...($overrides['extra_branches'] ?? []),
        'refs/heads/orb-999' => $overrides['other_head_before_cleanup'] ?? $test->otherSha,
    ]);
    $afterPruneBranches = abandonedCleanupBranches([
        'refs/heads/main' => $test->mainSha,
        ...($branchBefore ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        'refs/heads/orb-999' => $test->otherSha,
    ]);
    $afterBranches = abandonedCleanupBranches([
        'refs/heads/main' => $test->mainSha,
        ...($branchAfter ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        'refs/heads/orb-999' => $test->otherSha,
    ]);
    $log = new class
    {
        /** @var list<array<int, string>> */
        public array $commands = [];
    };
    $inventoryCalls = 0;
    $branchCalls = 0;

    Process::fake(function ($process) use (
        $test,
        $overrides,
        $log,
        $beforeWorktrees,
        $cleanupBeforeWorktrees,
        $afterPruneWorktrees,
        $afterWorktrees,
        $beforeBranches,
        $cleanupBeforeBranches,
        $afterPruneBranches,
        $afterBranches,
        &$inventoryCalls,
        &$branchCalls,
    ) {
        $log->commands[] = $process->command;

        if ($process->command === ['git', 'fetch', '--prune', 'origin']) {
            return Process::result();
        }

        if ($process->command === [
            'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
        ]) {
            return Process::result(output: ($overrides['common_dir'] ?? $test->repository.'/.git')."\n");
        }

        if ($process->command === ['git', 'worktree', 'list', '--porcelain', '-z']) {
            $inventoryCalls++;

            if ($overrides['resume_only'] ?? false) {
                return Process::result(output: $inventoryCalls === 1
                    ? $cleanupBeforeWorktrees
                    : $afterWorktrees);
            }

            return Process::result(output: match ($inventoryCalls) {
                1 => $beforeWorktrees,
                2 => $cleanupBeforeWorktrees,
                3 => ($overrides['target_prunable_before_cleanup'] ?? false)
                    ? $afterPruneWorktrees
                    : $afterWorktrees,
                default => $afterWorktrees,
            });
        }

        if ($process->command === [
            'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
        ]) {
            $branchCalls++;

            if ($overrides['resume_only'] ?? false) {
                return Process::result(output: $branchCalls === 1
                    ? $cleanupBeforeBranches
                    : $afterBranches);
            }

            return Process::result(output: match ($branchCalls) {
                1 => $beforeBranches,
                2 => $cleanupBeforeBranches,
                3 => ($overrides['target_prunable_before_cleanup'] ?? false)
                    ? $afterPruneBranches
                    : $afterBranches,
                default => $afterBranches,
            });
        }

        if ($process->command === [
            'git', 'worktree', 'remove', $test->worktree,
        ]) {
            return Process::result();
        }

        if ($process->command === ['git', 'rev-parse', 'refs/heads/orb-234']) {
            return Process::result(output: ($overrides['branch_head'] ?? $test->candidateSha)."\n");
        }

        if ($process->command === [
            'git', 'merge-base', '--is-ancestor', 'refs/heads/orb-234', 'origin/main',
        ]) {
            return Process::result(exitCode: $overrides['ancestor_exit'] ?? 0);
        }

        if ($process->command === ['git', 'status', '--porcelain']) {
            return Process::result(output: $overrides['status'] ?? '');
        }

        if ($process->command === ['git', 'rev-parse', 'HEAD']) {
            return Process::result(output: ($overrides['worktree_head'] ?? $test->candidateSha)."\n");
        }

        if ($process->command === [$test->repository.'/bin/worktree-remove', 'ORB-234']) {
            if (! ($overrides['preserve_path'] ?? false)) {
                File::deleteDirectory($test->worktree);
            }

            if ($overrides['script_throws'] ?? false) {
                throw new RuntimeException('Cleanup adapter interrupted.');
            }

            return Process::result(
                output: $overrides['script_output'] ?? "Removed orb-234\n",
                exitCode: $overrides['script_exit'] ?? 0,
            );
        }

        throw new RuntimeException('Unexpected abandoned cleanup command: '.implode(' ', $process->command));
    })->preventStrayProcesses();

    return $log;
}

it('removes only the exact clean merged abandoned worktree through retained authorization', function () {
    $log = fakeAbandonedCleanup($this);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );
    $cleaned = $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        false,
    );

    expect($authorization->disposition)->toBe('remove')
        ->and($cleaned->disposition)->toBe('removed')
        ->and($cleaned->candidateSha)->toBe($this->candidateSha)
        ->and($log->commands)->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('records an already absent worktree without invoking the destructive adapter', function () {
    File::deleteDirectory($this->worktree);
    $log = fakeAbandonedCleanup($this, ['present_before' => false]);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );
    $cleaned = $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        false,
    );

    expect($authorization->disposition)->toBe('already_absent')
        ->and($cleaned->disposition)->toBe('already_absent')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('reconciles authorized absence after an ambiguous removal without replaying it', function () {
    File::deleteDirectory($this->worktree);
    $log = fakeAbandonedCleanup($this, ['present_before' => false]);
    $authorization = new PreparedOrbitAbandonedWorktreeCleanup(
        repository: $this->repository,
        worktree: $this->worktree,
        issueKey: 'ORB-234',
        branch: 'orb-234',
        candidateSha: $this->candidateSha,
        cleanupAttemptId: $this->cleanupAttemptId,
        disposition: 'remove',
        protectedWorktrees: [
            [
                'worktree' => $this->repository,
                'head' => $this->mainSha,
                'branch' => 'refs/heads/main',
                'prunable' => false,
            ],
            [
                'worktree' => $this->otherWorktree,
                'head' => $this->otherSha,
                'branch' => 'refs/heads/orb-999',
                'prunable' => false,
            ],
        ],
        protectedBranches: [
            'refs/heads/main' => $this->mainSha,
            'refs/heads/orb-999' => $this->otherSha,
        ],
        authorizedAt: '2026-09-12T01:00:00Z',
    );

    $cleaned = app(ProcessOrbitRepository::class)->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        true,
    );

    expect($cleaned->disposition)->toBe('already_absent')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('resumes after the cleanup adapter removes the target and is interrupted', function () {
    $log = fakeAbandonedCleanup($this, ['script_throws' => true]);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );

    expect(fn () => $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        false,
    ))->toThrow(OrbitRepositoryFailed::class, 'adapter could not run');

    $cleaned = $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        true,
    );

    expect($cleaned->disposition)->toBe('already_absent')
        ->and($log->commands)->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('resumes an authorized branch-only interruption through the cleanup adapter', function () {
    File::deleteDirectory($this->worktree);
    $log = fakeAbandonedCleanup($this, [
        'present_before' => false,
        'branch_before' => true,
        'resume_only' => true,
    ]);
    $authorization = new PreparedOrbitAbandonedWorktreeCleanup(
        repository: $this->repository,
        worktree: $this->worktree,
        issueKey: 'ORB-234',
        branch: 'orb-234',
        candidateSha: $this->candidateSha,
        cleanupAttemptId: $this->cleanupAttemptId,
        disposition: 'remove',
        protectedWorktrees: [
            [
                'worktree' => $this->repository,
                'head' => $this->mainSha,
                'branch' => 'refs/heads/main',
                'prunable' => false,
            ],
            [
                'worktree' => $this->otherWorktree,
                'head' => $this->otherSha,
                'branch' => 'refs/heads/orb-999',
                'prunable' => false,
            ],
        ],
        protectedBranches: [
            'refs/heads/main' => $this->mainSha,
            'refs/heads/orb-999' => $this->otherSha,
        ],
        authorizedAt: '2026-09-12T01:00:00Z',
    );

    $cleaned = app(ProcessOrbitRepository::class)->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        true,
    );

    expect($cleaned->disposition)->toBe('removed')
        ->and($log->commands)->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('removes only an authorized prunable target registration before resuming branch cleanup', function () {
    $log = fakeAbandonedCleanup($this, ['target_prunable_before_cleanup' => true]);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );
    File::deleteDirectory($this->worktree);

    $cleaned = $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        true,
    );

    expect($cleaned->disposition)->toBe('removed')
        ->and($log->commands)->toContain([
            'git', 'worktree', 'remove', $this->worktree,
        ])
        ->and($log->commands)->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('rejects every unrelated prunable worktree before abandoned cleanup', function () {
    $log = fakeAbandonedCleanup($this, ['other_prunable_before_cleanup' => true]);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );

    expect(fn () => $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        true,
    ))->toThrow(OrbitRepositoryFailed::class, 'unrelated prunable')
        ->and($log->commands)->not->toContain([
            'git', 'worktree', 'remove', $this->worktree,
        ])
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('rejects unrelated resource mutation after cleanup authorization', function () {
    $log = fakeAbandonedCleanup($this, [
        'other_head_before_cleanup' => str_repeat('e', 40),
    ]);
    $repository = app(ProcessOrbitRepository::class);
    $authorization = $repository->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    );

    expect(fn () => $repository->cleanupAbandonedWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
        $authorization,
        false,
    ))->toThrow(OrbitRepositoryFailed::class, 'state changed after authorization')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('rejects ambiguous issue branches before the cleanup adapter can select one', function () {
    $log = fakeAbandonedCleanup($this, [
        'extra_branches' => ['refs/heads/orb-234-recovery' => str_repeat('e', 40)],
    ]);

    expect(fn () => app(ProcessOrbitRepository::class)->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    ))->toThrow(OrbitRepositoryFailed::class, 'state is inconsistent')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});

it('refuses a dirty, changed, or unmerged abandoned candidate', function (array $overrides) {
    $log = fakeAbandonedCleanup($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    ))->toThrow(OrbitRepositoryFailed::class, 'dirty, changed, or not merged')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
})->with([
    'dirty' => [['status' => ' M changed.php']],
    'changed branch' => [['branch_head' => str_repeat('f', 40)]],
    'changed worktree' => [['worktree_head' => str_repeat('f', 40)]],
    'unmerged' => [['ancestor_exit' => 1]],
]);

it('rejects a symlinked cleanup adapter', function () {
    File::delete($this->repository.'/bin/worktree-remove');
    File::put($this->repository.'/bin/real-remove', "#!/usr/bin/env bash\n");
    chmod($this->repository.'/bin/real-remove', 0755);
    symlink($this->repository.'/bin/real-remove', $this->repository.'/bin/worktree-remove');
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(ProcessOrbitRepository::class)->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    ))->toThrow(OrbitRepositoryFailed::class, 'adapter is unavailable');
});

it('rejects a symlinked repository common directory', function () {
    File::deleteDirectory($this->repository.'/.git');
    File::makeDirectory($this->base.'/foreign-common', 0755, true);
    symlink($this->base.'/foreign-common', $this->repository.'/.git');
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(ProcessOrbitRepository::class)->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    ))->toThrow(OrbitRepositoryFailed::class, 'adapter is unavailable');
});

it('rejects a worktree from another Git common directory', function () {
    File::makeDirectory($this->base.'/foreign-common', 0755, true);
    $log = fakeAbandonedCleanup($this, ['common_dir' => $this->base.'/foreign-common']);

    expect(fn () => app(ProcessOrbitRepository::class)->prepareAbandonedWorktreeCleanup(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->cleanupAttemptId,
    ))->toThrow(OrbitRepositoryFailed::class, 'no longer belongs')
        ->and($log->commands)->not->toContain([
            $this->repository.'/bin/worktree-remove', 'ORB-234',
        ]);
});
