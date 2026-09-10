<?php

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-planning-repository-'.bin2hex(random_bytes(4)));
    $this->repositoryPath = $this->base.'/repository';
    $this->commonDirectory = $this->repositoryPath.'/.git';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->headSha = str_repeat('a', 40);
    $this->treeSha = str_repeat('b', 40);
    $this->receiptPath = $this->commonDirectory.'/orbit-checks/'.$this->headSha.'/startup/result.json';
    $this->snapshotPath = $this->worktreePath.'/.loop/issue.json';
    $this->issueId = '11111111-2222-4333-8444-555555555555';

    File::makeDirectory($this->repositoryPath.'/bin', 0755, true);
    File::makeDirectory(dirname($this->receiptPath), 0755, true);
    File::makeDirectory($this->worktreePath.'/.loop', 0755, true);
    File::put($this->repositoryPath.'/bin/loop-flow', "#!/usr/bin/env python3\n");
    chmod($this->repositoryPath.'/bin/loop-flow', 0755);
    File::put($this->repositoryPath.'/bin/plan-lint', "#!/usr/bin/env bash\n");
    chmod($this->repositoryPath.'/bin/plan-lint', 0755);
    File::put($this->receiptPath, json_encode(
        planningRepositoryReceipt($this->worktreePath, $this->headSha, $this->treeSha),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));
    $snapshotContents = json_encode([
        'id' => $this->issueId,
        'identifier' => 'ORB-234',
        'title' => 'Plan this issue',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    File::put($this->snapshotPath, $snapshotContents);
    chmod($this->snapshotPath, 0600);

    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: $this->repositoryPath,
        worktreeRoot: $this->worktreeRoot,
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
    $this->worktree = new PreparedWorktree(realpath($this->worktreePath), $this->headSha);
    $this->candidate = new CandidateCheck(realpath($this->receiptPath), $this->headSha, $this->treeSha);
    $this->snapshot = new PreparedIssueSnapshot(
        schema: OrbitIssueSnapshot::SCHEMA,
        provider: OrbitIssueSnapshot::PROVIDER,
        path: realpath($this->snapshotPath),
        contentsHash: hash('sha256', $snapshotContents),
        contractSchema: OrbitIssueSnapshot::CONTRACT_SCHEMA,
        contractHash: str_repeat('c', 64),
        issueId: $this->issueId,
        issueKey: 'ORB-234',
    );
});

afterEach(fn () => File::deleteDirectory($this->base));

/** @return array<string, mixed> */
function planningRepositoryReceipt(string $worktree, string $headSha, string $treeSha): array
{
    $checks = [];

    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        foreach ([
            ['composer', 'validate', '--strict'],
            ['composer', 'check'],
            ['composer', 'test:affected'],
        ] as $command) {
            $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
        }
    }

    return [
        'schema' => 1,
        'role' => 'builder',
        'candidate' => $headSha,
        'tree' => $treeSha,
        'worktree' => $worktree,
        'checks' => $checks,
        'passed' => true,
        'unchanged' => true,
    ];
}

/** @param array<string, string> $overrides */
function fakePlanningRepositoryInspection(object $test, array $overrides = []): void
{
    $outputs = [
        'inventory' => "worktree {$test->repositoryPath}\nHEAD ".str_repeat('d', 40)."\nbranch refs/heads/main\n\n"
            ."worktree {$test->worktreePath}\nHEAD {$test->headSha}\nbranch refs/heads/orb-234\n",
        'status' => '',
        'conflicts' => '',
        'head' => $test->headSha."\n",
        'tree' => $test->treeSha."\n",
        'common' => $test->commonDirectory."\n",
        'flow' => "discovery\n",
        ...$overrides,
    ];

    Process::fake(function ($process) use ($test, $outputs) {
        $command = $process->command;
        $output = match ($command) {
            ['git', 'worktree', 'list', '--porcelain'] => $outputs['inventory'],
            ['git', 'status', '--porcelain'] => $outputs['status'],
            ['git', 'diff', '--name-only', '--diff-filter=U'] => $outputs['conflicts'],
            ['git', 'rev-parse', 'HEAD'] => $outputs['head'],
            ['git', 'rev-parse', 'HEAD^{tree}'] => $outputs['tree'],
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'] => $outputs['common'],
            [realpath($test->repositoryPath.'/bin/loop-flow'), 'status', '--worktree='.$test->worktreePath] => $outputs['flow'],
            default => throw new RuntimeException('Unexpected planning repository command.'),
        };

        return Process::result(output: $output);
    })->preventStrayProcesses();
}

it('verifies the exact retained Orbit planning repository state without rerunning checks', function () {
    fakePlanningRepositoryInspection($this);

    $verified = app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    );

    expect($verified->worktreePath)->toBe($this->worktreePath)
        ->and($verified->branch)->toBe('orb-234')
        ->and($verified->candidateSha)->toBe($this->headSha)
        ->and($verified->treeSha)->toBe($this->treeSha)
        ->and($verified->flow)->toBe('discovery')
        ->and($verified->qualityReceiptPath)->toBe($this->receiptPath)
        ->and($verified->snapshotPath)->toBe($this->snapshotPath)
        ->and($verified->snapshotContentsHash)->toBe($this->snapshot->contentsHash);

    Process::assertRanTimes(fn () => true, 7);
    Process::assertNotRan(fn ($process): bool => $process->command === ['composer', 'check']);
});

it('rejects changed Orbit planning repository facts', function (array $overrides, string $message) {
    fakePlanningRepositoryInspection($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, $message);
})->with([
    'registered branch' => [
        ['inventory' => "worktree /different\nHEAD ".str_repeat('a', 40)."\nbranch refs/heads/orb-234\n"],
        'registered Orbit issue worktree changed',
    ],
    'dirty worktree' => [['status' => " M app/Test.php\n"], 'worktree is dirty'],
    'unresolved conflicts' => [['conflicts' => "app/Test.php\n"], 'unresolved conflicts'],
    'head' => [['head' => str_repeat('d', 40)."\n"], 'recorded Git state'],
    'tree' => [['tree' => str_repeat('d', 40)."\n"], 'recorded Git state'],
    'flow' => [['flow' => "proof\n"], 'requires the discovery flow'],
]);

it('rejects a worktree from another Git common directory', function () {
    $other = $this->base.'/other.git';
    File::makeDirectory($other);
    fakePlanningRepositoryInspection($this, ['common' => $other."\n"]);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'no longer belongs to the configured repository');
});

it('rejects changed startup receipt contents', function () {
    File::put($this->receiptPath, json_encode([
        ...planningRepositoryReceipt($this->worktreePath, $this->headSha, $this->treeSha),
        'unchanged' => false,
    ], JSON_THROW_ON_ERROR));
    fakePlanningRepositoryInspection($this);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'invalid receipt');
});

it('rejects a changed startup receipt path', function () {
    $outside = $this->commonDirectory.'/outside/result.json';
    File::makeDirectory(dirname($outside), 0755, true);
    File::put($outside, File::get($this->receiptPath));
    $candidate = new CandidateCheck($outside, $this->headSha, $this->treeSha);
    fakePlanningRepositoryInspection($this);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'invalid receipt path');
});

it('rejects a startup receipt redirected through a parent symlink', function () {
    $runDirectory = dirname($this->receiptPath);
    $alternateDirectory = dirname($runDirectory).'/alternate';
    File::move($runDirectory, $alternateDirectory);
    symlink($alternateDirectory, $runDirectory);
    fakePlanningRepositoryInspection($this);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'invalid receipt path');
});

it('rejects changed retained issue snapshot state', function (string $case) {
    if ($case === 'bytes') {
        File::append($this->snapshotPath, "\n");
    } elseif ($case === 'mode') {
        chmod($this->snapshotPath, 0644);
    } else {
        $outside = $this->base.'/outside-issue.json';
        File::move($this->snapshotPath, $outside);
        symlink($outside, $this->snapshotPath);
    }

    fakePlanningRepositoryInspection($this);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningHandoff(
        $this->config,
        $this->worktree,
        $this->candidate,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with(['bytes', 'mode', 'symlink']);

it('verifies a saved planning artifact and its pending verdict through Orbit plan-lint', function () {
    $artifactSha = str_repeat('d', 40);
    $plan = "Plan format: 1\nIssue: ORB-234\nFlow: discovery\nReview verdict: PENDING\n";
    $validator = realpath($this->repositoryPath.'/bin/plan-lint');

    Process::fake(function ($process) use ($artifactSha, $plan, $validator) {
        return match ($process->command) {
            [$validator, 'verify', 'ORB-234', '--worktree='.$this->worktreePath, '--artifact='.$artifactSha] => Process::result(output: "passed\n"),
            ['git', 'show', $artifactSha.':.loop/plan.md'] => Process::result(output: $plan),
            default => throw new RuntimeException('Unexpected planning artifact command.'),
        };
    })->preventStrayProcesses();

    $verified = app(ProcessOrbitRepository::class)->verifyPlanningArtifact(
        $this->config,
        $this->worktree,
        'ORB-234',
        $artifactSha,
    );

    expect($verified->artifactSha)->toBe($artifactSha)
        ->and($verified->planContentsHash)->toBe(hash('sha256', $plan));
    Process::assertRanTimes(fn () => true, 2);
});

it('rejects a failed Orbit plan-lint verification', function () {
    $artifactSha = str_repeat('d', 40);
    $validator = realpath($this->repositoryPath.'/bin/plan-lint');

    Process::fake(function ($process) use ($artifactSha, $validator) {
        if ($process->command === [
            $validator,
            'verify',
            'ORB-234',
            '--worktree='.$this->worktreePath,
            '--artifact='.$artifactSha,
        ]) {
            return Process::result(errorOutput: 'stale plan receipt', exitCode: 1);
        }

        throw new RuntimeException('Unexpected planning artifact command.');
    })->preventStrayProcesses();

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningArtifact(
        $this->config,
        $this->worktree,
        'ORB-234',
        $artifactSha,
    ))->toThrow(OrbitRepositoryFailed::class, 'stale plan receipt');

    Process::assertNotRan(fn ($process): bool => $process->command[0] === 'git');
});

it('rejects a saved planning artifact without an exact pending verdict', function () {
    $artifactSha = str_repeat('d', 40);
    $validator = realpath($this->repositoryPath.'/bin/plan-lint');

    Process::fake(function ($process) use ($artifactSha, $validator) {
        return match ($process->command) {
            [$validator, 'verify', 'ORB-234', '--worktree='.$this->worktreePath, '--artifact='.$artifactSha] => Process::result(output: "passed\n"),
            ['git', 'show', $artifactSha.':.loop/plan.md'] => Process::result(output: "Review verdict: PASS\n"),
            default => throw new RuntimeException('Unexpected planning artifact command.'),
        };
    })->preventStrayProcesses();

    expect(fn () => app(ProcessOrbitRepository::class)->verifyPlanningArtifact(
        $this->config,
        $this->worktree,
        'ORB-234',
        $artifactSha,
    ))->toThrow(OrbitRepositoryFailed::class, 'must have a PENDING review verdict');
});
