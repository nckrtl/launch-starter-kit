<?php

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-post-merge-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    $this->common = $this->repository.'/.git';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktree = $this->worktreeRoot.'/orb-234';
    $this->candidateSha = str_repeat('a', 40);
    $this->mergeSha = str_repeat('b', 40);
    $this->treeSha = str_repeat('c', 40);
    $this->mainSha = str_repeat('d', 40);

    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->common, 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    File::put($this->repository.'/bin/loop-flow', "#!/usr/bin/env python3\n");
    chmod($this->repository.'/bin/loop-flow', 0755);

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
 * @param  array<string, string|int>  $overrides
 * @return object{commands: list<array<int, string>>}
 */
function fakeMergeLineage(object $test, array $overrides = []): object
{
    $results = [
        'common' => $test->common."\n",
        'fetch_exit' => 0,
        'published_exit' => 0,
        'verification_exit' => 0,
        'verification' => json_encode([
            'flow' => 'discovery',
            'candidate' => $test->candidateSha,
            'merge' => $test->mergeSha,
            'tree' => $test->treeSha,
        ], JSON_THROW_ON_ERROR)."\n",
        ...$overrides,
    ];
    $log = new class
    {
        /** @var list<array<int, string>> */
        public array $commands = [];
    };

    Process::fake(function ($process) use ($test, $results, $log) {
        $log->commands[] = $process->command;
        $expectedPath = $process->command === [
            'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
        ] ? $test->worktree : $test->repository;
        expect($process->path)->toBe($expectedPath);

        return match ($process->command) {
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'] => Process::result(
                output: (string) $results['common'],
            ),
            ['git', 'fetch', 'origin', 'main'] => Process::result(
                errorOutput: (int) $results['fetch_exit'] === 0 ? '' : 'fetch failed',
                exitCode: (int) $results['fetch_exit'],
            ),
            ['git', 'merge-base', '--is-ancestor', $test->mergeSha, 'origin/main'] => Process::result(
                errorOutput: (int) $results['published_exit'] === 0 ? '' : 'merge missing',
                exitCode: (int) $results['published_exit'],
            ),
            [
                $test->repository.'/bin/loop-flow',
                'verify-merge',
                '--worktree='.$test->worktree,
                '--candidate='.$test->candidateSha,
                '--merge='.$test->mergeSha,
            ] => Process::result(
                output: (string) $results['verification'],
                errorOutput: (int) $results['verification_exit'] === 0 ? '' : 'verification failed',
                exitCode: (int) $results['verification_exit'],
            ),
            default => throw new RuntimeException('Unexpected merge lineage command.'),
        };
    })->preventStrayProcesses();

    return $log;
}

/**
 * @param  array<string, string|int>  $overrides
 * @return object{commands: list<array<int, string>>}
 */
function fakePrimaryReconciliation(object $test, array $overrides = []): object
{
    $results = [
        'status' => '',
        'status_exit' => 0,
        'branch' => "main\n",
        'branch_exit' => 0,
        'ancestor_exit' => 0,
        'merge_exit' => 0,
        'head' => $test->mainSha."\n",
        'head_exit' => 0,
        'main' => $test->mainSha."\n",
        'main_exit' => 0,
        'origin' => $test->mainSha."\n",
        'origin_exit' => 0,
        ...$overrides,
    ];
    $log = new class
    {
        /** @var list<array<int, string>> */
        public array $commands = [];
    };

    Process::fake(function ($process) use ($test, $results, $log) {
        $log->commands[] = $process->command;
        expect($process->path)->toBe($test->repository);

        return match ($process->command) {
            ['git', 'status', '--porcelain'] => Process::result(
                output: (string) $results['status'],
                exitCode: (int) $results['status_exit'],
            ),
            ['git', 'branch', '--show-current'] => Process::result(
                output: (string) $results['branch'],
                exitCode: (int) $results['branch_exit'],
            ),
            ['git', 'merge-base', '--is-ancestor', $test->mergeSha, 'origin/main'] => Process::result(
                exitCode: (int) $results['ancestor_exit'],
            ),
            ['git', 'merge', '--ff-only', 'origin/main'] => Process::result(
                errorOutput: (int) $results['merge_exit'] === 0 ? '' : 'not fast-forwardable',
                exitCode: (int) $results['merge_exit'],
            ),
            ['git', 'rev-parse', 'HEAD'] => Process::result(
                output: (string) $results['head'],
                exitCode: (int) $results['head_exit'],
            ),
            ['git', 'rev-parse', 'main'] => Process::result(
                output: (string) $results['main'],
                exitCode: (int) $results['main_exit'],
            ),
            ['git', 'rev-parse', 'origin/main'] => Process::result(
                output: (string) $results['origin'],
                exitCode: (int) $results['origin_exit'],
            ),
            default => throw new RuntimeException('Unexpected primary reconciliation command.'),
        };
    })->preventStrayProcesses();

    return $log;
}

it('fetches and verifies the exact published Orbit merge lineage', function () {
    $log = fakeMergeLineage($this);

    $verified = app(ProcessOrbitRepository::class)->verifyMergeLineage(
        $this->config,
        $this->worktree,
        $this->candidateSha,
        $this->mergeSha,
    );

    expect($verified->flow)->toBe('discovery')
        ->and($verified->candidateSha)->toBe($this->candidateSha)
        ->and($verified->mergeCommitSha)->toBe($this->mergeSha)
        ->and($verified->treeSha)->toBe($this->treeSha)
        ->and($log->commands)->toBe([
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'],
            ['git', 'fetch', 'origin', 'main'],
            ['git', 'merge-base', '--is-ancestor', $this->mergeSha, 'origin/main'],
            [
                $this->repository.'/bin/loop-flow',
                'verify-merge',
                '--worktree='.$this->worktree,
                '--candidate='.$this->candidateSha,
                '--merge='.$this->mergeSha,
            ],
        ]);
});

it('rejects an unavailable origin main or an unpublished merge', function (array $overrides, string $message) {
    $log = fakeMergeLineage($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyMergeLineage(
        $this->config,
        $this->worktree,
        $this->candidateSha,
        $this->mergeSha,
    ))->toThrow(OrbitRepositoryFailed::class, $message);

    $expectedCalls = array_key_exists('fetch_exit', $overrides) ? 2 : 3;
    expect($log->commands)->toHaveCount($expectedCalls);
})->with([
    'fetch failed' => [['fetch_exit' => 1], 'fetch failed'],
    'merge missing' => [['published_exit' => 1], 'merge missing'],
]);

it('rejects a merge worktree from another Git common directory', function () {
    $other = $this->base.'/other.git';
    File::makeDirectory($other);
    fakeMergeLineage($this, ['common' => $other."\n"]);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyMergeLineage(
        $this->config,
        $this->worktree,
        $this->candidateSha,
        $this->mergeSha,
    ))->toThrow(OrbitRepositoryFailed::class, 'no longer belongs');
    Process::assertRanTimes(fn () => true, 1);
});

it('strictly validates merge lineage evidence', function (string $output) {
    fakeMergeLineage($this, ['verification' => $output]);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyMergeLineage(
        $this->config,
        $this->worktree,
        $this->candidateSha,
        $this->mergeSha,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with([
    'invalid JSON' => ['{'],
    'wrong candidate' => [json_encode([
        'flow' => 'discovery',
        'candidate' => str_repeat('e', 40),
        'merge' => str_repeat('b', 40),
        'tree' => str_repeat('c', 40),
    ], JSON_THROW_ON_ERROR)],
    'extra evidence' => [json_encode([
        'flow' => 'discovery',
        'candidate' => str_repeat('a', 40),
        'merge' => str_repeat('b', 40),
        'tree' => str_repeat('c', 40),
        'extra' => true,
    ], JSON_THROW_ON_ERROR)],
]);

it('locks and fast-forwards the clean Orbit primary checkout to origin main', function () {
    $log = fakePrimaryReconciliation($this);

    $reconciled = app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
        $this->config,
        $this->mergeSha,
    );

    expect($reconciled->repository)->toBe($this->repository)
        ->and($reconciled->mergeCommitSha)->toBe($this->mergeSha)
        ->and($reconciled->mainSha)->toBe($this->mainSha)
        ->and($reconciled->originMainSha)->toBe($this->mainSha)
        ->and($log->commands)->toBe([
            ['git', 'status', '--porcelain'],
            ['git', 'branch', '--show-current'],
            ['git', 'merge-base', '--is-ancestor', $this->mergeSha, 'origin/main'],
            ['git', 'merge', '--ff-only', 'origin/main'],
            ['git', 'rev-parse', 'HEAD'],
            ['git', 'rev-parse', 'main'],
            ['git', 'rev-parse', 'origin/main'],
        ]);
    expect($this->common.'/orbit-delivery/v1/checkout.lock')->toBeFile();
});

it('refuses a dirty or wrong-branch Orbit primary checkout', function (array $overrides, string $message) {
    fakePrimaryReconciliation($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
        $this->config,
        $this->mergeSha,
    ))->toThrow(OrbitRepositoryFailed::class, $message);
    Process::assertNotRan(fn ($process): bool => $process->command === [
        'git', 'merge', '--ff-only', 'origin/main',
    ]);

    fakePrimaryReconciliation($this);
    expect(app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
        $this->config,
        $this->mergeSha,
    )->mainSha)->toBe($this->mainSha);
})->with([
    'dirty' => [['status' => " M app/Test.php\n"], 'checkout is dirty'],
    'wrong branch' => [['branch' => "orb-234\n"], 'not on main'],
]);

it('refuses a contended primary checkout lock before running Git', function () {
    $directory = $this->common.'/orbit-delivery/v1';
    File::makeDirectory($directory, 0700, true);
    $lock = fopen($directory.'/checkout.lock', 'c+');

    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Could not hold the checkout lock for the test.');
    }

    Process::fake()->preventStrayProcesses();

    try {
        expect(fn () => app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
            $this->config,
            $this->mergeSha,
        ))->toThrow(OrbitRepositoryFailed::class, 'currently owns the primary checkout');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    Process::assertRanTimes(fn () => true, 0);
});

it('rejects failed fast-forwards and inconsistent primary read-back', function (array $overrides) {
    fakePrimaryReconciliation($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
        $this->config,
        $this->mergeSha,
    ))->toThrow(OrbitRepositoryFailed::class, 'did not reconcile');

    fakePrimaryReconciliation($this);
    expect(app(ProcessOrbitRepository::class)->reconcilePrimaryCheckout(
        $this->config,
        $this->mergeSha,
    )->mainSha)->toBe($this->mainSha);
})->with([
    'fast-forward failed' => [['merge_exit' => 1]],
    'head mismatch' => [['head' => str_repeat('e', 40)."\n"]],
    'remote mismatch' => [['origin' => str_repeat('e', 40)."\n"]],
]);
