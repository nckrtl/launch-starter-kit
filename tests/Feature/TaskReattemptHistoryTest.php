<?php

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Repositories\OrbitCandidateReceipt;
use App\Models\TaskAgentDispatch;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\PrepareTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingReattempt;
use App\Tasks\Landing\TaskLandingReattemptGit;
use App\Tasks\Landing\TaskLandingRepository;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ApplyTaskReattempt;
use App\Tasks\Runtime\PrepareTaskReattempt;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskReattemptGit;
use App\Tasks\Runtime\TaskReattemptInput;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class HistoryReattemptAgents implements TaskAgents
{
    public array $sessions = [];

    public array $prompts = [];

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        if (! in_array($session, $this->sessions, true)) {
            throw new LogicException('Unknown disposable session.');
        }
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        if ($workspace->herdr_workspace === null) {
            $workspace->update(['herdr_workspace' => ['workspaceId' => 'fixture', 'checkoutPath' => $workspace->worktree]]);
        }

        return $this->sessions[] = ['workspaceId' => 'fixture', 'tabId' => 'tab', 'paneId' => $name,
            'terminalId' => $name, 'agentName' => $name, 'agentId' => 'native-'.$name, 'workingDirectory' => $workspace->worktree];
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = compact('session', 'prompt');

        return $session;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->historyDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/task-history-test-XXXXXX'])->throw()->output());
});

afterEach(fn () => File::deleteDirectory($this->historyDirectory));

function historyGit(string $directory, array $arguments, ?string $input = null): string
{
    if (! str_starts_with($directory, test()->historyDirectory.'/')) {
        throw new LogicException('Git writes are confined to the disposable fixture.');
    }

    return rtrim(Process::path($directory)->timeout(10)->input($input)->env([
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0',
    ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', '-c', 'gc.auto=0', '-c', 'maintenance.auto=false', ...$arguments])->throw()->output(), "\n");
}

function historyFixture(bool $conflict = false): void
{
    $test = test();
    $test->repository = $test->historyDirectory.'/repository';
    $test->worktree = $test->historyDirectory.'/worktrees/feature';
    File::makeDirectory($test->repository, recursive: true);
    File::makeDirectory(dirname($test->worktree));
    File::makeDirectory($test->historyDirectory.'/projects');
    config(['commander.projects_path' => $test->historyDirectory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    historyGit($test->repository, ['init', '--initial-branch=main']);
    historyGit($test->repository, ['config', 'user.email', 'history@example.test']);
    historyGit($test->repository, ['config', 'user.name', 'History Test']);
    historyGit($test->repository, ['remote', 'add', 'origin', $test->repository]);
    File::put($test->repository.'/fixture.txt', "broken fixture\n");
    historyGit($test->repository, ['add', '--all']);
    historyGit($test->repository, ['commit', '-m', 'Original upstream']);
    historyGit($test->repository, ['worktree', 'add', '-b', 'feature', $test->worktree]);
    File::put($test->worktree.'/schedules.md', "Authored Schedule CLI documentation.\n");
    if ($conflict) {
        File::put($test->worktree.'/fixture.txt', "conflicting authored choice\n");
    }
    historyGit($test->worktree, ['add', '--all']);
    historyGit($test->worktree, ['commit', '-m', 'Authored Schedule CLI docs']);
    $test->docsCommit = historyGit($test->worktree, ['rev-parse', 'HEAD']);
    File::put($test->repository.'/prior-main.txt', "Earlier upstream change\n");
    historyGit($test->repository, ['add', '--all']);
    historyGit($test->repository, ['commit', '-m', 'Earlier upstream']);
    $test->priorMain = historyGit($test->repository, ['rev-parse', 'HEAD']);
    historyGit($test->worktree, ['merge', '--no-ff', '--no-edit', $test->priorMain]);
    $test->originalBase = historyGit($test->worktree, ['rev-parse', 'HEAD']);
    config(['task-runtime.enabled' => true, 'task-runtime.projects.orbit' => [
        'repository' => $test->repository, 'worktree_root' => dirname($test->worktree), 'socket' => '/unused-history.sock',
        'agent_kind' => 'codex', 'agent_arguments' => [], 'flow_version' => 1, 'instructions' => 'Preserve authored history.',
        'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 5,
    ]]);
    $test->agents = new HistoryReattemptAgents;
    app()->instance(TaskAgents::class, $test->agents);
    $test->root = app(CreateTask::class)->handle('orbit', 'Schedule CLI', 'Implement Schedule CLI.', TaskKind::Group, acceptanceCriteria: 'Schedules work.');
    $test->child = app(CreateTask::class)->handle('orbit', 'Implementation', 'Implement the commands.', parent: $test->root, acceptanceCriteria: 'Commands pass checks.');
    $test->workspace = app(StartTaskWorkspace::class)->handle($test->root, $test->worktree, app(TaskRuntimePlan::class)->hash($test->root), 'ORB-990071', true);
    $test->origin = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    File::append($test->worktree.'/schedules.md', "Staged documentation clarification.\n");
    historyGit($test->worktree, ['add', 'schedules.md']);
    File::append($test->worktree.'/schedules.md', "Unstaged documentation clarification.\n");
    File::put($test->worktree.'/command.php', "<?php // Untracked task implementation.\n");
    historySubmit($test->origin, 'blocked');
    historyGit($test->repository, ['checkout', '-b', 'repair']);
    File::put($test->repository.'/fixture.txt', "reviewed prerequisite repair\n");
    historyGit($test->repository, ['add', '--all']);
    historyGit($test->repository, ['commit', '-m', 'Independently reviewed prerequisite']);
    $test->repairCandidate = historyGit($test->repository, ['rev-parse', 'HEAD']);
    historyGit($test->repository, ['checkout', 'main']);
    historyGit($test->repository, ['merge', '--no-ff', '--no-edit', 'repair']);
    $test->verifiedMain = historyGit($test->repository, ['rev-parse', 'HEAD']);
    foreach (['review', 'main', 'restoration'] as $name) {
        File::put($test->historyDirectory.'/'.$name.'.log', $name.' evidence for '.$test->verifiedMain);
    }
}

function historySubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): void
{
    $receipt = ['token' => $dispatch->handoff_token, 'summary' => 'Exact fixture handoff.', 'evidence' => 'Disposable fixture evidence.'];
    if ($verdict !== null) {
        $receipt['verdict'] = $verdict;
    }
    app(SubmitTaskDispatch::class)->handle($dispatch, $receipt);
}

function historyLog(string $name): array
{
    $path = test()->historyDirectory.'/'.$name.'.log';

    return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
}

function historyRequest(): array
{
    $test = test();

    return ['reason' => 'Preserve the authored docs while incorporating the prerequisite.', 'evidence' => 'Reviewed separately; no scope change.',
        'prerequisite' => ['source' => 'ORB-REPAIR', 'pull_request' => 'https://example.test/pull/2',
            'candidate' => $test->repairCandidate, 'merge' => $test->verifiedMain, 'main' => $test->verifiedMain,
            'review' => historyLog('review'), 'verification' => ['head' => $test->verifiedMain, 'command' => ['composer', 'test:affected'],
                'working_directory' => $test->repository, 'exit_code' => 0, 'log' => historyLog('main')]],
        'integration' => 'preserve_history'];
}

function historyPrepare(?array $request = null, ?string $state = null, bool $apply = false): array
{
    $test = test();

    return app(PrepareTaskReattempt::class)->handle($test->workspace->id, $test->origin->id, $test->originalBase,
        $test->workspace->manifest_hash, $request ?? historyRequest(), true, $state, $apply);
}

function historyCheckpoint(): TaskReattemptCheckpoint
{
    $preview = historyPrepare();
    historyPrepare(state: $preview['state_hash'], apply: true);

    return test()->checkpoint = TaskReattemptCheckpoint::query()->sole();
}

function historyIntegrate(bool $restore = true): void
{
    $test = test();
    historyGit($test->worktree, ['stash', 'push', '--include-untracked', '-m', 'Disposable checkpoint backup']);
    historyGit($test->worktree, ['merge', '--no-ff', '--no-edit', $test->verifiedMain]);
    $test->integration = historyGit($test->worktree, ['rev-parse', 'HEAD']);
    if ($restore) {
        historyGit($test->worktree, ['stash', 'apply', '--index']);
    }
}

function historyApply(?string $state = null, bool $apply = false): array
{
    return app(ApplyTaskReattempt::class)->handle(test()->checkpoint->id, ['reason' => 'Resume with preserved history.',
        'evidence' => 'Merged pinned upstream and restored both task trees.', 'log' => historyLog('restoration')], true, $state, $apply);
}

function historyCutover(): TaskReattempt
{
    $preview = historyApply();
    historyApply($preview['state_hash'], true);

    return test()->audit = TaskReattempt::query()->sole();
}

function historySnapshot(): array
{
    $test = test();

    return [historyGit($test->worktree, ['count-objects', '-v']), historyGit($test->worktree, ['show-ref']),
        historyGit($test->worktree, ['status', '--porcelain=v1']), historyGit($test->worktree, ['diff', '--binary']),
        hash_file('sha256', historyGit($test->worktree, ['rev-parse', '--git-path', 'index'])),
        File::get($test->worktree.'/schedules.md'), File::exists($test->worktree.'/command.php') ? File::get($test->worktree.'/command.php') : null,
        $test->workspace->fresh()->toArray(), $test->origin->fresh()->toArray(), TaskRun::query()->count(),
        TaskReattemptCheckpoint::query()->count(), TaskReattempt::query()->count(), TaskAgentDispatch::query()->count(),
        $test->agents->sessions, $test->agents->prompts];
}

function historyComplete(): array
{
    historyCheckpoint();
    historyIntegrate();
    historyCutover();
    $test = test();
    $successor = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    expect($successor->session)->toBe($test->origin->session)->and($test->agents->sessions)->toHaveCount(1);
    historySubmit($successor);
    historySubmit(app(AdvanceTaskWorkspace::class)->handle($test->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    historyGit($test->worktree, ['add', '--all']);
    historyGit($test->worktree, ['commit', '-m', 'Accepted Schedule implementation']);
    historySubmit($commit);
    historySubmit(app(AdvanceTaskWorkspace::class)->handle($test->workspace), 'pass');
    $test->workspace->refresh();
    $run = $test->child->fresh()->acceptedRun()->firstOrFail();
    $review = $run->reviews()->sole();
    app(TaskLandingReattempt::class)->accepted($run, $review);

    return ['reattempt' => app(TaskLandingReattempt::class)->ledger($test->workspace, $test->child->fresh()),
        'accepted_tasks' => [['commit_sha' => $run->commit_sha, 'tree_sha' => $review->tree_sha, 'base_sha' => $run->base_sha]]];
}

it('preserves the authored merge history and both dirty trees through dispatch acceptance and landing proof', function () {
    historyFixture();
    $origin = $this->origin->fresh()->toArray();
    $docs = File::get($this->worktree.'/schedules.md');
    $indexDocs = historyGit($this->worktree, ['show', ':schedules.md']);
    $database = historyComplete();
    $before = historySnapshot();
    $proof = app(TaskLandingReattempt::class)->proof($this->workspace, $database);
    expect(historySnapshot())->toBe($before)
        ->and($this->checkpoint->request)->toBe(historyRequest())
        ->and($this->origin->fresh()->toArray())->toBe($origin)
        ->and($this->origin->run()->firstOrFail()->base_sha)->toBe($this->originalBase)
        ->and($this->origin->run()->firstOrFail()->status)->toBe(TaskRunStatus::Failed)
        ->and($this->workspace->base_sha)->toBe($this->originalBase)
        ->and($database['reattempt']['base_sha'])->toBe($this->integration)->not->toBe($this->verifiedMain)
        ->and($proof['accepted_commits'][0]['parent'])->toBe($this->integration)
        ->and(historyGit($this->worktree, ['show', '-s', '--format=%P', $this->integration]))->toBe($this->originalBase.' '.$this->verifiedMain)
        ->and(historyGit($this->worktree, ['show', '-s', '--format=%P', $this->originalBase]))->toBe($this->docsCommit.' '.$this->priorMain)
        ->and(historyGit($this->worktree, ['merge-base', '--is-ancestor', $this->docsCommit, 'HEAD']))->toBe('')
        ->and(File::get($this->worktree.'/schedules.md'))->toBe($docs)
        ->and(historyGit($this->worktree, ['show', $this->audit->observation['index_tree'].':schedules.md']))->toBe($indexDocs)
        ->and(File::get($this->worktree.'/command.php'))->toContain('Untracked task implementation')
        ->and(File::get($this->worktree.'/fixture.txt'))->toBe("reviewed prerequisite repair\n")
        ->and($this->root->fresh()->status)->toBe(TaskStatus::Completed);
});

it('previews the history-preserving route without persistent mutations and applies identical requests only once', function () {
    historyFixture();
    config(['task-runtime.enabled' => false]);
    $before = historySnapshot();
    $preview = historyPrepare();
    expect(historySnapshot())->toBe($before);
    config(['task-runtime.enabled' => true]);
    historyCheckpoint();
    expect(historyPrepare(state: $preview['state_hash'], apply: true)['applied'])->toBeFalse();
    historyIntegrate();
    config(['task-runtime.enabled' => false]);
    $before = historySnapshot();
    $preview = historyApply();
    expect(historySnapshot())->toBe($before);
    config(['task-runtime.enabled' => true]);
    historyCutover();
    expect(historyApply($preview['state_hash'], true)['applied'])->toBeFalse()
        ->and(TaskRun::query()->count())->toBe(2)->and(TaskAgentDispatch::query()->count())->toBe(2);
});

it('does not silently reinterpret the old request or accept another integration strategy', function (mixed $strategy) {
    historyFixture();
    $request = historyRequest();
    if ($strategy === 'omitted') {
        unset($request['integration']);
        expect(app(TaskReattemptInput::class)->checkpoint($request, $this->worktree))->toBe($request);
    } else {
        $request['integration'] = $strategy;
    }
    $before = historySnapshot();
    expect(fn () => historyPrepare($request))->toThrow(Exception::class)->and(historySnapshot())->toBe($before);
})->with(['omitted', 'main', 'rebase', null, true]);

function historyForgedCommit(string $defect): string
{
    $test = test();
    $tree = historyGit($test->worktree, ['rev-parse', $test->integration.'^{tree}']);
    $parents = [$test->originalBase, $test->verifiedMain];
    match ($defect) {
        'wrong first parent' => $parents[0] = $test->docsCommit,
        'wrong second parent' => $parents[1] = $test->repairCandidate,
        'reversed parents' => $parents = array_reverse($parents),
        'extra parent' => $parents[] = $test->priorMain,
        'missing history' => $parents = [$test->verifiedMain],
        'missing docs tree' => $tree = historyGit($test->worktree, ['rev-parse', $test->verifiedMain.'^{tree}']),
        'extra source' => $tree = historyGit($test->worktree, ['mktree'],
            historyGit($test->worktree, ['ls-tree', $test->integration])."\n100644 blob "
            .historyGit($test->worktree, ['hash-object', '-w', '--stdin'], 'unapproved source')."\tunapproved.php\n"),
    };
    $arguments = ['commit-tree', $tree];
    foreach ($parents as $parent) {
        $arguments = [...$arguments, '-p', $parent];
    }

    return historyGit($test->worktree, $arguments, "Forged disposable integration\n");
}

it('refuses forged integration parents and trees at cutover without releasing the blocked attempt', function (string $defect) {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    $forged = historyForgedCommit($defect);
    historyGit($this->worktree, ['update-ref', 'HEAD', $forged, $this->integration]);
    $before = historySnapshot();
    expect(fn () => historyApply())->toThrow(RuntimeException::class, 'integration commit')
        ->and(historySnapshot())->toBe($before)->and(TaskRun::query()->count())->toBe(1)
        ->and($this->workspace->fresh()->attention)->not->toBeNull();
})->with(['wrong first parent', 'wrong second parent', 'reversed parents', 'extra parent', 'missing history', 'missing docs tree', 'extra source']);

it('refuses authored-history merge conflicts before checkpointing or writing merge objects', function () {
    historyFixture(conflict: true);
    $before = historySnapshot();
    expect(fn () => historyPrepare())->toThrow(RuntimeException::class)->and(historySnapshot())->toBe($before);
});

it('refuses a manually resolved integration even with the exact ordered parents', function () {
    historyFixture(conflict: true);
    $tree = historyGit($this->worktree, ['rev-parse', $this->originalBase.'^{tree}']);
    $resolved = historyGit($this->worktree, ['commit-tree', $tree, '-p', $this->originalBase, '-p', $this->verifiedMain], "Manual resolution\n");
    $before = historySnapshot();
    expect(fn () => app(TaskReattemptGit::class)->integration($this->worktree, $this->originalBase, $this->verifiedMain, $resolved))
        ->toThrow(RuntimeException::class)->and(historySnapshot())->toBe($before);
});

it('refuses external merge drivers during history preservation and independent integration validation', function () {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    historyGit($this->repository, ['config', 'merge.custom.driver', 'false']);
    $before = historySnapshot();
    expect(fn () => historyApply())->toThrow(RuntimeException::class, 'external merge drivers')
        ->and(fn () => app(TaskReattemptGit::class)->integration($this->worktree, $this->originalBase, $this->verifiedMain, $this->integration))
        ->toThrow(RuntimeException::class, 'external merge drivers')->and(historySnapshot())->toBe($before);
});

it('refuses conflicting dirty patches and extra restored source on a valid integration commit', function (string $defect) {
    historyFixture();
    if ($defect === 'dirty conflict') {
        File::put($this->worktree.'/fixture.txt', "dirty competing fixture change\n");
    }
    historyCheckpoint();
    historyIntegrate(restore: $defect !== 'dirty conflict');
    if ($defect === 'extra source') {
        File::put($this->worktree.'/extra.txt', 'Unapproved restoration edit.');
    }
    $before = historySnapshot();
    expect(fn () => historyApply())->toThrow(Exception::class)->and(historySnapshot())->toBe($before);
})->with(['dirty conflict', 'extra source']);

it('refuses changed state or prerequisite evidence between preview and apply', function (string $stage, string $defect) {
    historyFixture();
    $request = historyRequest();
    if ($stage === 'cutover') {
        historyCheckpoint();
        historyIntegrate();
        $preview = historyApply();
    } else {
        $preview = historyPrepare();
    }
    match ($defect) {
        'state' => File::append($this->worktree.'/schedules.md', "Unapproved drift.\n"),
        'main' => historyGit($this->repository, ['commit', '--allow-empty', '-m', 'Later main']),
        'review' => File::append($this->historyDirectory.'/review.log', 'Changed review.'),
    };
    $before = historySnapshot();
    expect(fn () => $stage === 'cutover' ? historyApply($preview['state_hash'], true) : historyPrepare($request, $preview['state_hash'], true))
        ->toThrow(Exception::class)->and(historySnapshot())->toBe($before);
})->with(['checkpoint', 'cutover'])->with(['state', 'main', 'review']);

it('rechecks integration provenance before prompting even if the cutover observation and run base were corrupted together', function (string $defect) {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    historyCutover();
    $forged = historyForgedCommit($defect);
    historyGit($this->worktree, ['update-ref', 'HEAD', $forged, $this->integration]);
    $observation = $this->audit->observation;
    $observation['head'] = $forged;
    DB::table('task_reattempts')->where('id', $this->audit->id)->update(['observation' => json_encode($observation, JSON_THROW_ON_ERROR)]);
    DB::table('task_runs')->where('id', $this->audit->task_run_id)->update(['base_sha' => $forged]);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(RuntimeException::class, 'integration commit')
        ->and($this->agents->prompts)->toHaveCount(1)->and($this->agents->sessions)->toHaveCount(1)
        ->and(TaskAgentDispatch::query()->findOrFail($this->audit->task_agent_dispatch_id)->state)->toBe('ambiguous');
})->with(['wrong first parent', 'missing docs tree']);

it('refuses an equivalent-tree integration substitution whose cutover hash names the original commit', function () {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    historyCutover();
    $tree = historyGit($this->worktree, ['rev-parse', $this->integration.'^{tree}']);
    $replacement = historyGit($this->worktree, ['commit-tree', $tree, '-p', $this->originalBase, '-p', $this->verifiedMain], "Different integration identity\n");
    expect($replacement)->not->toBe($this->integration);
    historyGit($this->worktree, ['update-ref', 'HEAD', $replacement, $this->integration]);
    $observation = $this->audit->observation;
    $observation['head'] = $replacement;
    DB::table('task_reattempts')->where('id', $this->audit->id)->update(['observation' => json_encode($observation, JSON_THROW_ON_ERROR)]);
    DB::table('task_runs')->where('id', $this->audit->task_run_id)->update(['base_sha' => $replacement]);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(LogicException::class, 'request identity changed')
        ->and($this->agents->prompts)->toHaveCount(1)->and($this->agents->sessions)->toHaveCount(1)
        ->and(TaskAgentDispatch::query()->findOrFail($this->audit->task_agent_dispatch_id)->state)->toBe('ambiguous');
});

it('refuses independently corrupted checkpoint or cutover hashes before prompting', function (string $record) {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    historyCutover();
    DB::table($record === 'checkpoint' ? 'task_reattempt_checkpoints' : 'task_reattempts')
        ->where('id', $record === 'checkpoint' ? $this->checkpoint->id : $this->audit->id)
        ->update(['request_hash' => str_repeat('0', 64)]);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(Exception::class)
        ->and($this->agents->prompts)->toHaveCount(1)->and($this->agents->sessions)->toHaveCount(1)
        ->and(TaskAgentDispatch::query()->findOrFail($this->audit->task_agent_dispatch_id)->state)->toBe('ambiguous');
})->with(['checkpoint', 'cutover']);

it('rechecks the pinned upstream before sending the history-preserving successor', function () {
    historyFixture();
    historyCheckpoint();
    historyIntegrate();
    historyCutover();
    historyGit($this->repository, ['commit', '--allow-empty', '-m', 'Later upstream']);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(RuntimeException::class, 'Authoritative main')
        ->and($this->agents->prompts)->toHaveCount(1)->and($this->agents->sessions)->toHaveCount(1);
});

it('rejects missing rebound and symbolic retained refs for history-preserving cutover dispatch and landing', function (string $stage, string $defect) {
    historyFixture();
    $database = null;
    if ($stage === 'landing') {
        $database = historyComplete();
    } else {
        historyCheckpoint();
        historyIntegrate();
        if ($stage === 'dispatch') {
            historyCutover();
        }
    }
    $ref = 'refs/commander/task-reattempts/'.$this->checkpoint->request_hash.'/tree';
    match ($defect) {
        'missing' => historyGit($this->worktree, ['update-ref', '-d', $ref]),
        'rebound' => historyGit($this->worktree, ['update-ref', $ref, historyGit($this->worktree, ['rev-parse', $this->verifiedMain.'^{tree}'])]),
        'symbolic' => (function () use ($ref) {
            historyGit($this->worktree, ['update-ref', 'refs/fixture/alias', $this->checkpoint->observation['tree']]);
            historyGit($this->worktree, ['symbolic-ref', $ref, 'refs/fixture/alias']);
        })(),
    };
    $prompts = $this->agents->prompts;
    expect(fn () => match ($stage) {
        'cutover' => historyApply(),
        'dispatch' => app(AdvanceTaskWorkspace::class)->handle($this->workspace),
        'landing' => app(TaskLandingReattempt::class)->proof($this->workspace, $database),
    })->toThrow(RuntimeException::class, 'retained checkpoint')->and($this->agents->prompts)->toBe($prompts);
})->with(['cutover', 'dispatch', 'landing'])->with(['missing', 'rebound', 'symbolic']);

it('proves the integration again during landing without substituting later main or trusting recorded trees', function (string $defect) {
    historyFixture();
    $database = historyComplete();
    $forged = historyForgedCommit($defect);
    $database['reattempt']['cutover']['observation']['head'] = $forged;
    $before = historySnapshot();
    expect(fn () => app(TaskLandingReattemptGit::class)->proof($this->workspace, $database['reattempt'], $database['accepted_tasks']))
        ->toThrow(RuntimeException::class, 'integration commit')->and(historySnapshot())->toBe($before);
})->with(['wrong first parent', 'wrong second parent', 'reversed parents', 'extra parent', 'missing history', 'missing docs tree', 'extra source']);

it('allows later upstream advancement at landing while keeping the original audited integration base', function () {
    historyFixture();
    $database = historyComplete();
    historyGit($this->repository, ['commit', '--allow-empty', '-m', 'Later unrelated upstream']);
    $proof = app(TaskLandingReattempt::class)->proof($this->workspace, $database);
    expect($proof['accepted_commits'][0]['parent'])->toBe($this->integration);
});

it('packages the completed history-preserving run through the real landing action', function () {
    historyFixture();
    historyComplete();
    $candidate = historyGit($this->worktree, ['rev-parse', 'HEAD']);
    $tree = historyGit($this->worktree, ['rev-parse', 'HEAD^{tree}']);
    $checks = [];
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        foreach ([['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']] as $command) {
            $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
        }
    }
    $gate = $this->repository.'/.git/orbit-checks/'.$candidate.'/builder/result.json';
    File::makeDirectory(dirname($gate), recursive: true);
    $contents = TaskLandingData::json(['schema' => 1, 'role' => 'builder', 'candidate' => $candidate, 'tree' => $tree,
        'worktree' => $this->worktree, 'passed' => true, 'unchanged' => true, 'checks' => $checks]);
    File::put($gate, $contents);
    $repository = new class(['candidate' => $candidate, 'tree' => $tree, 'gate_path' => $gate, 'gate_sha256' => hash('sha256', $contents), 'gate_contents' => $contents, 'flow_contents' => TaskLandingData::json(['schema' => 1, 'flow' => 'discovery'])]) implements TaskLandingRepository
    {
        public ?array $published = null;

        public int $publications = 0;

        public function __construct(private array $observation) {}

        public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
        {
            app(OrbitCandidateReceipt::class)->validate($gate, new PreparedWorktree($workspace->worktree, $candidate),
                $this->observation['tree'], $workspace->worktree, $workspace->repository.'/.git');

            return $this->observation;
        }

        public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
        {
            if ($this->published !== null && $this->published !== $inputs) {
                throw new LogicException('Changed disposable package.');
            }

            return $this->published === null ? null : str_repeat('e', 40);
        }

        public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
        {
            $this->publications++;
            $this->published = $inputs;
        }
    };
    app()->instance(TaskLandingRepository::class, $repository);
    $issueId = '22222222-2222-4222-8222-222222222222';
    $payload = ['id' => $issueId, 'identifier' => 'ORB-990071', 'title' => 'Schedule CLI', 'description' => 'Implement Schedule CLI.',
        'team' => ['id' => config('commander.hermes.orbit_linear_team_id')], 'state' => ['name' => 'In Review', 'type' => 'started'], 'delegate' => null, 'assignee' => null];
    foreach (['labels', 'attachments', 'children', 'inverseRelations'] as $key) {
        $payload[$key] = ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
    }
    $issue = new OrbitIssueSnapshot($issueId, 'ORB-990071', $payload, str_repeat('d', 64));
    app()->instance(OrbitIssueReader::class, new class($issue) implements OrbitIssueReader
    {
        public function __construct(private OrbitIssueSnapshot $issue) {}

        public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
        {
            return $this->issue;
        }
    });
    $request = ['candidate' => $candidate, 'manifest' => app(TaskRuntimePlan::class)->hash($this->root->refresh()),
        'final_dispatch' => $this->workspace->dispatches()->latest('id')->firstOrFail()->id, 'issue_id' => $issueId,
        'gate_receipt' => $gate, 'pull_request_body' => 'Schedule CLI implemented and reviewed; authored history preserved.'];
    $before = historySnapshot();
    $preview = app(PrepareTaskLanding::class)->handle($this->workspace->id, $request, true);
    expect(historySnapshot())->toBe($before)
        ->and($preview['inputs']['database']['reattempt']['admission_base'])->toBe($this->originalBase)
        ->and($preview['inputs']['database']['accepted_tasks'][0]['base_sha'])->toBe($this->integration)
        ->and($preview['inputs']['reattempt_proof']['accepted_commits'][0]['parent'])->toBe($this->integration);
    $result = app(PrepareTaskLanding::class)->handle($this->workspace->id, $request, true, $preview['proposal_hash'], true);
    expect($result['landing']['state'])->toBe('packaged')->and($repository->publications)->toBe(1)
        ->and($result['landing']['inputs']['database']['reattempt']['checkpoint']['request']['integration'])->toBe('preserve_history')
        ->and(historySnapshot())->toBe($before);
});
