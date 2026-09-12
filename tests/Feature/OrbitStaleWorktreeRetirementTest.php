<?php

use App\Delivery\Actions\RetireStaleOrbitWorktree;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitStaleWorktreeRetirer;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitStaleWorktree;
use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final class FakeOrbitStaleWorktreeRetirer implements OrbitStaleWorktreeRetirer
{
    public ?OrbitStaleWorktree $stale = null;

    public ?RetiredOrbitStaleWorktree $retired = null;

    public ?OrbitRepositoryFailed $failure = null;

    /** @var list<string> */
    public array $inspections = [];

    /** @var list<OrbitStaleWorktree> */
    public array $retirements = [];

    public function inspectStaleWorktree(OrbitProjectConfig $config, string $issueKey): ?OrbitStaleWorktree
    {
        $this->inspections[] = $issueKey;

        return $this->stale;
    }

    public function retireStaleWorktree(
        OrbitProjectConfig $config,
        OrbitStaleWorktree $worktree,
    ): RetiredOrbitStaleWorktree {
        $this->retirements[] = $worktree;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->retired ?? throw new LogicException('No retirement result was configured.');
    }
}

final class FakeStaleWorktreeHerdrRuntime implements HerdrWorkspaceRuntime
{
    public int $snapshotCalls = 0;

    public function __construct(
        public HerdrSessionSnapshot $session,
        public ?HerdrSessionSnapshot $nextSession = null,
    ) {}

    public function snapshot(): HerdrSessionSnapshot
    {
        $this->snapshotCalls++;

        return $this->snapshotCalls > 1 && $this->nextSession !== null
            ? $this->nextSession
            : $this->session;
    }

    public function readAgent(string $name): HerdrAgentOutput
    {
        throw new LogicException('Not used.');
    }

    public function sendAgentKeys(string $name, array $keys): void
    {
        throw new LogicException('Not used.');
    }

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo
    {
        throw new LogicException('Not used.');
    }

    public function closeWorkspace(string $workspaceId, int $protocol): void
    {
        throw new LogicException('Not used.');
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/stale-worktree-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    $this->common = $this->repository.'/.git';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktree = $this->base.'/legacy/orb-234-old-title';
    $this->branch = 'orb-234-old-title';
    $this->mainSha = str_repeat('a', 40);
    $this->headSha = str_repeat('b', 40);
    $this->treeSha = str_repeat('c', 40);
    $this->otherSha = str_repeat('d', 40);
    $this->directory = $this->common.'/orbit-delivery/v1/orb-234';
    File::makeDirectory($this->directory, 0700, true);
    File::makeDirectory($this->worktreeRoot, 0755, true);
    File::makeDirectory($this->worktree.'/.loop', 0755, true);
    File::makeDirectory($this->worktree.'/docs', 0755, true);
    File::put($this->worktree.'/.git', "gitdir: {$this->common}/worktrees/orb-234\n");
    File::put($this->worktree.'/.loop/plan.md', "historical plan\n");
    File::put($this->worktree.'/docs/new.md', "uncommitted schedule documentation\n");
    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: $this->repository,
        worktreeRoot: $this->worktreeRoot,
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

/**
 * @param  list<array{worktree: string, head: string, branch: ?string, prunable?: bool}>  $items
 */
function staleWorktreeInventory(array $items): string
{
    return implode("\0\0", array_map(static function (array $item): string {
        $fields = ["worktree {$item['worktree']}", "HEAD {$item['head']}"];

        if ($item['branch'] !== null) {
            $fields[] = "branch {$item['branch']}";
        }

        if ($item['prunable'] ?? false) {
            $fields[] = 'prunable gitdir file points to non-existent location';
        }

        return implode("\0", $fields);
    }, $items))."\0\0";
}

/** @param array<string, string> $branches */
function staleBranchInventory(array $branches): string
{
    return implode("\n", array_map(
        static fn (string $sha, string $ref): string => "{$sha} {$ref}",
        $branches,
        array_keys($branches),
    ))."\n";
}

/** @param array<string, mixed> $overrides */
function fakeStaleRetirement(object $test, array $overrides = []): object
{
    $state = new class
    {
        public bool $targetPresent = true;

        public bool $targetPrunable = false;

        public bool $branchPresent = true;

        public bool $retained = false;

        public bool $removeThrown = false;

        public int $captures = 0;

        public string $otherSha = '';

        /** @var list<array<int, string>> */
        public array $commands = [];
    };
    $state->targetPrunable = $overrides['target_prunable'] ?? false;
    $state->otherSha = $test->otherSha;

    Process::fake(function ($process) use ($test, $overrides, $state) {
        $state->commands[] = $process->command;
        $worktrees = [[
            'worktree' => $test->repository,
            'head' => $test->mainSha,
            'branch' => 'refs/heads/main',
        ]];

        if ($state->targetPresent) {
            $worktrees[] = [
                'worktree' => $test->worktree,
                'head' => $test->headSha,
                'branch' => 'refs/heads/'.$test->branch,
                'prunable' => $state->targetPrunable,
            ];
        }

        $worktrees[] = [
            'worktree' => $test->base.'/other',
            'head' => $state->otherSha,
            'branch' => 'refs/heads/orb-999',
            'prunable' => $overrides['other_prunable'] ?? false,
        ];
        $branches = [
            'refs/heads/main' => $test->mainSha,
            ...($state->branchPresent ? ['refs/heads/'.$test->branch => $test->headSha] : []),
            ...($overrides['extra_branches'] ?? []),
            'refs/heads/orb-999' => $state->otherSha,
        ];

        if ($process->command === ['git', 'worktree', 'list', '--porcelain', '-z']) {
            return Process::result(output: staleWorktreeInventory($worktrees));
        }

        if ($process->command === [
            'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
        ]) {
            return Process::result(output: staleBranchInventory($branches));
        }

        if ($process->command === [
            'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
        ]) {
            return Process::result(output: ($overrides['common'] ?? $test->common)."\n");
        }

        if ($process->command === ['git', 'rev-parse', 'HEAD^{tree}']) {
            return Process::result(output: $test->treeSha."\n");
        }

        if ($process->command === ['git', 'rev-parse', 'HEAD']) {
            $state->captures++;

            return Process::result(output: $test->headSha."\n");
        }

        if ($process->command === [
            'git', 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignored=no',
        ]) {
            return Process::result(output: " M docs/README.md\0?? docs/new.md\0");
        }

        if ($process->command === ['git', 'diff', '--binary', '--no-ext-diff', 'HEAD', '--', '.']) {
            $suffix = ($overrides['change_after_archive'] ?? false) && $state->captures > 1
                ? "changed\n"
                : '';

            return Process::result(output: "diff --git a/docs/README.md b/docs/README.md\n{$suffix}");
        }

        if ($process->command === ['git', 'ls-files', '--others', '--exclude-standard', '-z']) {
            return Process::result(output: "docs/new.md\0");
        }

        if ($process->command === [
            'git', 'show-ref', '--verify', '--hash',
            'refs/orbit-delivery/retired-worktrees/orb-234/'.$test->headSha,
        ]) {
            return $state->retained
                ? Process::result(output: $test->headSha."\n")
                : Process::result(exitCode: 1);
        }

        if ($process->command === [
            'git', 'update-ref',
            'refs/orbit-delivery/retired-worktrees/orb-234/'.$test->headSha,
            $test->headSha,
            str_repeat('0', 40),
        ]) {
            $state->retained = true;

            return Process::result();
        }

        if ($process->command === ['git', 'worktree', 'remove', '--force', $test->worktree]) {
            $state->targetPresent = false;
            $state->targetPrunable = false;
            File::deleteDirectory($test->worktree);

            if ($overrides['advance_unrelated_after_remove'] ?? false) {
                $state->otherSha = str_repeat('f', 40);
            }

            if (($overrides['throw_after_remove'] ?? false) && ! $state->removeThrown) {
                $state->removeThrown = true;
                throw new RuntimeException('Removal interrupted.');
            }

            return Process::result(
                errorOutput: $overrides['remove_error'] ?? '',
                exitCode: isset($overrides['remove_error']) ? 1 : 0,
            );
        }

        if ($process->command === [
            'git', 'update-ref', '-d', 'refs/heads/'.$test->branch, $test->headSha,
        ]) {
            $state->branchPresent = false;

            return Process::result();
        }

        throw new RuntimeException('Unexpected stale retirement command: '.implode(' ', $process->command));
    })->preventStrayProcesses();

    return $state;
}

function staleIssue(string $state = 'Todo'): OrbitIssueSnapshot
{
    return new OrbitIssueSnapshot(
        issueId: '11111111-2222-4333-8444-555555555555',
        issueKey: 'ORB-234',
        payload: [
            'state' => [
                'name' => $state,
                'type' => $state === 'Todo' ? 'unstarted' : 'started',
            ],
        ],
        contractHash: str_repeat('e', 64),
    );
}

function emptyStaleHerdrSession(): HerdrSessionSnapshot
{
    return new HerdrSessionSnapshot('herdr-test', 22, [], [], []);
}

it('archives dirty stale work before removing only its checkout and branch', function () {
    $state = fakeStaleRetirement($this);
    $repository = app(ProcessOrbitRepository::class);
    $stale = $repository->inspectStaleWorktree($this->config, 'ORB-234');

    expect($stale)->not->toBeNull();

    $retired = $repository->retireStaleWorktree($this->config, $stale);
    $journal = json_decode(File::get($this->directory.'/stale-worktree-retirement.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($retired->disposition)->toBe('retired')
        ->and($retired->branch)->toBe($this->branch)
        ->and($journal['state'])->toBe('retired')
        ->and(File::exists($this->worktree))->toBeFalse()
        ->and(File::get($retired->archive.'/changes.patch'))->toContain('docs/README.md')
        ->and(File::get($retired->archive.'/untracked/docs/new.md'))->toBe("uncommitted schedule documentation\n")
        ->and(File::get($retired->archive.'/loop/plan.md'))->toBe("historical plan\n")
        ->and($state->commands)->toContain([
            'git', 'worktree', 'remove', '--force', $this->worktree,
        ])
        ->and($state->commands)->toContain([
            'git', 'update-ref', '-d', 'refs/heads/'.$this->branch, $this->headSha,
        ]);
});

it('reconciles an interrupted removal from its retained journal without replaying deletion', function () {
    $state = fakeStaleRetirement($this, ['throw_after_remove' => true]);
    $repository = app(ProcessOrbitRepository::class);
    $stale = $repository->inspectStaleWorktree($this->config, 'ORB-234');

    expect(fn () => $repository->retireStaleWorktree($this->config, $stale))
        ->toThrow(OrbitRepositoryFailed::class, 'could not be removed');

    $retained = $repository->inspectStaleWorktree($this->config, 'ORB-234');
    $retired = $repository->retireStaleWorktree($this->config, $retained);

    expect($retired->disposition)->toBe('retired')
        ->and(array_filter(
            $state->commands,
            fn (array $command): bool => $command === [
                'git', 'worktree', 'remove', '--force', $this->worktree,
            ],
        ))->toHaveCount(1);
});

it('returns retained evidence idempotently after retirement completed', function () {
    $state = fakeStaleRetirement($this);
    $repository = app(ProcessOrbitRepository::class);
    $stale = $repository->inspectStaleWorktree($this->config, 'ORB-234');
    $repository->retireStaleWorktree($this->config, $stale);
    $retained = $repository->inspectStaleWorktree($this->config, 'ORB-234');
    $retired = $repository->retireStaleWorktree($this->config, $retained);

    expect($retired->disposition)->toBe('already_retired')
        ->and(array_filter(
            $state->commands,
            fn (array $command): bool => $command === [
                'git', 'worktree', 'remove', '--force', $this->worktree,
            ],
        ))->toHaveCount(1);
});

it('allows unrelated worktree heads to advance during retirement reconciliation', function () {
    $state = fakeStaleRetirement($this, [
        'throw_after_remove' => true,
        'advance_unrelated_after_remove' => true,
    ]);
    $repository = app(ProcessOrbitRepository::class);
    $stale = $repository->inspectStaleWorktree($this->config, 'ORB-234');

    expect(fn () => $repository->retireStaleWorktree($this->config, $stale))
        ->toThrow(OrbitRepositoryFailed::class, 'could not be removed');

    $retired = $repository->retireStaleWorktree(
        $this->config,
        $repository->inspectStaleWorktree($this->config, 'ORB-234'),
    );

    expect($retired->disposition)->toBe('retired')
        ->and($state->otherSha)->toBe(str_repeat('f', 40));
});

it('refuses changed dirty work after archiving and before removal', function () {
    $state = fakeStaleRetirement($this, ['change_after_archive' => true]);
    $repository = app(ProcessOrbitRepository::class);
    $stale = $repository->inspectStaleWorktree($this->config, 'ORB-234');

    expect(fn () => $repository->retireStaleWorktree($this->config, $stale))
        ->toThrow(OrbitRepositoryFailed::class, 'changed after it was archived')
        ->and($state->commands)->not->toContain([
            'git', 'worktree', 'remove', '--force', $this->worktree,
        ]);
});

it('refuses ambiguous or prunable stale worktree inventories', function (array $overrides, string $message) {
    fakeStaleRetirement($this, $overrides);

    expect(fn () => app(ProcessOrbitRepository::class)->inspectStaleWorktree($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, $message);
})->with([
    'ambiguous sibling branch' => [[
        'extra_branches' => ['refs/heads/orb-234-another' => str_repeat('e', 40)],
    ], 'inventory is ambiguous'],
    'unrelated prunable worktree' => [['other_prunable' => true], 'prunable Orbit worktree'],
]);

it('refuses a stale checkout from another Git common directory', function () {
    $foreign = $this->base.'/foreign-common';
    File::makeDirectory($foreign, 0755, true);
    fakeStaleRetirement($this, ['common' => $foreign]);

    expect(fn () => app(ProcessOrbitRepository::class)->inspectStaleWorktree($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'does not belong');
});

it('retires a Todo stale checkout only after Herdr proves it inactive', function () {
    $contract = new FakeOrbitStaleWorktreeRetirer;
    $herdr = new FakeStaleWorktreeHerdrRuntime(emptyStaleHerdrSession());
    $contract->stale = new OrbitStaleWorktree(
        $this->repository,
        $this->worktree,
        'ORB-234',
        $this->branch,
        $this->headSha,
        $this->treeSha,
    );
    $contract->retired = new RetiredOrbitStaleWorktree(
        $this->repository,
        $this->worktree,
        'ORB-234',
        $this->branch,
        $this->headSha,
        $this->treeSha,
        'refs/orbit-delivery/retired-worktrees/orb-234/'.$this->headSha,
        $this->directory.'/retired-worktrees/'.$this->headSha.'/'.str_repeat('f', 64),
        str_repeat('f', 64),
        'retired',
        '2026-09-12T02:00:00Z',
    );
    $action = new RetireStaleOrbitWorktree($contract, $herdr);

    expect($action->handle($this->config, staleIssue()))->toBe($contract->retired)
        ->and($herdr->snapshotCalls)->toBe(2)
        ->and($contract->retirements)->toBe([$contract->stale]);
});

it('rechecks Herdr activity immediately before stale retirement', function () {
    $contract = new FakeOrbitStaleWorktreeRetirer;
    $contract->stale = new OrbitStaleWorktree(
        $this->repository,
        $this->worktree,
        'ORB-234',
        $this->branch,
        $this->headSha,
        $this->treeSha,
    );
    $active = new HerdrSessionSnapshot(
        'herdr-test',
        22,
        [],
        [new HerdrSnapshotPane('workspace', 'tab', 'pane', 'terminal', $this->worktree.'/docs')],
        [],
    );
    $herdr = new FakeStaleWorktreeHerdrRuntime(emptyStaleHerdrSession(), $active);

    expect(fn () => (new RetireStaleOrbitWorktree($contract, $herdr))
        ->handle($this->config, staleIssue()))
        ->toThrow(OrbitRepositoryFailed::class, 'still has a Herdr pane')
        ->and($herdr->snapshotCalls)->toBe(2)
        ->and($contract->retirements)->toBe([]);
});

it('never inspects stale work for an issue that is already active', function () {
    $contract = new FakeOrbitStaleWorktreeRetirer;
    $herdr = new FakeStaleWorktreeHerdrRuntime(emptyStaleHerdrSession());
    $action = new RetireStaleOrbitWorktree($contract, $herdr);

    expect($action->handle($this->config, staleIssue('In Progress')))->toBeNull()
        ->and($contract->inspections)->toBe([])
        ->and($herdr->snapshotCalls)->toBe(0);
});

it('preserves stale work referenced by any Herdr workspace, pane, or agent', function (string $kind) {
    $contract = new FakeOrbitStaleWorktreeRetirer;
    $contract->stale = new OrbitStaleWorktree(
        $this->repository,
        $this->worktree,
        'ORB-234',
        $this->branch,
        $this->headSha,
        $this->treeSha,
    );
    $session = new HerdrSessionSnapshot(
        'herdr-test',
        22,
        $kind === 'workspace'
            ? [new HerdrSnapshotWorkspace('workspace', $this->repository, $this->worktree, true)]
            : [],
        $kind === 'pane'
            ? [new HerdrSnapshotPane('workspace', 'tab', 'pane', 'terminal', $this->worktree.'/docs')]
            : [],
        $kind === 'agent'
            ? [new HerdrSnapshotAgent('workspace', 'tab', 'pane', 'terminal', 'codex', 'worker', 'idle', $this->worktree)]
            : [],
    );
    $action = new RetireStaleOrbitWorktree(
        $contract,
        new FakeStaleWorktreeHerdrRuntime($session),
    );

    expect(fn () => $action->handle($this->config, staleIssue()))
        ->toThrow(OrbitRepositoryFailed::class, 'still has a Herdr')
        ->and($contract->retirements)->toBe([]);
})->with(['workspace', 'pane', 'agent']);

it('canonicalizes Herdr paths before deciding a stale worktree is inactive', function () {
    $contract = new FakeOrbitStaleWorktreeRetirer;
    $contract->stale = new OrbitStaleWorktree(
        $this->repository,
        $this->worktree,
        'ORB-234',
        $this->branch,
        $this->headSha,
        $this->treeSha,
    );
    $session = new HerdrSessionSnapshot(
        'herdr-test',
        22,
        [],
        [new HerdrSnapshotPane(
            'workspace',
            'tab',
            'pane',
            'terminal',
            $this->worktree.'/docs/../docs',
        )],
        [],
    );
    $action = new RetireStaleOrbitWorktree(
        $contract,
        new FakeStaleWorktreeHerdrRuntime($session),
    );

    expect(fn () => $action->handle($this->config, staleIssue()))
        ->toThrow(OrbitRepositoryFailed::class, 'still has a Herdr pane')
        ->and($contract->retirements)->toBe([]);
});
