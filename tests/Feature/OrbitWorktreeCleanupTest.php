<?php

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\PreparedOrbitWorktreeRemoval;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-worktree-cleanup-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktree = $this->worktreeRoot.'/orb-234';
    $this->otherWorktree = $this->worktreeRoot.'/orb-999';
    $this->candidateSha = str_repeat('a', 40);
    $this->artifactSha = str_repeat('b', 40);
    $this->mainSha = str_repeat('c', 40);
    $this->otherSha = str_repeat('d', 40);
    $this->cleanupAttemptId = str_repeat('e', 32);
    $this->proofAttemptId = str_repeat('f', 32);
    $this->artifactRef = 'refs/tags/loop/orb-234/'.$this->candidateSha;

    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->repository.'/.git', 0755, true);
    File::makeDirectory($this->worktree, 0755, true);
    File::makeDirectory($this->otherWorktree, 0755, true);
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
    $this->proofCloseout = new OrbitProofCloseout(
        state: 'complete',
        issueKey: 'ORB-234',
        attemptId: $this->proofAttemptId,
        candidateSha: $this->candidateSha,
        artifactSha: $this->artifactSha,
        mergeCommitSha: str_repeat('1', 40),
        mainSha: $this->mainSha,
        generationId: 'generation-42',
        error: null,
        recordedAt: '2026-09-11T15:00:00Z',
    );
});

afterEach(fn () => File::deleteDirectory($this->base));

/**
 * @param  list<array{worktree: string, head: string, branch: ?string, prunable?: bool}>  $worktrees
 */
function orbitCleanupInventory(array $worktrees): string
{
    $records = [];

    foreach ($worktrees as $worktree) {
        $fields = [
            'worktree '.$worktree['worktree'],
            'HEAD '.$worktree['head'],
        ];

        $fields[] = $worktree['branch'] === null ? 'detached' : 'branch '.$worktree['branch'];

        if ($worktree['prunable'] ?? false) {
            $fields[] = 'prunable gitdir file points to non-existent location';
        }

        $records[] = implode("\0", $fields);
    }

    return implode("\0\0", $records)."\0\0";
}

/** @param array<string, string> $branches */
function orbitCleanupBranchInventory(array $branches): string
{
    $lines = [];

    foreach ($branches as $ref => $sha) {
        $lines[] = $sha.' '.$ref;
    }

    return implode("\n", $lines)."\n";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return object{commands: list<array<int, string>>, paths: list<string|null>, timeouts: list<float|null>}
 */
function fakeOrbitWorktreeCleanup(object $test, array $overrides = []): object
{
    $target = [
        'worktree' => $test->worktree,
        'head' => $test->candidateSha,
        'branch' => 'refs/heads/orb-234',
    ];
    $primary = [
        'worktree' => $test->repository,
        'head' => $test->mainSha,
        'branch' => 'refs/heads/main',
    ];
    $other = [
        'worktree' => $test->otherWorktree,
        'head' => $test->otherSha,
        'branch' => 'refs/heads/orb-999',
    ];
    $beforeTarget = $overrides['before_target'] ?? true;
    $afterTarget = $overrides['after_target'] ?? false;
    $before = $overrides['before_inventory'] ?? orbitCleanupInventory([
        $primary,
        ...($beforeTarget ? [$target] : []),
        $other,
    ]);
    $after = $overrides['after_inventory'] ?? orbitCleanupInventory([
        $primary,
        ...($afterTarget ? [$target] : []),
        $other,
    ]);
    $beforeBranch = $overrides['before_branch'] ?? true;
    $afterBranch = $overrides['after_branch'] ?? false;
    $unregisteredBranchSha = str_repeat('8', 40);
    $beforeBranches = $overrides['branch_output_before'] ?? orbitCleanupBranchInventory([
        'refs/heads/main' => $test->mainSha,
        ...($beforeBranch ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        'refs/heads/orb-999' => $test->otherSha,
        'refs/heads/maintenance' => $unregisteredBranchSha,
    ]);
    $afterBranches = $overrides['branch_output_after'] ?? orbitCleanupBranchInventory([
        'refs/heads/main' => $test->mainSha,
        ...($afterBranch ? ['refs/heads/orb-234' => $test->candidateSha] : []),
        'refs/heads/orb-999' => $test->otherSha,
        'refs/heads/maintenance' => $unregisteredBranchSha,
    ]);
    $log = new class
    {
        /** @var list<array<int, string>> */
        public array $commands = [];

        /** @var list<string|null> */
        public array $paths = [];

        /** @var list<float|null> */
        public array $timeouts = [];
    };
    $inventoryCalls = 0;
    $branchCalls = 0;
    $artifactCalls = 0;
    $remoteCalls = 0;

    Process::fake(function ($process) use (
        $test,
        $overrides,
        $log,
        $before,
        $after,
        $beforeBranches,
        $afterBranches,
        &$inventoryCalls,
        &$branchCalls,
        &$artifactCalls,
        &$remoteCalls,
    ) {
        $log->commands[] = $process->command;
        $log->paths[] = $process->path;
        $log->timeouts[] = $process->timeout;

        if ($process->command === ['git', 'fetch', '--prune', 'origin']) {
            return Process::result(
                errorOutput: ($overrides['fetch_exit'] ?? 0) === 0 ? '' : 'fetch failed',
                exitCode: $overrides['fetch_exit'] ?? 0,
            );
        }

        if ($process->command === ['git', 'worktree', 'list', '--porcelain', '-z']) {
            $inventoryCalls++;

            return Process::result(output: $inventoryCalls === 1 ? $before : $after);
        }

        if ($process->command === [
            'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
        ]) {
            $branchCalls++;

            return Process::result(output: $branchCalls === 1 ? $beforeBranches : $afterBranches);
        }

        if ($process->command === ['git', 'show-ref', '--verify', '--hash', $test->artifactRef]) {
            $artifactCalls++;
            $sha = $artifactCalls === 1
                ? (array_key_exists('local_artifact_before', $overrides)
                    ? $overrides['local_artifact_before'] : $test->artifactSha)
                : (array_key_exists('local_artifact_after', $overrides)
                    ? $overrides['local_artifact_after'] : $test->artifactSha);

            return Process::result(output: $sha === null ? '' : $sha."\n", exitCode: $sha === null ? 1 : 0);
        }

        if ($process->command === ['git', 'ls-remote', '--refs', 'origin', $test->artifactRef]) {
            $remoteCalls++;
            $sha = $remoteCalls === 1
                ? (array_key_exists('remote_artifact_before', $overrides)
                    ? $overrides['remote_artifact_before'] : $test->artifactSha)
                : (array_key_exists('remote_artifact_after', $overrides)
                    ? $overrides['remote_artifact_after'] : $test->artifactSha);

            return Process::result(
                output: $sha === null ? '' : $sha."\t".$test->artifactRef."\n",
                exitCode: $sha === null ? 2 : 0,
            );
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
            if ($overrides['script_exception'] ?? false) {
                throw new RuntimeException('process timed out');
            }

            if (! ($overrides['preserve_path'] ?? false)) {
                File::deleteDirectory($test->worktree);
            }

            if ($overrides['mutate_proof_archive'] ?? false) {
                File::put(
                    $test->repository.'/.e2e/proof-review/ORB-234/'.$test->proofAttemptId.'.json',
                    '{"review":"changed"}',
                );
            }

            return Process::result(
                output: $overrides['script_output'] ?? "Removed orb-234\n",
                errorOutput: $overrides['script_error'] ?? '',
                exitCode: $overrides['script_exit'] ?? 0,
            );
        }

        throw new RuntimeException('Unexpected cleanup command: '.implode(' ', $process->command));
    })->preventStrayProcesses();

    return $log;
}

function writeOrbitProofArchives(object $test): void
{
    $records = [
        '.e2e/proof-evidence/ORB-234/'.$test->proofAttemptId.'.json' => ['capture' => true],
        '.e2e/proof-review/ORB-234/'.$test->proofAttemptId.'.json' => ['review' => true],
        '.e2e/proof-review-evaluation/ORB-234/'.$test->proofAttemptId.'.json' => ['approved' => true],
        '.e2e/proof-closeout/ORB-234/'.$test->proofAttemptId.'.json' => $test->proofCloseout->toArray(),
    ];

    foreach ($records as $relative => $record) {
        File::ensureDirectoryExists(dirname($test->repository.'/'.$relative));
        File::put($test->repository.'/'.$relative, json_encode($record, JSON_THROW_ON_ERROR));
    }
}

function orbitCleanupAuthorization(
    object $test,
    ?OrbitProofCloseout $proofCloseout = null,
): PreparedOrbitWorktreeRemoval {
    $archives = [];

    if ($proofCloseout !== null) {
        foreach ([
            ".e2e/proof-evidence/ORB-234/{$test->proofAttemptId}.json",
            ".e2e/proof-review/ORB-234/{$test->proofAttemptId}.json",
            ".e2e/proof-review-evaluation/ORB-234/{$test->proofAttemptId}.json",
            ".e2e/proof-closeout/ORB-234/{$test->proofAttemptId}.json",
        ] as $relativePath) {
            $archives[$relativePath] = hash_file('sha256', $test->repository.'/'.$relativePath);
        }
    }

    return new PreparedOrbitWorktreeRemoval(
        repository: $test->repository,
        worktree: $test->worktree,
        issueKey: 'ORB-234',
        branch: 'orb-234',
        candidateSha: $test->candidateSha,
        artifactRef: $test->artifactRef,
        artifactSha: $test->artifactSha,
        cleanupAttemptId: $test->cleanupAttemptId,
        proofAttemptId: $proofCloseout?->attemptId,
        protectedWorktrees: [
            [
                'worktree' => $test->repository,
                'head' => $test->mainSha,
                'branch' => 'refs/heads/main',
                'prunable' => false,
            ],
            [
                'worktree' => $test->otherWorktree,
                'head' => $test->otherSha,
                'branch' => 'refs/heads/orb-999',
                'prunable' => false,
            ],
        ],
        protectedBranches: [
            'refs/heads/main' => $test->mainSha,
            'refs/heads/orb-999' => $test->otherSha,
            'refs/heads/maintenance' => str_repeat('8', 40),
        ],
        evidenceArchives: $archives,
        authorizedAt: '2026-09-11T16:00:00Z',
    );
}

it('removes the exact merged Orbit worktree and returns durable discovery evidence', function () {
    $log = fakeOrbitWorktreeCleanup($this);

    $removed = app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->artifactSha,
        null,
        $this->cleanupAttemptId,
        orbitCleanupAuthorization($this),
        false,
    );

    expect($removed->issueKey)->toBe('ORB-234')
        ->and($removed->worktree)->toBe($this->worktree)
        ->and($removed->cleanupAttemptId)->toBe($this->cleanupAttemptId)
        ->and($removed->proofAttemptId)->toBeNull()
        ->and($removed->evidenceArchives)->toBe([])
        ->and($removed->artifactRef)->toBe($this->artifactRef)
        ->and($removed->artifactSha)->toBe($this->artifactSha)
        ->and($log->commands)->toContain([$this->repository.'/bin/worktree-remove', 'ORB-234']);

    $script = array_search([$this->repository.'/bin/worktree-remove', 'ORB-234'], $log->commands, true);
    expect($script)->toBeInt()
        ->and($log->paths[$script])->toBe($this->repository)
        ->and($log->timeouts[$script])->toBe(300);
});

it('preflights the exact cleanup target without running the destructive adapter', function () {
    fakeOrbitWorktreeCleanup($this);

    $authorization = app(ProcessOrbitRepository::class)->prepareWorktreeRemoval(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->artifactSha,
        null,
        $this->cleanupAttemptId,
    );

    expect($authorization->cleanupAttemptId)->toBe($this->cleanupAttemptId)
        ->and($authorization->protectedWorktrees)->toHaveCount(2)
        ->and($authorization->protectedBranches)->toHaveCount(3);
    Process::assertNotRan(fn ($process): bool => $process->command === [
        $this->repository.'/bin/worktree-remove', 'ORB-234',
    ]);
});

it('binds proof cleanup to the exact retained primary archives', function () {
    writeOrbitProofArchives($this);
    fakeOrbitWorktreeCleanup($this);

    $removed = app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config,
        'ORB-234',
        $this->worktree,
        'orb-234',
        $this->candidateSha,
        $this->artifactSha,
        $this->proofCloseout,
        $this->cleanupAttemptId,
        orbitCleanupAuthorization($this, $this->proofCloseout),
        false,
    );

    expect($removed->proofAttemptId)->toBe($this->proofAttemptId)
        ->and($removed->evidenceArchives)->toHaveCount(4)
        ->and(array_keys($removed->evidenceArchives))->toBe([
            '.e2e/proof-evidence/ORB-234/'.$this->proofAttemptId.'.json',
            '.e2e/proof-review/ORB-234/'.$this->proofAttemptId.'.json',
            '.e2e/proof-review-evaluation/ORB-234/'.$this->proofAttemptId.'.json',
            '.e2e/proof-closeout/ORB-234/'.$this->proofAttemptId.'.json',
        ]);
});

it('requires the exact target on the first authorized cleanup attempt', function () {
    $retryLog = fakeOrbitWorktreeCleanup($this, ['before_target' => false, 'before_branch' => false]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'state is inconsistent');

    expect($retryLog->commands)->not->toContain([
        $this->repository.'/bin/worktree-remove', 'ORB-234',
    ]);
});

it('resumes cleanup after the worktree was removed but its branch remains', function () {
    File::deleteDirectory($this->worktree);
    fakeOrbitWorktreeCleanup($this, ['before_target' => false, 'before_branch' => true]);

    $removed = app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), true,
    );

    expect($removed->branch)->toBe('orb-234');
});

it('reconciles an already absent target only for an authorized resume', function () {
    File::deleteDirectory($this->worktree);
    fakeOrbitWorktreeCleanup(
        $this,
        ['before_target' => false, 'before_branch' => false],
    );

    $removed = app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), true,
    );

    expect($removed->cleanupAttemptId)->toBe($this->cleanupAttemptId);
});

it('rejects a target path and branch registered to different worktrees', function () {
    fakeOrbitWorktreeCleanup($this, [
        'before_inventory' => orbitCleanupInventory([
            ['worktree' => $this->repository, 'head' => $this->mainSha, 'branch' => 'refs/heads/main'],
            ['worktree' => $this->worktree, 'head' => $this->candidateSha, 'branch' => 'refs/heads/wrong'],
            ['worktree' => $this->otherWorktree, 'head' => $this->candidateSha, 'branch' => 'refs/heads/orb-234'],
        ]),
    ]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'state is inconsistent');
});

it('rejects ambiguous issue branches before the legacy cleanup script selects one', function () {
    fakeOrbitWorktreeCleanup($this, [
        'branch_output_before' => orbitCleanupBranchInventory([
            'refs/heads/main' => $this->mainSha,
            'refs/heads/orb-234' => $this->candidateSha,
            'refs/heads/orb-234-proof' => $this->candidateSha,
            'refs/heads/orb-999' => $this->otherSha,
            'refs/heads/maintenance' => str_repeat('8', 40),
        ]),
    ]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'state is inconsistent');
});

it('refuses dirty, changed, or unmerged cleanup candidates', function (array $overrides) {
    fakeOrbitWorktreeCleanup($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'dirty, changed, or not merged');
})->with([
    'dirty worktree' => [['status' => " M app/Test.php\n"]],
    'moved branch' => [['branch_head' => str_repeat('9', 40)]],
    'moved worktree head' => [['worktree_head' => str_repeat('9', 40)]],
    'unmerged branch' => [['ancestor_exit' => 1]],
]);

it('requires the immutable local and remote artifact through cleanup', function (array $overrides) {
    fakeOrbitWorktreeCleanup($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'state is inconsistent');
})->with([
    'missing local before' => [['local_artifact_before' => null]],
    'changed local after' => [['local_artifact_after' => str_repeat('9', 40)]],
    'missing remote before' => [['remote_artifact_before' => null]],
    'changed remote after' => [['remote_artifact_after' => str_repeat('9', 40)]],
]);

it('rejects missing, redirected, or mismatched proof archives', function (string $change) {
    writeOrbitProofArchives($this);
    $authorization = orbitCleanupAuthorization($this, $this->proofCloseout);

    if ($change === 'missing') {
        File::delete($this->repository.'/.e2e/proof-review/ORB-234/'.$this->proofAttemptId.'.json');
    } elseif ($change === 'redirected') {
        $path = $this->repository.'/.e2e/proof-review/ORB-234/'.$this->proofAttemptId.'.json';
        File::delete($path);
        symlink('/dev/null', $path);
    } elseif ($change === 'redirected parent') {
        $directory = $this->repository.'/.e2e/proof-review/ORB-234';
        $outside = $this->base.'/outside-review';
        File::makeDirectory($outside, 0755, true);
        File::put($outside.'/'.$this->proofAttemptId.'.json', '{"review":true}');
        File::deleteDirectory($directory);
        symlink($outside, $directory);
    } else {
        File::put(
            $this->repository.'/.e2e/proof-closeout/ORB-234/'.$this->proofAttemptId.'.json',
            json_encode([...$this->proofCloseout->toArray(), 'generation_id' => 'other'], JSON_THROW_ON_ERROR),
        );
    }

    fakeOrbitWorktreeCleanup($this);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, $this->proofCloseout,
        $this->cleanupAttemptId, $authorization, false,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with(['missing', 'redirected', 'redirected parent', 'mismatched closeout']);

it('rejects proof archives changed by the cleanup adapter', function () {
    writeOrbitProofArchives($this);
    $authorization = orbitCleanupAuthorization($this, $this->proofCloseout);
    fakeOrbitWorktreeCleanup($this, ['mutate_proof_archive' => true]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, $this->proofCloseout,
        $this->cleanupAttemptId, $authorization, false,
    ))->toThrow(OrbitRepositoryFailed::class, 'proof archives changed');

    $retryLog = fakeOrbitWorktreeCleanup(
        $this,
        ['before_target' => false, 'before_branch' => false],
    );

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, $this->proofCloseout,
        $this->cleanupAttemptId, $authorization, true,
    ))->toThrow(OrbitRepositoryFailed::class, 'state changed after authorization');
    expect($retryLog->commands)->not->toContain([
        $this->repository.'/bin/worktree-remove', 'ORB-234',
    ]);
});

it('rejects failed, malformed, and interrupted cleanup commands', function (array $overrides) {
    fakeOrbitWorktreeCleanup($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with([
    'refresh failed' => [['fetch_exit' => 1]],
    'failed' => [['script_exit' => 1, 'script_error' => 'branch is not merged']],
    'malformed output' => [['script_output' => "Done\n"]],
    'interrupted' => [['script_exception' => true]],
]);

it('rejects failed cleanup postconditions and unrelated worktree changes', function (string $change) {
    $overrides = match ($change) {
        'worktree remains' => ['after_target' => true, 'after_branch' => true, 'preserve_path' => true],
        'branch remains' => ['after_branch' => true],
        'unrelated changed' => ['after_inventory' => orbitCleanupInventory([
            ['worktree' => $this->repository, 'head' => $this->mainSha, 'branch' => 'refs/heads/main'],
            ['worktree' => $this->otherWorktree, 'head' => str_repeat('9', 40), 'branch' => 'refs/heads/orb-999'],
        ])],
        'unrelated branch moved' => ['branch_output_after' => orbitCleanupBranchInventory([
            'refs/heads/main' => $this->mainSha,
            'refs/heads/orb-999' => $this->otherSha,
            'refs/heads/maintenance' => str_repeat('9', 40),
        ])],
        'unrelated prunable' => ['before_inventory' => orbitCleanupInventory([
            ['worktree' => $this->repository, 'head' => $this->mainSha, 'branch' => 'refs/heads/main'],
            ['worktree' => $this->worktree, 'head' => $this->candidateSha, 'branch' => 'refs/heads/orb-234'],
            [
                'worktree' => $this->otherWorktree,
                'head' => $this->otherSha,
                'branch' => 'refs/heads/orb-999',
                'prunable' => true,
            ],
        ])],
    };
    fakeOrbitWorktreeCleanup($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with([
    'worktree remains',
    'branch remains',
    'unrelated changed',
    'unrelated branch moved',
    'unrelated prunable',
]);

it('keeps cleanup blocked across retries after an unrelated branch changes', function () {
    $authorization = orbitCleanupAuthorization($this);
    $changedBranches = orbitCleanupBranchInventory([
        'refs/heads/main' => $this->mainSha,
        'refs/heads/orb-999' => $this->otherSha,
        'refs/heads/maintenance' => str_repeat('9', 40),
    ]);
    fakeOrbitWorktreeCleanup($this, ['branch_output_after' => $changedBranches]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        $authorization, false,
    ))->toThrow(OrbitRepositoryFailed::class, 'unrelated Orbit branch changed');

    $retryLog = fakeOrbitWorktreeCleanup($this, [
        'before_target' => false,
        'before_branch' => false,
        'branch_output_before' => $changedBranches,
        'branch_output_after' => $changedBranches,
    ]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        $authorization, true,
    ))->toThrow(OrbitRepositoryFailed::class, 'state changed after authorization');
    expect($retryLog->commands)->not->toContain([
        $this->repository.'/bin/worktree-remove', 'ORB-234',
    ]);
});

it('keeps cleanup blocked across retries after an unrelated worktree changes', function () {
    $authorization = orbitCleanupAuthorization($this);
    $changedWorktrees = orbitCleanupInventory([
        ['worktree' => $this->repository, 'head' => $this->mainSha, 'branch' => 'refs/heads/main'],
        ['worktree' => $this->otherWorktree, 'head' => str_repeat('9', 40), 'branch' => 'refs/heads/orb-999'],
    ]);
    fakeOrbitWorktreeCleanup($this, ['after_inventory' => $changedWorktrees]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        $authorization, false,
    ))->toThrow(OrbitRepositoryFailed::class, 'unrelated Orbit worktree changed');

    $retryLog = fakeOrbitWorktreeCleanup($this, [
        'before_target' => false,
        'before_branch' => false,
        'before_inventory' => $changedWorktrees,
        'after_inventory' => $changedWorktrees,
    ]);

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        $authorization, true,
    ))->toThrow(OrbitRepositoryFailed::class, 'state changed after authorization');
    expect($retryLog->commands)->not->toContain([
        $this->repository.'/bin/worktree-remove', 'ORB-234',
    ]);
});

it('rejects an unavailable or redirected worktree cleanup adapter', function (string $change) {
    if ($change === 'redirected') {
        File::delete($this->repository.'/bin/worktree-remove');
        symlink('/bin/true', $this->repository.'/bin/worktree-remove');
    } else {
        chmod($this->repository.'/bin/worktree-remove', 0644);
    }

    Process::fake()->preventStrayProcesses();

    expect(fn () => app(ProcessOrbitRepository::class)->removeWorktree(
        $this->config, 'ORB-234', $this->worktree, 'orb-234',
        $this->candidateSha, $this->artifactSha, null, $this->cleanupAttemptId,
        orbitCleanupAuthorization($this), false,
    ))->toThrow(OrbitRepositoryFailed::class, 'adapter is unavailable');
    Process::assertRanTimes(fn () => true, 0);
})->with(['not executable', 'redirected']);
