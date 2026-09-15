<?php

use App\Jobs\AdvanceTaskRunner;
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
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ApplyTaskReattempt;
use App\Tasks\Runtime\GitTaskWorktree;
use App\Tasks\Runtime\PrepareTaskReattempt;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskReattemptGit;
use App\Tasks\Runtime\TaskReattemptInput;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class ReattemptFakeAgents implements TaskAgents
{
    public array $starts = [];

    public array $prompts = [];

    public bool $missing = false;

    public bool $failPrompt = false;

    public ?Closure $duringObservation = null;

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        if ($this->duringObservation !== null) {
            ($this->duringObservation)($workspace);
        }
        if ($this->missing || $session !== $this->starts[0]) {
            throw new LogicException('The retained worker session changed.');
        }
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        $session = ['workspaceId' => 'workspace', 'tabId' => 'tab', 'paneId' => 'pane-'.$name,
            'terminalId' => 'terminal-'.$name, 'agentName' => $name, 'agentId' => 'conversation-'.$name,
            'workingDirectory' => $workspace->worktree];
        $this->starts[] = $session;

        return $session;
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = compact('session', 'prompt');
        if ($this->failPrompt) {
            throw new RuntimeException('Unknown prompt outcome.');
        }

        return $session;
    }
}

beforeEach(function () {
    $this->reattemptDirectory = trim(Process::run(['mktemp', '-d', sys_get_temp_dir().'/task-reattempt-test-XXXXXX'])->throw()->output());
    $this->repository = $this->reattemptDirectory.'/repository';
    $this->worktree = $this->reattemptDirectory.'/worktrees/feature';
    File::makeDirectory($this->repository, recursive: true);
    File::makeDirectory(dirname($this->worktree));
    File::makeDirectory($this->reattemptDirectory.'/projects');
    config(['commander.projects_path' => $this->reattemptDirectory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    reattemptGit($this->repository, ['init', '--initial-branch=main']);
    reattemptGit($this->repository, ['config', 'user.email', 'reattempt@example.test']);
    reattemptGit($this->repository, ['config', 'user.name', 'Reattempt Test']);
    reattemptGit($this->repository, ['remote', 'add', 'origin', $this->repository]);
    File::put($this->repository.'/.gitignore', ".env\nvendor/\n");
    File::put($this->repository.'/feature.txt', "original\n");
    File::put($this->repository.'/fixture.txt', "broken fixture\n");
    File::put($this->repository.'/removed.txt', "remove me\n");
    reattemptGit($this->repository, ['add', '--all']);
    reattemptGit($this->repository, ['commit', '-m', 'Base']);
    $this->originalBase = trim(reattemptGit($this->repository, ['rev-parse', 'HEAD']));
    reattemptGit($this->repository, ['worktree', 'add', '-b', 'feature', $this->worktree]);
    config(['task-runtime.enabled' => true, 'task-runtime.projects.orbit' => [
        'repository' => $this->repository, 'worktree_root' => dirname($this->worktree), 'socket' => '/unused-test.sock',
        'agent_kind' => 'codex', 'agent_arguments' => [], 'flow_version' => 1, 'instructions' => 'Automated-only; preserve database safety and exact candidate gate.',
        'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 5,
    ]]);
    $this->agents = new ReattemptFakeAgents;
    app()->instance(TaskAgents::class, $this->agents);
    $this->root = app(CreateTask::class)->handle('orbit', 'Database safety', 'Deliver the safety change.', TaskKind::Group, acceptanceCriteria: 'Database safety and exact candidate gate.');
    $this->child = app(CreateTask::class)->handle('orbit', 'Safety implementation', 'Implement database safety.', parent: $this->root, acceptanceCriteria: 'Safety tests pass.');
    $this->second = app(CreateTask::class)->handle('orbit', 'Follow-on integration', 'Integrate the accepted safety change.', parent: $this->root, acceptanceCriteria: 'The accepted chain remains intact.');
    $this->workspace = app(StartTaskWorkspace::class)->handle($this->root, $this->worktree, app(TaskRuntimePlan::class)->hash($this->root), 'ORB-FEATURE', true);
    $this->origin = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    File::put($this->worktree.'/feature.txt', "task implementation\n");
    reattemptSubmit($this->origin, 'blocked');
    reattemptGit($this->repository, ['checkout', '-b', 'repair']);
    File::put($this->repository.'/fixture.txt', "reviewed repaired fixture\n");
    reattemptGit($this->repository, ['add', '--all']);
    reattemptGit($this->repository, ['commit', '-m', 'Separate prerequisite repair']);
    $this->repairCandidate = trim(reattemptGit($this->repository, ['rev-parse', 'HEAD']));
    reattemptGit($this->repository, ['checkout', 'main']);
    reattemptGit($this->repository, ['merge', '--no-ff', '--no-edit', 'repair']);
    $this->verifiedMain = trim(reattemptGit($this->repository, ['rev-parse', 'HEAD']));
    File::put($this->reattemptDirectory.'/review.log', 'Independent review of '.$this->repairCandidate.' passed.');
    File::put($this->reattemptDirectory.'/main.log', 'Main verification at '.$this->verifiedMain.' passed, exit 0.');
    File::put($this->reattemptDirectory.'/restore.log', 'Preserved task patch restored without conflicts.');
});

afterEach(fn () => File::deleteDirectory($this->reattemptDirectory));

function reattemptGit(string $directory, array $arguments, ?string $input = null): string
{
    return Process::path($directory)->timeout(10)->input($input)->env([
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0',
    ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output();
}

function reattemptSubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): void
{
    $receipt = ['token' => $dispatch->handoff_token, 'summary' => 'Fixture prerequisite blocks checks.', 'evidence' => 'Exact retained test results.'];
    if ($verdict !== null) {
        $receipt['verdict'] = $verdict;
    }
    app(SubmitTaskDispatch::class)->handle($dispatch, $receipt);
}

function reattemptLog(string $name): array
{
    $path = test()->reattemptDirectory.'/'.$name.'.log';

    return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
}

function reattemptRequest(): array
{
    return ['reason' => 'Separate baseline repair now merged and verified.', 'evidence' => 'The original blocked result remains valid history.',
        'prerequisite' => ['source' => 'ORB-REPAIR', 'pull_request' => 'https://example.test/pulls/249',
            'candidate' => test()->repairCandidate, 'merge' => test()->verifiedMain, 'main' => test()->verifiedMain,
            'review' => reattemptLog('review'), 'verification' => ['head' => test()->verifiedMain,
                'command' => ['composer', 'test:affected'], 'working_directory' => test()->repository,
                'exit_code' => 0, 'log' => reattemptLog('main')]]];
}

function reattemptRestoration(): array
{
    return ['reason' => 'Resume only the preserved task on verified upstream.', 'evidence' => 'Pristine upstream integrated; both task trees restored.', 'log' => reattemptLog('restore')];
}

function reattemptCheckpoint(): TaskReattemptCheckpoint
{
    $test = test();
    $action = app(PrepareTaskReattempt::class);
    $preview = $action->handle($test->workspace->id, $test->origin->id, $test->originalBase, $test->workspace->manifest_hash, reattemptRequest(), true);
    $result = $action->handle($test->workspace->id, $test->origin->id, $test->originalBase, $test->workspace->manifest_hash, reattemptRequest(), true, $preview['state_hash'], true);

    return TaskReattemptCheckpoint::query()->findOrFail($result['checkpoint']['id']);
}

function reattemptRestore(TaskReattemptCheckpoint $checkpoint): void
{
    $test = test();
    // Only the per-test disposable repository is changed here.
    reattemptGit($test->worktree, ['read-tree', '--reset', '-u', $test->verifiedMain]);
    reattemptGit($test->worktree, ['update-ref', 'HEAD', $test->verifiedMain, $test->originalBase]);
    foreach (explode("\0", reattemptGit($test->worktree, ['ls-files', '--others', '--exclude-standard', '-z'])) as $path) {
        if ($path !== '') {
            File::delete($test->worktree.'/'.$path);
        }
    }
    $patch = reattemptGit($test->worktree, ['diff', '--binary', '--full-index', $test->originalBase, $checkpoint->observation['tree']]);
    if ($patch !== '') {
        reattemptGit($test->worktree, ['apply', '--binary'], $patch);
    }
    $indexPatch = reattemptGit($test->worktree, ['diff', '--binary', '--full-index', $test->originalBase, $checkpoint->observation['index_tree']]);
    if ($indexPatch !== '') {
        reattemptGit($test->worktree, ['apply', '--cached', '--binary'], $indexPatch);
    }
}

function reattemptApply(TaskReattemptCheckpoint $checkpoint): array
{
    $action = app(ApplyTaskReattempt::class);
    $preview = $action->handle($checkpoint->id, reattemptRestoration(), true);

    return $action->handle($checkpoint->id, reattemptRestoration(), true, $preview['state_hash'], true);
}

it('reattempts the first blocked child on verified upstream with the same session and fixed new base', function () {
    $oldRun = $this->origin->run()->firstOrFail();
    $receipt = $this->origin->fresh()->toArray();
    $workspace = $this->workspace->fresh()->toArray();
    $checkpoint = reattemptCheckpoint();
    expect($this->workspace->fresh()->attention)->not->toBeNull()->and($this->agents->prompts)->toHaveCount(1);
    reattemptRestore($checkpoint);
    $result = reattemptApply($checkpoint);
    $run = TaskRun::query()->findOrFail($result['audit']['task_run_id']);
    expect($oldRun->fresh()->status)->toBe(TaskRunStatus::Failed)->and($oldRun->fresh()->base_sha)->toBe($this->originalBase)
        ->and($this->origin->fresh()->toArray())->toBe($receipt)->and($run->base_sha)->toBe($this->verifiedMain)
        ->and($run->worker_ref)->toBe($oldRun->worker_ref)->and($run->reviewer_ref)->toBe($oldRun->reviewer_ref)
        ->and($this->workspace->fresh()->base_sha)->toBe($workspace['base_sha'])
        ->and($this->workspace->fresh()->manifest_hash)->toBe($workspace['manifest_hash'])
        ->and($this->workspace->fresh()->configuration)->toBe($workspace['configuration']);
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($dispatch->session)->toBe($receipt['session'])->and($dispatch->token_hash)->not->toBe($this->origin->token_hash)
        ->and($dispatch->round)->toBe(0)->and($this->agents->starts)->toHaveCount(1)
        ->and($dispatch->prompt)->toContain('reattempt_checkpoint', $this->verifiedMain, 'Automated-only');
    reattemptSubmit($dispatch);
    $review = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    reattemptSubmit($review, 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    reattemptGit($this->worktree, ['add', '--all']);
    reattemptGit($this->worktree, ['commit', '-m', 'Accepted feature only']);
    reattemptSubmit($commit);
    expect($this->child->fresh()->status)->toBe(TaskStatus::Completed)
        ->and(trim(reattemptGit($this->worktree, ['show', '--format=', '--name-only', 'HEAD'])))->toBe('feature.txt');
    $next = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($next->run()->firstOrFail()->base_sha)->toBe($run->fresh()->commit_sha)
        ->and($next->run()->firstOrFail()->worker_ref)->not->toBe($run->worker_ref);
    File::put($this->worktree.'/second.txt', 'Follow-on integration.');
    reattemptSubmit($next);
    reattemptSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    reattemptGit($this->worktree, ['add', '--all']);
    reattemptGit($this->worktree, ['commit', '-m', 'Accepted follow-on task']);
    reattemptSubmit($commit);
    $final = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    reattemptSubmit($final, 'pass');
    expect($this->root->fresh()->status)->toBe(TaskStatus::Completed);
});

it('previews both stages without persistent Git database dispatch or agent changes', function () {
    $before = $this->workspace->fresh()->toArray();
    $objects = reattemptGit($this->repository, ['count-objects', '-v']);
    $refs = reattemptGit($this->repository, ['show-ref']);
    $index = hash_file('sha256', trim(reattemptGit($this->worktree, ['rev-parse', '--git-path', 'index'])));
    config(['task-runtime.enabled' => false]);
    $preview = app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, reattemptRequest(), true);
    expect($preview['recorded'])->toBeFalse()->and(TaskReattemptCheckpoint::query()->count())->toBe(0)
        ->and($this->workspace->fresh()->toArray())->toBe($before)->and(reattemptGit($this->repository, ['count-objects', '-v']))->toBe($objects)
        ->and(reattemptGit($this->repository, ['show-ref']))->toBe($refs)
        ->and(hash_file('sha256', trim(reattemptGit($this->worktree, ['rev-parse', '--git-path', 'index']))))->toBe($index);
    config(['task-runtime.enabled' => true]);
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $objects = reattemptGit($this->repository, ['count-objects', '-v']);
    $refs = reattemptGit($this->repository, ['show-ref']);
    config(['task-runtime.enabled' => false]);
    expect(app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true)['recorded'])->toBeFalse()
        ->and(TaskReattempt::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(1)
        ->and($this->workspace->dispatches()->count())->toBe(1)->and($this->agents->starts)->toHaveCount(1)
        ->and($this->agents->prompts)->toHaveCount(1)->and(reattemptGit($this->repository, ['count-objects', '-v']))->toBe($objects)
        ->and(reattemptGit($this->repository, ['show-ref']))->toBe($refs);
});

it('retains staged unstaged untracked binary modes symlinks deletions and staged ignored bytes', function () {
    File::put($this->worktree.'/feature.txt', "staged task\n");
    File::put($this->worktree.'/.env', 'explicitly staged test fixture');
    reattemptGit($this->worktree, ['add', '--force', 'feature.txt', '.env']);
    File::put($this->worktree.'/feature.txt', "unstaged task\n");
    File::put($this->worktree.'/binary.dat', "\0\xff\x01");
    File::put($this->worktree.'/executable', 'task');
    chmod($this->worktree.'/executable', 0755);
    symlink('feature.txt', $this->worktree.'/link');
    File::delete($this->worktree.'/removed.txt');
    $checkpoint = reattemptCheckpoint();
    expect($checkpoint->observation['tree'])->not->toBe($checkpoint->observation['index_tree']);
    expect(trim(reattemptGit($this->worktree, ['show', $checkpoint->observation['tree'].':feature.txt'])))->toBe('unstaged task')
        ->and(trim(reattemptGit($this->worktree, ['show', $checkpoint->observation['index_tree'].':feature.txt'])))->toBe('staged task')
        ->and(reattemptGit($this->worktree, ['ls-tree', '-r', $checkpoint->observation['tree']]))->toContain('100755', '120000', 'binary.dat', '.env')->not->toContain('removed.txt');
    reattemptRestore($checkpoint);
    expect(reattemptApply($checkpoint)['applied'])->toBeTrue()
        ->and(File::get($this->worktree.'/binary.dat'))->toBe("\0\xff\x01")
        ->and(readlink($this->worktree.'/link'))->toBe('feature.txt')
        ->and(File::get($this->worktree.'/.env'))->toBe('explicitly staged test fixture');
});

it('rejects changed checkpoint pins and leaves the held attempt untouched', function (string $case) {
    $request = reattemptRequest();
    $preview = app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, true);
    $head = $this->originalBase;
    $manifest = $this->workspace->manifest_hash;
    $exclusive = true;
    match ($case) {
        'tree' => File::put($this->worktree.'/feature.txt', 'drift'),
        'index' => reattemptGit($this->worktree, ['add', 'feature.txt']),
        'head' => $head = $this->verifiedMain,
        'manifest' => $manifest = str_repeat('c', 64),
        'owner' => $exclusive = false,
        'session' => $this->agents->missing = true,
        'hold' => $this->workspace->refresh()->update(['attention' => 'Another hold.']),
        'disabled' => config(['task-runtime.enabled' => false]),
        'evidence' => File::put($this->reattemptDirectory.'/main.log', 'changed'),
    };
    expect(fn () => app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $head, $manifest, $request, $exclusive, $preview['state_hash'], true))->toThrow(Exception::class)
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(1)
        ->and($this->origin->fresh()->result['verdict'])->toBe('blocked')->and($this->agents->prompts)->toHaveCount(1);
})->with(['tree', 'index', 'head', 'manifest', 'owner', 'session', 'hold', 'disabled', 'evidence']);

it('rejects missing or nonpassing or unmerged prerequisite evidence', function (string $case) {
    $request = reattemptRequest();
    match ($case) {
        'failed' => $request['prerequisite']['verification']['exit_code'] = 1,
        'wrong verification head' => $request['prerequisite']['verification']['head'] = $this->originalBase,
        'missing review' => $request['prerequisite']['review']['path'] = $this->reattemptDirectory.'/absent',
        'unmerged' => $request['prerequisite']['merge'] = $this->repairCandidate,
        'wrong candidate' => $request['prerequisite']['candidate'] = $this->originalBase,
        'same issue' => $request['prerequisite']['source'] = $this->workspace->source_key,
        'unknown' => $request['scope_override'] = true,
    };
    expect(fn () => app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, true))->toThrow(Exception::class)
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0);
})->with(['failed', 'wrong verification head', 'missing review', 'unmerged', 'wrong candidate', 'same issue', 'unknown']);

it('rejects extra restored changes without cutting over the original attempt', function () {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    File::put($this->worktree.'/fixture.txt', 'unreviewed repair alteration');
    expect(fn () => reattemptApply($checkpoint))->toThrow(LogicException::class, 'exact restored')
        ->and(TaskRun::query()->count())->toBe(1)->and($this->origin->run()->firstOrFail()->status)->toBe(TaskRunStatus::Running)
        ->and(TaskReattempt::query()->count())->toBe(0)->and($this->workspace->fresh()->attention)->not->toBeNull();
});

it('keeps checkpoints and cutovers immutable and identical retries idempotent', function () {
    $checkpoint = reattemptCheckpoint();
    $state = app(TaskReattemptInput::class)->hash($checkpoint->observation);
    $same = app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, reattemptRequest(), true, $state, true);
    expect($same['applied'])->toBeFalse();
    reattemptRestore($checkpoint);
    $result = reattemptApply($checkpoint);
    $audit = TaskReattempt::query()->findOrFail($result['audit']['id']);
    $state = app(TaskReattemptInput::class)->hash($audit->observation);
    expect(app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true, $state, true)['applied'])->toBeFalse()
        ->and(TaskRun::query()->count())->toBe(2)->and($this->workspace->dispatches()->count())->toBe(2)
        ->and(fn () => $audit->update(['request_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $checkpoint->delete())->toThrow(LogicException::class);
    $request = reattemptRestoration();
    $request['reason'] = 'Conflicting authority.';
    expect(fn () => app(ApplyTaskReattempt::class)->handle($checkpoint->id, $request, true, $state, true))->toThrow(LogicException::class, 'conflicting');
    reattemptSubmit($this->origin, 'blocked');
    expect($this->workspace->fresh()->attention)->toBeNull();
});

it('rolls back the entire cutover if recording its audit fails', function () {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    DB::statement("CREATE TRIGGER reject_reattempt BEFORE INSERT ON task_reattempts BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    expect(fn () => reattemptApply($checkpoint))->toThrow(Exception::class)
        ->and($this->origin->run()->firstOrFail()->status)->toBe(TaskRunStatus::Running)
        ->and($this->child->fresh()->status)->toBe(TaskStatus::Running)->and(TaskRun::query()->count())->toBe(1)
        ->and($this->workspace->dispatches()->count())->toBe(1)->and($this->workspace->fresh()->attention)->not->toBeNull();
});

it('reconciles before sending and never falls back to a new worker', function (string $case) {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $result = reattemptApply($checkpoint);
    match ($case) {
        'tree' => File::put($this->worktree.'/feature.txt', 'changed after cutover'),
        'session' => $this->agents->missing = true,
        'session record' => TaskAgentDispatch::query()->findOrFail($result['audit']['task_agent_dispatch_id'])->update(['session' => null]),
        'observation hold' => $this->agents->duringObservation = fn (TaskWorkspace $workspace) => $workspace->refresh()->update(['attention' => 'New operator hold.']),
        'evidence' => File::put($this->reattemptDirectory.'/main.log', 'changed after cutover'),
    };
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(Exception::class)
        ->and($this->agents->starts)->toHaveCount(1)->and($this->agents->prompts)->toHaveCount(1)
        ->and(TaskAgentDispatch::query()->findOrFail($result['audit']['task_agent_dispatch_id'])->state)->toBe('ambiguous');
})->with(['tree', 'session', 'session record', 'observation hold', 'evidence']);

it('does not resend ambiguous successor prompts or apply stale job failures to them', function () {
    $oldJob = new AdvanceTaskRunner($this->workspace->id);
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    reattemptApply($checkpoint);
    $this->agents->failPrompt = true;
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(RuntimeException::class);
    $oldJob->failed(null);
    expect(app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toBeNull()
        ->and($this->agents->starts)->toHaveCount(1)->and($this->agents->prompts)->toHaveCount(2);
});

it('refuses any origin outside the initial blocked implementation', function (string $case) {
    $run = $this->origin->run()->firstOrFail();
    match ($case) {
        'uncertain' => DB::table('task_agent_dispatches')->where('id', $this->origin->id)->update(['state' => 'ambiguous']),
        'review round' => DB::table('task_agent_dispatches')->where('id', $this->origin->id)->update(['round' => 1]),
        'review instruction' => DB::table('task_agent_dispatches')->where('id', $this->origin->id)->update(['kind' => 'review']),
        'final instruction' => DB::table('task_agent_dispatches')->where('id', $this->origin->id)->update(['kind' => 'final_review']),
        'accepted' => DB::table('tasks')->where('id', $this->child->id)->update(['accepted_task_run_id' => $run->id]),
        'finished run' => DB::table('task_runs')->where('id', $run->id)->update(['status' => 'failed']),
        'changed slot' => DB::table('task_runs')->where('id', $run->id)->update(['active_task_id' => null]),
        'another attempt' => DB::table('task_runs')->where('id', $run->id)->update(['attempt' => 2]),
    };
    expect(fn () => reattemptCheckpoint())->toThrow(LogicException::class, 'first blocked implementation')
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0)->and($this->agents->prompts)->toHaveCount(1);
})->with(['uncertain', 'review round', 'review instruction', 'final instruction', 'accepted', 'finished run', 'changed slot', 'another attempt']);

it('refuses conflicting restoration without implementing a conflict resolver', function () {
    File::put($this->worktree.'/fixture.txt', "task changed the same fixture line\n");
    $checkpoint = reattemptCheckpoint();
    reattemptGit($this->worktree, ['read-tree', '--reset', '-u', $this->verifiedMain]);
    reattemptGit($this->worktree, ['update-ref', 'HEAD', $this->verifiedMain, $this->originalBase]);
    expect(fn () => app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true))->toThrow(RuntimeException::class)
        ->and($this->origin->run()->firstOrFail()->status)->toBe(TaskRunStatus::Running)
        ->and(TaskReattempt::query()->count())->toBe(0)->and(File::get($this->worktree.'/fixture.txt'))->toBe("reviewed repaired fixture\n");
});

it('rejects external merge drivers without running them during preview', function () {
    reattemptGit($this->repository, ['config', 'merge.custom.driver', 'false']);
    expect(fn () => reattemptCheckpoint())->toThrow(RuntimeException::class, 'external merge drivers')
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0);
});

it('atomically coalesces overlapping identical cutovers observed before mutation', function () {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $preview = app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true);
    $this->agents->duringObservation = function () use ($checkpoint, $preview): void {
        $this->agents->duringObservation = null;
        app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true, $preview['state_hash'], true);
    };
    $outer = app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true, $preview['state_hash'], true);
    expect($outer['applied'])->toBeFalse()->and(TaskReattempt::query()->count())->toBe(1)
        ->and(TaskRun::query()->count())->toBe(2)->and($this->workspace->dispatches()->count())->toBe(2)
        ->and($this->agents->prompts)->toHaveCount(1);
});

it('rejects fresh-state changes between cutover preview and apply', function (string $case) {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $preview = app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true);
    match ($case) {
        'index' => reattemptGit($this->worktree, ['add', 'feature.txt']),
        'tree' => File::put($this->worktree.'/feature.txt', 'drift'),
        'session' => $this->agents->missing = true,
        'hold during observe' => $this->agents->duringObservation = fn (TaskWorkspace $workspace) => $workspace->refresh()->update(['attention' => 'New hold.']),
        'disabled' => config(['task-runtime.enabled' => false]),
    };
    expect(fn () => app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true, $preview['state_hash'], true))->toThrow(Exception::class)
        ->and(TaskRun::query()->count())->toBe(1)->and($this->origin->run()->firstOrFail()->status)->toBe(TaskRunStatus::Running)
        ->and(TaskReattempt::query()->count())->toBe(0);
})->with(['index', 'tree', 'session', 'hold during observe', 'disabled']);

it('rejects the old token on the successor and cannot replace its blocked receipt', function () {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    reattemptApply($checkpoint);
    $next = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    $receipt = ['token' => $this->origin->handoff_token, 'summary' => 'Replacement', 'evidence' => 'Claimed passing evidence'];
    expect(fn () => app(SubmitTaskDispatch::class)->handle($next, $receipt))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(SubmitTaskDispatch::class)->handle($this->origin, $receipt))->toThrow(LogicException::class, 'cannot be replaced')
        ->and($next->fresh()->state)->toBe('sent');
    app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($this->agents->prompts)->toHaveCount(2);
});

it('exposes preview apply and idempotent enqueue through the isolated CLI', function () {
    Queue::fake();
    $prepareFile = $this->reattemptDirectory.'/prepare.json';
    File::put($prepareFile, json_encode(reattemptRequest(), JSON_THROW_ON_ERROR));
    $arguments = ['workspace' => $this->workspace->id, '--dispatch' => $this->origin->id,
        '--head' => $this->originalBase, '--manifest' => $this->workspace->manifest_hash, '--file' => $prepareFile, '--exclusive' => true];
    expect(Artisan::call('tasks:prepare-reattempt', $arguments))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(Artisan::call('tasks:prepare-reattempt', [...$arguments, '--state' => $preview['state_hash'], '--apply' => true]))->toBe(0);
    $checkpoint = TaskReattemptCheckpoint::query()->firstOrFail();
    reattemptRestore($checkpoint);
    $file = $this->reattemptDirectory.'/restore.json';
    File::put($file, json_encode(reattemptRestoration(), JSON_THROW_ON_ERROR));
    $arguments = ['checkpoint' => $checkpoint->id, '--file' => $file, '--exclusive' => true];
    expect(Artisan::call('tasks:reattempt', [...$arguments, '--advance' => true]))->toBe(1)
        ->and(Artisan::call('tasks:reattempt', $arguments))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $arguments += ['--state' => $preview['state_hash'], '--apply' => true, '--advance' => true];
    expect(Artisan::call('tasks:reattempt', $arguments))->toBe(0)
        ->and(Artisan::call('tasks:reattempt', $arguments))->toBe(0);
    Queue::assertPushed(AdvanceTaskRunner::class, 1);
    expect(Artisan::call('tasks:inspect', ['project' => 'orbit', 'task' => $this->root->id]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('reattempt_checkpoint', 'reattempt', $this->verifiedMain)->not->toContain($this->origin->handoff_token);
});

it('retains a recorded reattempt when enqueue fails and permits normal advancement', function () {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $preview = app(ApplyTaskReattempt::class)->handle($checkpoint->id, reattemptRestoration(), true);
    $file = $this->reattemptDirectory.'/restore.json';
    File::put($file, json_encode(reattemptRestoration(), JSON_THROW_ON_ERROR));
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable.'));
    expect(Artisan::call('tasks:reattempt', ['checkpoint' => $checkpoint->id, '--file' => $file, '--exclusive' => true,
        '--state' => $preview['state_hash'], '--apply' => true, '--advance' => true]))->toBe(1)
        ->and(TaskReattempt::query()->count())->toBe(1)->and(TaskRun::query()->count())->toBe(2);
    expect(app(AdvanceTaskWorkspace::class)->handle($this->workspace)->state)->toBe('sent')
        ->and($this->agents->starts)->toHaveCount(1);
});

function reattemptCorruptRetention(TaskReattemptCheckpoint $checkpoint, string $kind, string $mode): void
{
    $test = test();
    $ref = 'refs/commander/task-reattempts/'.$checkpoint->request_hash.'/'.$kind;
    if ($mode === 'missing') {
        reattemptGit($test->worktree, ['update-ref', '-d', $ref]);
    } elseif ($mode === 'rebound') {
        $wrong = trim(reattemptGit($test->worktree, ['rev-parse', $test->verifiedMain.'^{tree}']));
        reattemptGit($test->worktree, ['update-ref', $ref, $wrong]);
    } else {
        $alias = 'refs/commander/retention-alias';
        reattemptGit($test->worktree, ['update-ref', $alias, $checkpoint->observation[$kind]]);
        reattemptGit($test->worktree, ['symbolic-ref', $ref, $alias]);
    }
}

it('refuses damaged retention refs in cutover preview and apply without releasing the hold', function (string $kind, string $mode) {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $action = app(ApplyTaskReattempt::class);
    $preview = $action->handle($checkpoint->id, reattemptRestoration(), true);
    $origin = $this->origin->fresh()->toArray();
    $hold = $this->workspace->fresh()->attention;
    reattemptCorruptRetention($checkpoint, $kind, $mode);
    $refs = reattemptGit($this->worktree, ['show-ref']);
    expect(fn () => $action->handle($checkpoint->id, reattemptRestoration(), true))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and(fn () => $action->handle($checkpoint->id, reattemptRestoration(), true, $preview['state_hash'], true))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and(TaskReattempt::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(1)
        ->and($this->workspace->fresh()->attention)->toBe($hold)->and($this->origin->fresh()->toArray())->toBe($origin)
        ->and(reattemptGit($this->worktree, ['show-ref']))->toBe($refs)->and($this->agents->prompts)->toHaveCount(1);
})->with(['tree', 'index_tree'])->with(['missing', 'rebound', 'symbolic']);

it('holds the successor without prompting when either retention ref is missing rebound or symbolic', function (string $kind, string $mode) {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    $result = reattemptApply($checkpoint);
    $origin = $this->origin->fresh()->toArray();
    reattemptCorruptRetention($checkpoint, $kind, $mode);
    $refs = reattemptGit($this->worktree, ['show-ref']);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and($this->agents->starts)->toHaveCount(1)->and($this->agents->prompts)->toHaveCount(1)
        ->and($this->workspace->fresh()->attention)->not->toBeNull()
        ->and(TaskAgentDispatch::query()->findOrFail($result['audit']['task_agent_dispatch_id'])->state)->toBe('ambiguous')
        ->and($this->origin->fresh()->toArray())->toBe($origin)->and(reattemptGit($this->worktree, ['show-ref']))->toBe($refs);
})->with(['tree', 'index_tree'])->with(['missing', 'rebound', 'symbolic']);

it('rechecks retention after the retained session observation and before prompting', function (string $kind) {
    $checkpoint = reattemptCheckpoint();
    reattemptRestore($checkpoint);
    reattemptApply($checkpoint);
    $this->agents->duringObservation = fn () => reattemptCorruptRetention($checkpoint, $kind, 'missing');
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($this->workspace))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and($this->agents->prompts)->toHaveCount(1)->and($this->workspace->fresh()->attention)->not->toBeNull();
})->with(['tree', 'index_tree']);

it('does not repair partial or conflicting retention publication on checkpoint retry', function (string $kind, string $mode) {
    DB::statement("CREATE TRIGGER reject_checkpoint BEFORE INSERT ON task_reattempt_checkpoints BEGIN SELECT RAISE(ABORT, 'checkpoint failed'); END");
    $action = app(PrepareTaskReattempt::class);
    $request = reattemptRequest();
    $preview = $action->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, true);
    $hash = app(TaskReattemptInput::class)->hash([$this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, $preview['state_hash']]);
    expect(fn () => $action->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, true, $preview['state_hash'], true))->toThrow(Exception::class);
    DB::statement('DROP TRIGGER reject_checkpoint');
    $checkpoint = new TaskReattemptCheckpoint(['request_hash' => $hash, 'observation' => $preview['observation']]);
    reattemptCorruptRetention($checkpoint, $kind, $mode);
    $refs = reattemptGit($this->worktree, ['show-ref']);
    expect(fn () => $action->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, $request, true, $preview['state_hash'], true))->toThrow(RuntimeException::class, 'retained checkpoint')
        ->and(reattemptGit($this->worktree, ['show-ref']))->toBe($refs)->and(TaskReattemptCheckpoint::query()->count())->toBe(0)
        ->and($this->workspace->fresh()->attention)->not->toBeNull();
})->with(['tree', 'index_tree'])->with(['missing', 'rebound']);

it('publishes neither retention ref when either create-only ref lock cannot be acquired', function (string $kind) {
    $identity = hash('sha256', 'publication lock fixture');
    $prefix = 'refs/commander/task-reattempts/'.$identity;
    $lock = trim(reattemptGit($this->worktree, ['rev-parse', '--path-format=absolute', '--git-path', $prefix.'/'.$kind.'.lock']));
    File::makeDirectory(dirname($lock), recursive: true);
    File::put($lock, 'Disposable competing fixture lock.');
    expect(fn () => app(TaskReattemptGit::class)->observe($this->repository, $this->worktree, $identity))->toThrow(RuntimeException::class)
        ->and(reattemptGit($this->worktree, ['for-each-ref', '--format=%(refname)', $prefix.'/']))->toBe('')
        ->and(File::get($lock))->toBe('Disposable competing fixture lock.')
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0)->and($this->workspace->fresh()->attention)->not->toBeNull();
})->with(['tree', 'index_tree']);

it('allows checkpoint retry after database failure only while both retained refs still match', function () {
    DB::statement("CREATE TRIGGER reject_checkpoint BEFORE INSERT ON task_reattempt_checkpoints BEGIN SELECT RAISE(ABORT, 'checkpoint failed'); END");
    expect(fn () => reattemptCheckpoint())->toThrow(Exception::class);
    $refs = reattemptGit($this->worktree, ['show-ref']);
    DB::statement('DROP TRIGGER reject_checkpoint');
    expect(reattemptCheckpoint()->exists)->toBeTrue()->and(reattemptGit($this->worktree, ['show-ref']))->toBe($refs);
});

it('refuses partial and promisor previews before missing object lookups without fetching', function (string $configuration, string $boundary) {
    $missing = $boundary === 'prerequisite' ? $this->repairCandidate : $this->originalBase;
    $remote = $this->reattemptDirectory.'/promisor.git';
    reattemptGit($this->repository, ['clone', '--bare', '--no-hardlinks', $this->repository, $remote]);
    reattemptGit($remote, ['config', 'uploadpack.allowFilter', 'true']);
    reattemptGit($this->repository, ['remote', 'set-url', 'origin', $remote]);
    reattemptGit($this->repository, ['config', $configuration, $configuration === 'extensions.partialClone' ? 'origin' : 'true']);
    $object = $this->repository.'/.git/objects/'.substr($missing, 0, 2).'/'.substr($missing, 2);
    expect(is_file($object))->toBeTrue();
    File::delete($object);
    $before = reattemptGit($this->repository, ['count-objects', '-v']);
    $index = hash_file('sha256', trim(reattemptGit($this->worktree, ['rev-parse', '--git-path', 'index'])));
    $observation = match ($boundary) {
        'prerequisite' => fn () => app(PrepareTaskReattempt::class)->handle($this->workspace->id, $this->origin->id, $this->originalBase, $this->workspace->manifest_hash, reattemptRequest(), true),
        'shared worktree' => fn () => app(GitTaskWorktree::class)->inspectAssignment($this->repository, $this->worktree),
    };
    expect($observation)->toThrow(RuntimeException::class, 'Partial or promisor')
        ->and(reattemptGit($this->repository, ['count-objects', '-v']))->toBe($before)
        ->and(is_file($object))->toBeFalse()
        ->and(hash_file('sha256', trim(reattemptGit($this->worktree, ['rev-parse', '--git-path', 'index']))))->toBe($index)
        ->and(TaskReattemptCheckpoint::query()->count())->toBe(0)->and($this->agents->prompts)->toHaveCount(1)
        ->and($this->workspace->fresh()->attention)->not->toBeNull();
})->with(['remote.origin.promisor', 'remote.origin.partialclonefilter', 'extensions.partialClone'])->with(['prerequisite', 'shared worktree']);

it('installs and reverses only the additive audit tables without changing existing task history', function () {
    $before = $this->origin->fresh()->toArray();
    $migration = require database_path('migrations/2026_09_12_220541_create_task_reattempt_audits.php');
    $migration->down();
    expect(Schema::hasTable('task_reattempts'))->toBeFalse()
        ->and(Schema::hasTable('task_reattempt_checkpoints'))->toBeFalse()
        ->and($this->origin->fresh()->toArray())->toBe($before);
    $migration->up();
    expect(Schema::hasTable('task_reattempts'))->toBeTrue()
        ->and(Schema::hasTable('task_reattempt_checkpoints'))->toBeTrue()
        ->and($this->origin->fresh()->toArray())->toBe($before);
});
