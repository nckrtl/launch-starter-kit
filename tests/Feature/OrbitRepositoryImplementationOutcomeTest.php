<?php

use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-implementation-repository-'.bin2hex(random_bytes(4)));
    $this->repositoryPath = $this->base.'/repository';
    $this->commonDirectory = $this->repositoryPath.'/.git';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->startupSha = str_repeat('a', 40);
    $this->reviewedSha = str_repeat('b', 40);
    $this->candidateSha = str_repeat('c', 40);
    $this->treeSha = str_repeat('d', 40);
    $this->artifactSha = str_repeat('e', 40);
    $this->gatePath = $this->commonDirectory.'/orbit-checks/'.$this->candidateSha.'/review/result.json';
    $this->snapshotPath = $this->worktreePath.'/.loop/issue.json';
    $this->issueId = '11111111-2222-4333-8444-555555555555';

    File::makeDirectory($this->repositoryPath.'/bin', 0755, true);
    File::makeDirectory(dirname($this->gatePath), 0755, true);
    File::makeDirectory($this->worktreePath.'/.loop', 0755, true);

    foreach (['loop-flow', 'loop-artifacts'] as $script) {
        File::put($this->repositoryPath.'/bin/'.$script, "#!/usr/bin/env python3\n");
        chmod($this->repositoryPath.'/bin/'.$script, 0755);
    }

    File::put($this->gatePath, json_encode(
        implementationGateReceipt($this->worktreePath, $this->candidateSha, $this->treeSha),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));
    $snapshotContents = json_encode([
        'id' => $this->issueId,
        'identifier' => 'ORB-234',
        'title' => 'Implement this issue',
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
    $this->worktree = new PreparedWorktree(realpath($this->worktreePath), $this->startupSha);
    $this->snapshot = new PreparedIssueSnapshot(
        schema: OrbitIssueSnapshot::SCHEMA,
        provider: OrbitIssueSnapshot::PROVIDER,
        path: realpath($this->snapshotPath),
        contentsHash: hash('sha256', $snapshotContents),
        contractSchema: OrbitIssueSnapshot::CONTRACT_SCHEMA,
        contractHash: str_repeat('f', 64),
        issueId: $this->issueId,
        issueKey: 'ORB-234',
    );
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Flow: discovery',
        'Candidate: '.$this->candidateSha,
        'Artifact: '.$this->artifactSha,
        'Builder gate: passed ('.$this->gatePath.')',
    ]);
});

afterEach(fn () => File::deleteDirectory($this->base));

/** @return array<string, mixed> */
function implementationGateReceipt(string $worktree, string $candidateSha, string $treeSha): array
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
        'candidate' => $candidateSha,
        'tree' => $treeSha,
        'worktree' => $worktree,
        'checks' => $checks,
        'passed' => true,
        'unchanged' => true,
    ];
}

/** @param array<string, string|int> $overrides */
function fakeImplementationOutcomeInspection(object $test, array $overrides = []): void
{
    $ref = 'refs/tags/loop/orb-234/'.$test->candidateSha;
    $outputs = [
        'inventory' => "worktree {$test->repositoryPath}\nHEAD ".str_repeat('0', 40)."\nbranch refs/heads/main\n\n"
            ."worktree {$test->worktreePath}\nHEAD {$test->candidateSha}\nbranch refs/heads/orb-234\n",
        'status' => '',
        'conflicts' => '',
        'head' => $test->candidateSha."\n",
        'tree' => $test->treeSha."\n",
        'common' => $test->commonDirectory."\n",
        'startup_ancestor_exit' => 0,
        'reviewed_ancestor_exit' => 0,
        'candidate_loop' => '',
        'flow' => "discovery\n",
        'remote' => $test->candidateSha."\trefs/heads/orb-234\n",
        'remote_exit' => 0,
        'artifact' => json_encode([
            'candidate' => $test->candidateSha,
            'ref' => $ref,
            'artifacts' => $test->artifactSha,
        ], JSON_THROW_ON_ERROR)."\n",
        'artifact_error' => '',
        'artifact_exit' => 0,
        ...$overrides,
    ];
    $flow = realpath($test->repositoryPath.'/bin/loop-flow');
    $artifacts = realpath($test->repositoryPath.'/bin/loop-artifacts');

    Process::fake(function ($process) use ($test, $outputs, $flow, $artifacts) {
        return match ($process->command) {
            ['git', 'worktree', 'list', '--porcelain'] => Process::result(output: (string) $outputs['inventory']),
            ['git', 'status', '--porcelain'] => Process::result(output: (string) $outputs['status']),
            ['git', 'diff', '--name-only', '--diff-filter=U'] => Process::result(output: (string) $outputs['conflicts']),
            ['git', 'rev-parse', 'HEAD'] => Process::result(output: (string) $outputs['head']),
            ['git', 'rev-parse', 'HEAD^{tree}'] => Process::result(output: (string) $outputs['tree']),
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'] => Process::result(output: (string) $outputs['common']),
            ['git', 'merge-base', '--is-ancestor', $test->startupSha, $test->candidateSha] => Process::result(
                exitCode: (int) $outputs['startup_ancestor_exit'],
            ),
            ['git', 'merge-base', '--is-ancestor', $test->reviewedSha, $test->candidateSha] => Process::result(
                exitCode: (int) $outputs['reviewed_ancestor_exit'],
            ),
            ['git', 'ls-tree', '-r', '--name-only', $test->candidateSha, '--', '.loop'] => Process::result(
                output: (string) $outputs['candidate_loop'],
            ),
            [$flow, 'status', '--worktree='.$test->worktreePath] => Process::result(output: (string) $outputs['flow']),
            ['git', 'ls-remote', 'origin', 'refs/heads/orb-234'] => Process::result(
                output: (string) $outputs['remote'],
                exitCode: (int) $outputs['remote_exit'],
            ),
            [
                $artifacts,
                'fetch',
                'ORB-234',
                '--candidate='.$test->candidateSha,
                '--expected-artifact='.$test->artifactSha,
            ] => Process::result(
                output: (string) $outputs['artifact'],
                errorOutput: (string) $outputs['artifact_error'],
                exitCode: (int) $outputs['artifact_exit'],
            ),
            default => throw new RuntimeException('Unexpected implementation outcome command.'),
        };
    })->preventStrayProcesses();
}

it('verifies a clean pushed implementation and its exact published evidence without rerunning checks', function () {
    fakeImplementationOutcomeInspection($this);

    $verified = app(OrbitImplementationRepository::class)->verifyImplementationOutcome(
        $this->config,
        $this->worktree,
        $this->snapshot,
        $this->reviewedSha,
        $this->candidateSha,
        $this->artifactSha,
        $this->gatePath,
        $this->body,
    );

    expect($verified->candidateSha)->toBe($this->candidateSha)
        ->and($verified->treeSha)->toBe($this->treeSha)
        ->and($verified->artifactSha)->toBe($this->artifactSha)
        ->and($verified->gateReceiptPath)->toBe($this->gatePath)
        ->and($verified->pullRequestBodyHash)->toBe(hash('sha256', $this->body))
        ->and($verified->flow)->toBe('discovery');
    Process::assertRanTimes(fn () => true, 12);
    Process::assertNotRan(fn ($process): bool => $process->command === ['composer', 'check']);
});

it('rejects implementation state that is not the exact clean descendant and selected flow', function (
    array $overrides,
) {
    fakeImplementationOutcomeInspection($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyImplementationOutcome(
        $this->config,
        $this->worktree,
        $this->snapshot,
        $this->reviewedSha,
        $this->candidateSha,
        $this->artifactSha,
        $this->gatePath,
        $this->body,
    ))->toThrow(OrbitRepositoryFailed::class, 'no longer matches its issue worktree');
})->with([
    'dirty worktree' => [['status' => " M apps/gateway/app/Changed.php\n"]],
    'conflicted worktree' => [['conflicts' => "apps/gateway/app/Changed.php\n"]],
    'different head' => [['head' => str_repeat('0', 40)."\n"]],
    'startup is not an ancestor' => [['startup_ancestor_exit' => 1]],
    'reviewed plan is not an ancestor' => [['reviewed_ancestor_exit' => 1]],
    'tracked loop state' => [['candidate_loop' => ".loop/plan.md\n"]],
    'different flow' => [['flow' => "proof\n"]],
]);

it('rejects implementation evidence outside the exact pushed branch and artifact binding', function (
    array $overrides,
    string $message,
) {
    fakeImplementationOutcomeInspection($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyImplementationOutcome(
        $this->config,
        $this->worktree,
        $this->snapshot,
        $this->reviewedSha,
        $this->candidateSha,
        $this->artifactSha,
        $this->gatePath,
        $this->body,
    ))->toThrow(OrbitRepositoryFailed::class, $message);
})->with([
    'different remote head' => [
        ['remote' => str_repeat('0', 40)."\trefs/heads/orb-234\n"],
        'does not match its branch',
    ],
    'artifact fetch failure' => [
        ['artifact' => '', 'artifact_error' => 'missing artifact', 'artifact_exit' => 1],
        'missing artifact',
    ],
    'different artifact result' => [
        ['artifact' => '{}'],
        'does not match its candidate',
    ],
]);

it('rejects a PR body without every exact implementation binding', function (string $missing) {
    fakeImplementationOutcomeInspection($this);
    $body = str_replace($missing, 'removed', $this->body);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyImplementationOutcome(
        $this->config,
        $this->worktree,
        $this->snapshot,
        $this->reviewedSha,
        $this->candidateSha,
        $this->artifactSha,
        $this->gatePath,
        $body,
    ))->toThrow(OrbitRepositoryFailed::class, 'body is missing an implementation binding');
})->with([
    'issue' => 'Issue: ORB-234',
    'candidate' => str_repeat('c', 40),
    'artifact' => str_repeat('e', 40),
    'flow' => 'discovery',
    'gate' => 'Builder gate: passed',
]);

it('rejects a changed Builder gate or retained issue snapshot', function (string $change) {
    if ($change === 'gate') {
        File::put($this->gatePath, json_encode([
            ...implementationGateReceipt($this->worktreePath, $this->candidateSha, $this->treeSha),
            'passed' => false,
        ], JSON_THROW_ON_ERROR));
    } else {
        File::append($this->snapshotPath, "\n");
    }
    fakeImplementationOutcomeInspection($this);

    expect(fn () => app(ProcessOrbitRepository::class)->verifyImplementationOutcome(
        $this->config,
        $this->worktree,
        $this->snapshot,
        $this->reviewedSha,
        $this->candidateSha,
        $this->artifactSha,
        $this->gatePath,
        $this->body,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with(['gate', 'snapshot']);
