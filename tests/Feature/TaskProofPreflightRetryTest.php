<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\DispatchTaskAgent;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimeLock;
use App\Tasks\Runtime\TaskRuntimePlan;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\UsesTaskSharedLocks;

uses(DatabaseMigrations::class, UsesTaskSharedLocks::class);

function preflightRetryGit(string $path, array $arguments): string
{
    $process = new LocalProcess(['git', '-c', 'core.hooksPath=/dev/null', ...$arguments], $path,
        [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null']);
    $process->mustRun();

    return trim($process->getOutput());
}

beforeEach(function () {
    $this->repository = $this->taskLockDirectory.'/repository';
    $this->worktree = $this->taskLockDirectory.'/worktree';
    File::makeDirectory($this->repository);
    preflightRetryGit($this->repository, ['init', '--initial-branch=main']);
    preflightRetryGit($this->repository, ['config', 'user.name', 'Retry Test']);
    preflightRetryGit($this->repository, ['config', 'user.email', 'retry@example.test']);
    File::put($this->repository.'/base.txt', 'base');
    preflightRetryGit($this->repository, ['add', '--all']);
    preflightRetryGit($this->repository, ['commit', '-m', 'Base']);
    $base = preflightRetryGit($this->repository, ['rev-parse', 'HEAD']);
    preflightRetryGit($this->repository, ['remote', 'add', 'origin', $this->repository]);
    preflightRetryGit($this->repository, ['worktree', 'add', '-b', 'feature', $this->worktree]);
    File::put($this->worktree.'/feature.txt', 'accepted feature');
    preflightRetryGit($this->worktree, ['add', '--all']);
    preflightRetryGit($this->worktree, ['commit', '-m', 'Accepted feature']);
    $this->candidate = preflightRetryGit($this->worktree, ['rev-parse', 'HEAD']);
    File::put($this->repository.'/main.txt', 'current main');
    preflightRetryGit($this->repository, ['add', '--all']);
    preflightRetryGit($this->repository, ['commit', '-m', 'New main']);
    $this->main = preflightRetryGit($this->repository, ['rev-parse', 'HEAD']);
    $root = Task::query()->create(['project_id' => 'orbit', 'kind' => TaskKind::Group, 'title' => 'Feature',
        'description' => 'Complete feature.', 'acceptance_criteria' => 'Feature works.']);
    $child = Task::query()->create(['project_id' => 'orbit', 'parent_id' => $root->id, 'title' => 'Implementation',
        'description' => 'Implement feature.', 'acceptance_criteria' => 'Implementation works.']);
    $run = TaskRun::query()->create(['task_id' => $child->id, 'root_task_id' => $root->id, 'attempt' => 1,
        'idempotency_key' => 'accepted', 'status' => TaskRunStatus::Completed, 'base_sha' => $base,
        'commit_sha' => $this->candidate, 'worker_ref' => 'worker', 'reviewer_ref' => 'reviewer',
        'started_at' => now(), 'finished_at' => now()]);
    $child->update(['status' => TaskStatus::Completed, 'accepted_task_run_id' => $run->id, 'completed_at' => now()]);
    $this->workspace = TaskWorkspace::query()->create(['root_task_id' => $root->id, 'project_id' => 'orbit',
        'source_key' => 'ORB-91', 'repository' => $this->repository, 'worktree' => $this->worktree,
        'base_sha' => $base, 'manifest_hash' => app(TaskRuntimePlan::class)->hash($root), 'configuration' => [
            'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true],
            'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 10,
        ]]);
    $this->dispatch = TaskAgentDispatch::query()->create(['task_workspace_id' => $this->workspace->id,
        'step_key' => 'root:final_review:0', 'kind' => 'final_review', 'round' => 0,
        'final_check_version' => 1, 'final_preflight_version' => 1,
        'token_hash' => str_repeat('a', 64), 'handoff_token' => 'fixture-token', 'prompt' => 'Unsent final prompt']);
    $agents = Mockery::mock(TaskAgents::class);
    $agents->shouldNotReceive('start', 'prompt', 'assertSession');
    app()->instance(TaskAgents::class, $agents);
});

afterEach(function () {
    DB::table('task_agent_dispatches')->where('id', $this->dispatch->id)->delete();
});

it('retries only the database preflight hold without repeating Git observation or external effects', function (string $failure, int $attempts) {
    $writes = 0;
    $failures = $failure === 'transient' ? 1 : PHP_INT_MAX;
    TaskWorkspace::updating(function (TaskWorkspace $workspace) use (&$writes, $failures, $failure): void {
        if (str_contains($workspace->attention ?? '', 'requires an audited current-main')) {
            $writes++;
            if ($writes <= $failures) {
                throw $failure === 'unrelated'
                    ? new LogicException('Not a concurrency failure.')
                    : new PDOException('SQLSTATE[HY000]: General error: 5 database is locked', 5);
            }
        }
    });
    Process::fake(function ($process) {
        expect($process->command[0])->toBe('git');
        $local = new LocalProcess($process->command, $process->path, $process->environment, timeout: 10);
        $local->run();

        return new ProcessResult($local);
    })->preventStrayProcesses();
    if ($failure === 'transient') {
        app(DispatchTaskAgent::class)->handle($this->dispatch);
        expect($this->dispatch->fresh()->state)->toBe('integration_required')
            ->and($this->dispatch->fresh()->final_preflight)->toBe(['head' => $this->candidate, 'main_sha' => $this->main,
                'main_is_ancestor' => false, 'manifest_hash' => $this->workspace->manifest_hash]);
    } else {
        expect(fn () => app(DispatchTaskAgent::class)->handle($this->dispatch))
            ->toThrow($failure === 'unrelated' ? LogicException::class : PDOException::class)
            ->and($this->dispatch->fresh()->state)->toBe('ambiguous')
            ->and($this->dispatch->fresh()->final_preflight)->toBeNull();
    }
    expect($writes)->toBe($attempts)->and(DB::transactionLevel())->toBe(0)
        ->and($this->dispatch->fresh()->session)->toBeNull()->and($this->dispatch->fresh()->result)->toBeNull()
        ->and($this->dispatch->fresh()->final_check)->toBeNull()->and($this->workspace->fresh()->final_check)->toBeNull();
    Process::assertRanTimes(fn ($process) => in_array('fetch', $process->command, true), 1);
    Process::assertRanTimes(fn ($process) => in_array('ls-remote', $process->command, true), 1);
    Process::assertNotRan(fn ($process) => $process->command[0] !== 'git');
    $recorded = $this->dispatch->fresh()->getRawOriginal();
    app(DispatchTaskAgent::class)->handle($this->dispatch);
    expect($this->dispatch->fresh()->getRawOriginal())->toBe($recorded)->and($writes)->toBe($attempts);
})->with([['transient', 2], ['persistent', 3], ['unrelated', 1]]);

it('keeps effect-capable runtime callbacks single-attempt by default', function () {
    $calls = 0;
    expect(function () use (&$calls): void {
        app(TaskRuntimeLock::class)->handle($this->workspace->id, function () use (&$calls): never {
            $calls++;
            throw new PDOException('database is locked', 5);
        });
    })->toThrow(PDOException::class);
    expect($calls)->toBe(1)->and(DB::transactionLevel())->toBe(0);
});
