<?php

use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\SplitPendingTask as SplitPendingTaskTool;
use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\SplitPendingTask;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgentPrompt;
use App\Tasks\Runtime\TaskManifestAmendmentHistory;
use App\Tasks\Runtime\TaskReattemptGuard;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

function amendmentGit(string $directory, array $arguments): string
{
    $process = new Process(['git', ...$arguments], $directory);
    $process->mustRun();

    return trim($process->getOutput());
}

beforeEach(function () {
    config(['task-runtime.enabled' => true]);
    $this->projectsPath = storage_path('framework/testing/task-amendments-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config(['commander.projects_path' => $this->projectsPath]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->gitRoot = storage_path('framework/testing/task-amendment-git-'.bin2hex(random_bytes(4)));
    $this->repository = $this->gitRoot.'/repository';
    $this->worktree = $this->gitRoot.'/worktree';
    File::makeDirectory($this->repository, 0755, true);
    amendmentGit($this->repository, ['init', '--initial-branch=main']);
    amendmentGit($this->repository, ['config', 'user.email', 'tasks@example.test']);
    amendmentGit($this->repository, ['config', 'user.name', 'Tasks Test']);
    File::put($this->repository.'/README.md', "fixture\n");
    amendmentGit($this->repository, ['add', 'README.md']);
    amendmentGit($this->repository, ['commit', '--message=Initial']);
    amendmentGit($this->repository, ['worktree', 'add', '-b', 'feature', $this->worktree]);
    $this->head = amendmentGit($this->worktree, ['rev-parse', 'HEAD']);

    $create = app(CreateTask::class);
    $this->root = $create->handle('orbit', 'Feature', 'Feature context', TaskKind::Group, acceptanceCriteria: 'Feature is complete');
    $this->accepted = $create->handle('orbit', 'Accepted', 'Accepted work', parent: $this->root, acceptanceCriteria: 'Accepted');
    $this->active = $create->handle('orbit', 'Active', 'Active work', parent: $this->root, acceptanceCriteria: 'Active');
    $this->tail = $create->handle('orbit', 'Large tail', 'Large remaining work', parent: $this->root, acceptanceCriteria: 'All remaining checks');
    $manifest = app(TaskRuntimePlan::class)->hash($this->root);
    $this->workspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->root->id, 'project_id' => 'orbit', 'source_key' => 'ORB-91',
        'repository' => $this->repository, 'worktree' => $this->worktree, 'base_sha' => $this->head,
        'manifest_hash' => $manifest, 'configuration' => ['flow_version' => 1],
    ]);
    $acceptedRun = app(StartTaskRun::class)->handle($this->accepted, 'accepted', 'worker-1', 'reviewer', baseSha: $this->head);
    $acceptedRun->update(['status' => TaskRunStatus::Completed, 'output' => ['summary' => 'accepted'],
        'commit_sha' => $this->head, 'finished_at' => now()]);
    $this->accepted->update(['status' => TaskStatus::Completed, 'completed_at' => now(), 'accepted_task_run_id' => $acceptedRun->id]);
    $this->run = app(StartTaskRun::class)->handle($this->active, 'active', 'worker-2', 'reviewer', baseSha: $this->head);
    $this->dispatch = TaskAgentDispatch::query()->create([
        'task_workspace_id' => $this->workspace->id, 'task_run_id' => $this->run->id,
        'step_key' => $this->run->id.':implement:0', 'kind' => 'implement', 'round' => 0, 'state' => 'sent',
        'token_hash' => hash('sha256', 'secret'), 'handoff_token' => 'secret', 'prompt' => 'original prompt',
        'session' => ['workingDirectory' => $this->worktree, 'agentName' => 'worker-2'],
    ]);
    $this->request = ['reason' => 'The admitted tail is too large for one bounded coding session.',
        'evidence' => 'The accepted and active task scopes remain unchanged; only the untouched tail is decomposed.',
        'replacements' => [
            ['title' => 'Fingerprint', 'description' => 'Align the prepared-state fingerprint.', 'acceptance_criteria' => 'Fingerprint checks pass.'],
            ['title' => 'Snapshots', 'description' => 'Preserve snapshot command contracts.', 'acceptance_criteria' => 'Snapshot checks pass.'],
            ['title' => 'Proof', 'description' => 'Preserve proof and closeout contracts.', 'acceptance_criteria' => 'Proof checks pass.'],
            ['title' => 'Docs', 'description' => 'Finalize maintained documentation.', 'acceptance_criteria' => 'Docs checks pass.'],
        ]];
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->gitRoot);
});

function manifestAmendmentApply(object $test, ?string $proposal = null): array
{
    $arguments = [$test->workspace->id, $test->tail->id, $test->run->id, $test->dispatch->id,
        $test->workspace->manifest_hash, $test->tail->contentVersion(), 'split-orb-91-tail', $test->request, true];
    $preview = app(SplitPendingTask::class)->handle(...$arguments);

    return app(SplitPendingTask::class)->handle(...[...$arguments, $proposal ?? $preview['proposal_hash'], true]);
}

it('splits the untouched tail without changing accepted or active execution history', function () {
    $preserved = [$this->accepted->fresh()->toArray(), $this->active->fresh()->toArray(), $this->run->fresh()->toArray(),
        $this->dispatch->fresh()->toArray(), $this->workspace->fresh()->toArray()];
    $result = manifestAmendmentApply($this);
    $children = app(TaskGraph::class)->orderedChildren($this->root);

    expect($result['applied'])->toBeTrue()
        ->and(array_map(fn (Task $task): int => $task->id, $children))->toBe([$this->accepted->id, $this->active->id, $this->tail->id, $children[3]->id, $children[4]->id, $children[5]->id])
        ->and(array_map(fn (Task $task): string => $task->title, $children))->toBe(['Accepted', 'Active', 'Fingerprint', 'Snapshots', 'Proof', 'Docs'])
        ->and($this->tail->fresh()->dependencies()->sole()->id)->toBe($this->active->id)
        ->and($children[3]->dependencies()->sole()->id)->toBe($this->tail->id)
        ->and($this->accepted->fresh()->toArray())->toBe($preserved[0])
        ->and($this->active->fresh()->toArray())->toBe($preserved[1])
        ->and($this->run->fresh()->toArray())->toBe($preserved[2])
        ->and($this->dispatch->fresh()->toArray())->toBe($preserved[3])
        ->and($this->workspace->fresh()->toArray())->toBe($preserved[4])
        ->and(app(TaskRuntimePlan::class)->effectiveHash($this->workspace))->toBe(app(TaskRuntimePlan::class)->hash($this->root))
        ->and($this->workspace->manifest_hash)->not->toBe(app(TaskRuntimePlan::class)->effectiveHash($this->workspace))
        ->and(TaskManifestAmendment::query()->count())->toBe(1);
    expect(app(TaskAgentPrompt::class)->render($this->workspace, $this->dispatch, 'replacement-token', $this->run))
        ->toContain('"manifest_amendments": [')->toContain('The admitted tail is too large');

    $replay = manifestAmendmentApply($this, $result['proposal_hash']);
    expect($replay['applied'])->toBeFalse()->and($replay['recorded'])->toBeTrue()
        ->and(TaskManifestAmendment::query()->count())->toBe(1)->and(Task::query()->count())->toBe(7);

    app(SubmitTaskDispatch::class)->handle($this->dispatch, ['token' => 'secret', 'summary' => 'Active task is ready.', 'evidence' => 'Snapshot captured.']);
    expect($this->active->fresh()->status)->toBe(TaskStatus::AwaitingReview);
});

it('rejects stale, unsafe, malformed, and conflicting split requests atomically', function () {
    $service = app(SplitPendingTask::class);
    $base = [$this->workspace->id, $this->tail->id, $this->run->id, $this->dispatch->id,
        $this->workspace->manifest_hash, $this->tail->contentVersion(), 'split-orb-91-tail', $this->request, true];

    $stale = $base;
    $stale[5] = str_repeat('f', 64);
    expect(fn () => $service->handle(...$stale))->toThrow(LogicException::class, 'pinned task brief');
    foreach ([2 => ModelNotFoundException::class, 3 => ModelNotFoundException::class, 4 => LogicException::class] as $offset => $exception) {
        $stale = $base;
        $stale[$offset] = $offset === 4 ? str_repeat('f', 64) : $base[$offset] + 999;
        expect(fn () => $service->handle(...$stale))->toThrow($exception);
    }
    $malformed = $base;
    $malformed[7] = ['reason' => 'x', 'evidence' => 'y', 'replacements' => [$this->request['replacements'][0]]];
    expect(fn () => $service->handle(...$malformed))->toThrow(InvalidArgumentException::class, 'two to eight');

    $preview = $service->handle(...$base);
    expect(fn () => $service->handle(...[...$base, str_repeat('a', 64), true]))
        ->toThrow(InvalidArgumentException::class, 'proposal_hash');
    $service->handle(...[...$base, $preview['proposal_hash'], true]);
    $conflict = $this->request;
    $conflict['reason'] = 'Different request';
    expect(fn () => $service->handle($this->workspace->id, $this->tail->id, $this->run->id, $this->dispatch->id,
        $this->workspace->manifest_hash, $this->tail->contentVersion(), 'split-orb-91-tail', $conflict, true))
        ->toThrow(LogicException::class, 'conflicting request')
        ->and(TaskManifestAmendment::query()->count())->toBe(1);
});

it('rolls back the brief and appended tasks when audit persistence fails', function () {
    $before = $this->tail->fresh()->getRawOriginal();
    $count = Task::query()->count();
    Event::listen('eloquent.creating: '.TaskManifestAmendment::class, fn () => throw new LogicException('Injected audit failure.'));

    expect(fn () => manifestAmendmentApply($this))->toThrow(LogicException::class, 'Injected audit failure')
        ->and($this->tail->fresh()->getRawOriginal())->toBe($before)
        ->and(Task::query()->count())->toBe($count)
        ->and(TaskManifestAmendment::query()->count())->toBe(0);
});

it('validates the exact split audit and composes it with a later final correction', function () {
    manifestAmendmentApply($this);
    $audit = TaskManifestAmendment::query()->sole();
    $beforeCorrection = app(TaskRuntimePlan::class)->manifest($this->root->refresh());
    $tail = app(TaskGraph::class)->orderedChildren($this->root)[5];
    $brief = ['title' => 'Final correction', 'description' => 'Correct the integrated feature.',
        'acceptance_criteria' => 'The correction passes.'];
    $correction = Task::query()->create(['project_id' => 'orbit', 'parent_id' => $this->root->id,
        'kind' => TaskKind::Executable, ...$brief]);
    $correction->dependencies()->attach($tail->id);
    $afterCorrection = app(TaskRuntimePlan::class)->manifest($this->root->refresh());
    $request = ['mode' => 'append_correction', 'reason' => 'Final review found a bounded gap.',
        'evidence' => 'The accepted amendment remains unchanged.',
        'task' => [...$brief, 'root_criterion' => 'Feature is complete']];
    TaskFinalContinuation::query()->create(['task_workspace_id' => $this->workspace->id,
        'task_agent_dispatch_id' => $this->dispatch->id, 'task_id' => $correction->id, 'mode' => 'append_correction',
        'request_hash' => TaskManifestAmendmentHistory::hash([$this->workspace->id, $this->dispatch->id, $this->head,
            TaskManifestAmendmentHistory::hash($beforeCorrection), $request]), 'head' => $this->head,
        'previous_manifest_hash' => TaskManifestAmendmentHistory::hash($beforeCorrection),
        'manifest_hash' => TaskManifestAmendmentHistory::hash($afterCorrection), 'final_round' => 1,
        'previous_attention' => 'Final correction required.', 'previous_result' => null, 'request' => $request,
        'created_at' => now()]);

    expect(app(TaskManifestAmendmentHistory::class)->ledger($this->workspace, $afterCorrection))->toHaveCount(1)
        ->and($audit->audit_hash)->toHaveLength(64);

    expect(fn () => app(TaskReattemptGuard::class)->origin($this->workspace, $this->dispatch->id,
        $this->head, app(TaskRuntimePlan::class)->effectiveHash($this->workspace)))
        ->toThrow(LogicException::class, 'Manifest-amended workspaces cannot enter');

    DB::table('task_manifest_amendments')->where('id', $audit->id)->update(['amendment_key' => 'tampered']);
    expect(fn () => app(TaskManifestAmendmentHistory::class)->ledger($this->workspace, $afterCorrection))
        ->toThrow(LogicException::class, 'history is inconsistent');
});

it('rejects ambiguous dispatch ownership and attempted targets', function () {
    $this->dispatch->update(['state' => 'ambiguous']);
    expect(fn () => manifestAmendmentApply($this))->toThrow(LogicException::class, 'unambiguous active task');
    $this->dispatch->update(['state' => 'sent']);
    $this->tail->runs()->create(['root_task_id' => $this->root->id, 'attempt' => 1, 'idempotency_key' => 'bad',
        'worker_ref' => 'worker-3', 'reviewer_ref' => 'reviewer', 'status' => TaskRunStatus::Failed,
        'input' => [], 'started_at' => now(), 'finished_at' => now()]);
    expect(fn () => manifestAmendmentApply($this))->toThrow(LogicException::class, 'untouched tail');
});

it('previews and applies the exact split through the console command', function () {
    $file = $this->gitRoot.'/split-request.json';
    File::put($file, json_encode($this->request, JSON_THROW_ON_ERROR));
    $arguments = ['workspace' => $this->workspace->id, 'target' => $this->tail->id,
        '--run' => $this->run->id, '--dispatch' => $this->dispatch->id,
        '--manifest' => $this->workspace->manifest_hash, '--target-version' => $this->tail->contentVersion(),
        '--key' => 'console-split', '--file' => $file, '--exclusive' => true];

    expect(Artisan::call('tasks:split-pending', $arguments))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['applied'])->toBeFalse()->and($preview['replacement_count'])->toBe(4);
    expect(Artisan::call('tasks:split-pending', [...$arguments,
        '--proposal' => $preview['proposal_hash'], '--apply' => true]))->toBe(0)
        ->and(TaskManifestAmendment::query()->count())->toBe(1);
});

it('previews and applies the exact split through MCP', function () {
    $input = ['project_id' => 'orbit', 'workspace_id' => $this->workspace->id,
        'target_task_id' => $this->tail->id, 'active_run_id' => $this->run->id,
        'active_dispatch_id' => $this->dispatch->id, 'expected_manifest_hash' => $this->workspace->manifest_hash,
        'expected_target_version' => $this->tail->contentVersion(), 'amendment_key' => 'mcp-split',
        'reason' => $this->request['reason'], 'evidence' => $this->request['evidence'],
        'replacements' => $this->request['replacements'], 'exclusive' => true, 'apply' => false,
        'proposal_hash' => null];
    CommanderServer::tool(SplitPendingTaskTool::class, $input)->assertOk()->assertSee('proposal_hash');
    $proposal = app(SplitPendingTask::class)->handle($this->workspace->id, $this->tail->id, $this->run->id,
        $this->dispatch->id, $this->workspace->manifest_hash, $this->tail->contentVersion(), 'mcp-split',
        $this->request, true)['proposal_hash'];
    CommanderServer::tool(SplitPendingTaskTool::class, [...$input, 'proposal_hash' => $proposal, 'apply' => true])
        ->assertOk()->assertSee('ordered_task_ids');
    expect(TaskManifestAmendment::query()->count())->toBe(1)
        ->and(array_map(fn (Task $task): string => $task->title, app(TaskGraph::class)->orderedChildren($this->root)))
        ->toBe(['Accepted', 'Active', 'Fingerprint', 'Snapshots', 'Proof', 'Docs']);
});

it('keeps amendment audits immutable and has a reversible foreign-key-safe migration', function () {
    manifestAmendmentApply($this);
    $audit = TaskManifestAmendment::query()->sole();
    expect(fn () => $audit->update(['head' => str_repeat('f', 40)]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $audit->delete())->toThrow(LogicException::class, 'retain history');

    $migration = require database_path('migrations/2026_09_14_085930_create_task_manifest_amendments_table.php');
    expect(fn () => $migration->down())->toThrow(LogicException::class, 'Retain task manifest amendment history');
    DB::table('task_manifest_amendments')->delete();
    $migration->down();
    expect(Schema::hasTable('task_manifest_amendments'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('task_manifest_amendments'))->toBeTrue();
});
