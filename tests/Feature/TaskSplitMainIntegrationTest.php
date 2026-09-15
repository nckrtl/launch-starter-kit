<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\Proof\OrbitTaskProofEvidence;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ContinueTaskFinal;
use App\Tasks\Runtime\SplitPendingTask;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskMainIntegration;
use App\Tasks\Runtime\TaskManifestAmendmentHistory;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

function splitMainGit(string $path, array $arguments): string
{
    return trim((new NativeProcess(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false',
        ...$arguments], $path))->mustRun()->getOutput());
}

function splitMainSubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): TaskAgentDispatch
{
    return app(SubmitTaskDispatch::class)->handle($dispatch, ['token' => $dispatch->handoff_token,
        'summary' => 'The assigned bounded change is complete.', 'evidence' => 'Focused local fixture checks passed.',
        ...($verdict === null ? [] : ['verdict' => $verdict])]);
}

function splitMainAccept(TaskAgentDispatch $implement): array
{
    $test = test();
    splitMainSubmit($implement);
    $review = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    expect($review->kind)->toBe('review')->and($review->session)->not->toBe($implement->session);
    splitMainSubmit($review, 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($test->workspace);
    expect($commit->kind)->toBe('commit')->and($commit->session)->toBe($review->session);
    splitMainGit($test->worktree, ['add', '--all']);
    splitMainGit($test->worktree, ['commit', '-m', 'Reviewed task '.$implement->task_run_id]);
    splitMainSubmit($commit);
    $run = $implement->run()->firstOrFail()->fresh();
    $task = Task::query()->findOrFail($run->task_id);
    expect($task->status)->toBe(TaskStatus::Completed)->and($task->accepted_task_run_id)->toBe($run->id);

    return $run->toArray();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->directory = storage_path('framework/testing/split-main-'.bin2hex(random_bytes(8)));
    $this->repository = $this->directory.'/repository';
    $this->worktree = $this->directory.'/worktree';
    File::makeDirectory($this->repository, 0700, true);
    File::makeDirectory($this->directory.'/projects', 0700, true);
    config(['task-runtime.enabled' => true, 'commander.projects_path' => $this->directory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    splitMainGit($this->repository, ['init', '--initial-branch=main']);
    splitMainGit($this->repository, ['config', 'user.name', 'Split Main Test']);
    splitMainGit($this->repository, ['config', 'user.email', 'split-main@example.test']);
    File::put($this->repository.'/.gitignore', ".loop/\n");
    File::put($this->repository.'/README.md', "initial\n");
    splitMainGit($this->repository, ['add', '--all']);
    splitMainGit($this->repository, ['commit', '-m', 'Initial']);
    splitMainGit($this->repository, ['remote', 'add', 'origin', $this->repository]);
    splitMainGit($this->repository, ['worktree', 'add', '-b', 'orb-91', $this->worktree]);
    $create = app(CreateTask::class);
    $this->root = $create->handle('orbit', 'Feature', 'Complete the scoped feature.', TaskKind::Group,
        acceptanceCriteria: 'Feature works and all focused checks pass.');
    $this->first = $create->handle('orbit', 'First', 'First bounded task.', parent: $this->root, acceptanceCriteria: 'First passes.');
    $this->active = $create->handle('orbit', 'Active', 'Active bounded task.', parent: $this->root, acceptanceCriteria: 'Active passes.');
    $this->tail = $create->handle('orbit', 'Large tail', 'Remaining scope.', parent: $this->root, acceptanceCriteria: 'Remaining checks pass.');
    $this->workspace = TaskWorkspace::query()->create(['root_task_id' => $this->root->id, 'project_id' => 'orbit',
        'source_key' => 'ORB-91', 'repository' => $this->repository, 'worktree' => $this->worktree,
        'base_sha' => splitMainGit($this->worktree, ['rev-parse', 'HEAD']),
        'manifest_hash' => app(TaskRuntimePlan::class)->hash($this->root),
        'configuration' => ['flow_version' => 1,
            'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true],
            'final_command' => [PHP_BINARY, '-r', 'exit(is_file("corrected.txt") ? 0 : 7);'], 'final_timeout' => 10],
    ]);
    $this->agents = new class implements TaskAgents
    {
        public array $prompts = [];

        public function assertSession(TaskWorkspace $workspace, array $session): void {}

        public function start(TaskWorkspace $workspace, string $name): array
        {
            return ['agentName' => $name, 'agentId' => 'fixture-'.$name, 'workingDirectory' => $workspace->worktree];
        }

        public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
        {
            $this->prompts[] = $prompt;

            return $session;
        }
    };
    app()->instance(TaskAgents::class, $this->agents);
});

afterEach(fn () => File::deleteDirectory($this->directory));

it('preserves a live split through real task acceptance, integration, correction and proof export', function () {
    $first = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    File::put($this->worktree.'/first.txt', 'first');
    $firstRun = splitMainAccept($first);
    $active = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    $preserved = [$this->workspace->fresh()->toArray(), $active->toArray(), $active->run()->firstOrFail()->toArray()];
    $request = ['reason' => 'The untouched tail exceeds a bounded implementation.',
        'evidence' => 'The accepted first task and active second task are unchanged.',
        'replacements' => array_map(fn (string $name): array => ['title' => $name,
            'description' => 'Complete '.$name.' only.', 'acceptance_criteria' => $name.' checks pass.'],
            ['Fingerprint', 'Snapshot contracts', 'Proof contracts', 'Documentation'])];
    $arguments = [$this->workspace->id, $this->tail->id, $active->task_run_id, $active->id,
        $this->workspace->manifest_hash, $this->tail->contentVersion(), 'split-before-main', $request, true];
    $preview = app(SplitPendingTask::class)->handle(...$arguments);
    app(SplitPendingTask::class)->handle(...[...$arguments, $preview['proposal_hash'], true]);
    $amendment = TaskManifestAmendment::query()->sole()->toArray();
    expect([$this->workspace->fresh()->toArray(), $active->fresh()->toArray(), $active->run()->firstOrFail()->toArray()])
        ->toBe($preserved)->and($this->first->fresh()->acceptedRun()->firstOrFail()->toArray())->toBe($firstRun);
    $children = app(TaskGraph::class)->orderedChildren($this->root);
    expect(array_column(array_map(fn (Task $task): array => $task->toArray(), $children), 'title'))
        ->toBe(['First', 'Active', 'Fingerprint', 'Snapshot contracts', 'Proof contracts', 'Documentation']);
    File::put($this->worktree.'/active.txt', 'active');
    $accepted = [$firstRun, splitMainAccept($active)];
    foreach (array_slice($children, 2) as $child) {
        $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
        expect($implement->run()->firstOrFail()->task_id)->toBe($child->id)
            ->and($implement->session)->not->toBe($active->session);
        File::put($this->worktree.'/task-'.$child->id.'.txt', $child->title);
        $accepted[] = splitMainAccept($implement);
    }
    $acceptedTail = splitMainGit($this->worktree, ['rev-parse', 'HEAD']);
    File::put($this->repository.'/main.txt', 'main advanced');
    splitMainGit($this->repository, ['add', '--all']);
    splitMainGit($this->repository, ['commit', '-m', 'Main advanced']);
    $main = splitMainGit($this->repository, ['rev-parse', 'HEAD']);
    $held = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($held->state)->toBe('integration_required')->and($held->final_check)->toBeNull();
    $originalHeld = $held->toArray();
    $integration = app(ContinueTaskFinal::class)->handle($this->workspace->id, $held->id, $acceptedTail,
        $held->final_preflight['manifest_hash'], ['mode' => 'integrate_main', 'main_sha' => $main,
            'reason' => 'Include current main before proof.', 'evidence' => 'Exact held preflight.'], true, true);
    $integration['audit'] = TaskFinalContinuation::query()->findOrFail($integration['audit']['id'])->toArray();
    $mergeWorker = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    splitMainGit($this->worktree, ['merge', '--no-commit', '--no-ff', $main]);
    $mergeRun = splitMainAccept($mergeWorker);
    expect(splitMainGit($this->worktree, ['show', '-s', '--format=%P', 'HEAD']))->toBe($acceptedTail.' '.$main)
        ->and($held->fresh()->toArray())->toBe($originalHeld);
    $failed = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($failed->state)->toBe('check_failed')->and($failed->round)->toBe(1)->and($failed->final_check['exit_code'])->toBe(7);
    $correction = app(ContinueTaskFinal::class)->handle($this->workspace->id, $failed->id, $mergeRun['commit_sha'],
        $failed->final_check['manifest_hash'], ['mode' => 'append_correction', 'reason' => 'Fix the focused check.',
            'evidence' => 'The exact final command failed.', 'task' => ['title' => 'Correct final check',
                'description' => 'Fix the bounded check failure.', 'acceptance_criteria' => 'All focused checks pass.',
                'root_criterion' => 'all focused checks pass.']], true, true);
    $correction['audit'] = TaskFinalContinuation::query()->findOrFail($correction['audit']['id'])->toArray();
    $correctionWorker = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect(app(TaskMainIntegration::class)->binding($correctionWorker->run()->firstOrFail()))->toBeNull();
    File::put($this->worktree.'/corrected.txt', 'fixed');
    $correctedRun = splitMainAccept($correctionWorker);
    expect(splitMainGit($this->worktree, ['show', '-s', '--format=%P', 'HEAD']))->toBe($mergeRun['commit_sha'])
        ->and(TaskManifestAmendment::query()->sole()->toArray())->toBe($amendment)
        ->and(TaskFinalContinuation::query()->where('mode', 'integrate_main')->sole()->toArray())->toBe($integration['audit']);
    foreach ($accepted as $original) {
        expect(Task::query()->findOrFail($original['task_id'])->acceptedRun()->firstOrFail()->toArray())->toBe($original);
    }

    // Only the unsent export boundary is a fixture. Every accepted run, review, commit and audit above is runtime-produced.
    $final = $this->workspace->dispatches()->create(['step_key' => 'root:final_review:2', 'kind' => 'final_review',
        'round' => 2, 'state' => 'sending', 'final_check_version' => 1, 'final_preflight_version' => 1,
        'token_hash' => hash('sha256', 'private-export-token'), 'handoff_token' => 'private-export-token', 'prompt' => '']);
    $command = $this->workspace->configuration['final_command'];
    $builder = (new NativeProcess($command, $this->worktree))->mustRun();
    $manifest = app(TaskRuntimePlan::class)->manifest($this->root);
    $check = ['sha' => $correctedRun['commit_sha'], 'manifest_hash' => TaskManifestAmendmentHistory::hash($manifest),
        'candidate_unchanged' => true, 'command' => $command, 'exit_code' => $builder->getExitCode(),
        'output' => $builder->getOutput(), 'error_output' => $builder->getErrorOutput()];
    // Export checks only local identity. Block all subsequent fetch, publish and remote commands.
    splitMainGit($this->repository, ['remote', 'set-url', 'origin', 'https://github.com/nckrtl/orbit.git']);
    Process::preventStrayProcesses();
    Process::fake(function ($process) {
        if (! is_array($process->command) || $process->command[0] !== 'git'
            || array_intersect($process->command, ['fetch', 'push', 'ls-remote']) !== []) {
            throw new LogicException('Only read-only local Git commands are allowed during export.');
        }
        $result = new NativeProcess($process->command, $process->path);
        $result->run();

        return Process::result(output: $result->getOutput(), errorOutput: $result->getErrorOutput(), exitCode: $result->getExitCode());
    });
    File::makeDirectory($this->worktree.'/.loop/proof', 0700, true);
    File::put($this->worktree.'/.loop/flow.json', TaskLandingData::json(['schema' => 1, 'flow' => 'proof']));
    File::put($this->worktree.'/.loop/proof/ORB-91.json', TaskLandingData::json(['snapshot_replacement' => true, 'inputs' => []]));
    $export = app(OrbitTaskProofEvidence::class)->inputs($this->workspace->fresh(), $check);
    expect($export['database']['final_dispatch_id'])->toBe($final->id)
        ->and($export['database']['manifest'])->toBe($manifest)
        ->and($export['database']['manifest_amendments'])->toHaveCount(1)
        ->and($export['database']['manifest_amendments'][0]['audit_hash'])->toBe($amendment['audit_hash'])
        ->and($export['database']['final_continuations'])->toBe([$integration['audit'], $correction['audit']])
        ->and($export['database']['accepted_tasks'])->toHaveCount(8)
        ->and(array_column($export['database']['accepted_tasks'], 'commit_sha'))
        ->toBe(array_column([...$accepted, $mergeRun, $correctedRun], 'commit_sha'))
        ->and($export['database']['accepted_tasks'][6]['main_integration']['parents'])->toBe([$acceptedTail, $main])
        ->and($export['database']['accepted_tasks'][6]['main_integration']['held_preflight'])->toBe($held->final_preflight)
        ->and($export['database']['accepted_tasks'][7])->not->toHaveKey('main_integration')
        ->and($this->workspace->fresh()->manifest_hash)->toBe($preserved[0]['manifest_hash']);

    $history = app(TaskManifestAmendmentHistory::class);
    $auditId = $integration['audit']['id'];
    foreach (['previous_manifest', 'manifest'] as $field) {
        $tampered = $integration['audit'][$field];
        $tampered['root']['title'] = 'Corrupted snapshot';
        DB::table('task_final_continuations')->where('id', $auditId)->update([$field => json_encode($tampered, JSON_THROW_ON_ERROR)]);
        expect(fn () => $history->ledger($this->workspace, $manifest))->toThrow(LogicException::class);
        DB::table('task_final_continuations')->where('id', $auditId)->update([$field => json_encode($integration['audit'][$field], JSON_THROW_ON_ERROR)]);
    }
    DB::table('task_agent_dispatches')->where('id', $held->id)->update(['final_preflight_version' => null]);
    expect(fn () => $history->ledger($this->workspace, $manifest))->toThrow(LogicException::class, 'retained preflight');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
