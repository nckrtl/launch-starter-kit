<?php

use App\Jobs\AdvanceTaskRunner;
use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskArtifactReviewBinding;
use App\Models\TaskFinalContinuation;
use App\Models\TaskRun;
use App\Models\TaskSessionReconnection;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\AcceptTaskRun;
use App\Tasks\Actions\CompleteTaskGroup;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\MarkTaskRunReadyForReview;
use App\Tasks\Actions\RecordTaskRunReview;
use App\Tasks\Actions\UpdateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\NativeTaskLandingRepository;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Landing\TaskLandingReviewTransport;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\Proof\OrbitTaskProofEvidence;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ContinueTaskFinal;
use App\Tasks\Runtime\GitTaskWorktree;
use App\Tasks\Runtime\ReconnectTaskSessions;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgentPrompt;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskAgentSessions;
use App\Tasks\Runtime\TaskArtifactReviews;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\Runtime\TaskSessionObserver;
use App\Tasks\Runtime\TaskTerminalSessions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process as LocalProcess;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class TaskRuntimeFakeAgents implements TaskAgents
{
    public bool $nullNative = false;

    public bool $reviewerMissing = false;

    public ?Closure $duringObservation = null;

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        if ($this->duringObservation !== null) {
            ($this->duringObservation)($workspace);
        }
        if ($this->reviewerMissing || ($session['workingDirectory'] ?? null) !== $workspace->worktree) {
            throw new LogicException('The assigned reviewer session changed.');
        }
    }

    public array $starts = [];

    public array $prompts = [];

    public bool $failNextPrompt = false;

    public bool $advanceSequence = false;

    public ?Closure $duringPrompt = null;

    public function start(TaskWorkspace $workspace, string $name): array
    {
        if ($this->nullNative && $workspace->herdr_workspace === null) {
            $workspace->update(['herdr_workspace' => ['workspaceId' => 'workspace-'.$workspace->id, 'paneId' => 'first-pane']]);
        }
        $session = ['workspaceId' => 'workspace-'.$workspace->id, 'tabId' => 'tab-'.$workspace->id,
            'paneId' => 'pane-'.$name, 'terminalId' => 'terminal-'.$name, 'agentId' => $this->nullNative ? null : 'conversation-'.$name,
            'agentName' => $name, 'workingDirectory' => $workspace->worktree,
            'agentStatus' => 'working', 'stateChangeSeq' => 1];
        $this->starts[] = $session;

        return $session;
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = ['workspace' => $workspace->id, 'session' => $session, 'prompt' => $prompt];
        if ($this->duringPrompt !== null) {
            ($this->duringPrompt)($workspace);
        }
        if ($this->failNextPrompt) {
            $this->failNextPrompt = false;
            throw new RuntimeException('Agent prompt timed out after submission.');
        }

        if ($this->advanceSequence) {
            $session['stateChangeSeq']++;
        }

        return $session;
    }
}

function taskRuntimeProofEvidence(string $defect = 'none', bool $createFinal = true, ?Closure $afterFirst = null): array
{
    $test = test();
    $configuration = config('task-runtime.projects.orbit');
    $configuration['orbit_profile'] = ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true];
    config(['task-runtime.projects.orbit' => $configuration]);
    taskRuntimeGit($test->runtimeWorktree, ['branch', '-m', 'orb-91']);
    taskRuntimeGit($test->runtimeWorktree, ['remote', 'add', 'origin', 'git@github.com:nckrtl/orbit.git']);
    File::append($test->runtimeRepository.'/.git/info/exclude', "\n.loop/\n");
    File::ensureDirectoryExists($test->runtimeWorktree.'/.loop');
    File::put($test->runtimeWorktree.'/.loop/flow.json', json_encode(['schema' => 1, 'flow' => 'proof'], JSON_THROW_ON_ERROR));
    foreach (['bin/e2e-topology', 'apps/e2e/artisan', 'apps/e2e/bootstrap/app.php', 'apps/e2e/vendor/autoload.php'] as $relative) {
        File::ensureDirectoryExists(dirname($test->runtimeWorktree.'/'.$relative));
        File::put($test->runtimeWorktree.'/'.$relative, $relative === 'bin/e2e-topology' ? "#!/bin/sh\nexit 99\n" : "<?php\n");
    }
    chmod($test->runtimeWorktree.'/bin/e2e-topology', 0755);
    taskRuntimeGit($test->runtimeWorktree, ['add', '--all']);
    taskRuntimeGit($test->runtimeWorktree, ['commit', '--message=Proof harness fixture']);
    $workspace = app(StartTaskWorkspace::class)->handle($test->runtimeRoot, $test->runtimeWorktree,
        app(TaskRuntimePlan::class)->hash($test->runtimeRoot), 'ORB-91', true, 'proof', true);
    taskRuntimeAcceptNext($workspace, 'first proof task');
    if ($afterFirst !== null) {
        $afterFirst($workspace);
    }
    taskRuntimeAcceptNext($workspace, 'integrated proof task');
    $workspace->refresh();
    $final = $createFinal ? TaskAgentDispatch::query()->create(['task_workspace_id' => $workspace->id,
        'step_key' => 'final:0', 'kind' => 'final_review', 'round' => 0, 'state' => 'sending',
        'token_hash' => str_repeat('a', 64), 'handoff_token' => str_repeat('b', 64), 'prompt' => '', 'final_check_version' => 1]) : null;
    File::ensureDirectoryExists($workspace->worktree.'/.loop/proof');
    File::put($workspace->worktree.'/.loop/flow.json', json_encode(['schema' => 1, 'flow' => 'proof'], JSON_THROW_ON_ERROR));
    $fixture = $workspace->worktree.'/.loop/proof/request.json';
    File::put($fixture, "{\"fixture\":true}\n");
    $input = '.loop/proof/request.json';
    $replacement = true;
    if ($defect === 'escape') {
        $input = '../secret';
    } elseif ($defect === 'declaration') {
        $replacement = false;
    } elseif ($defect === 'missing') {
        File::delete($fixture);
    } elseif ($defect === 'symlink') {
        File::move($fixture, $fixture.'.real');
        symlink($fixture.'.real', $fixture);
    } elseif ($defect === 'executable') {
        chmod($fixture, 0755);
    } elseif ($defect === 'nul') {
        File::put($fixture, "unsafe\0fixture");
    } elseif ($defect === 'unexpected') {
        File::put($workspace->worktree.'/.loop/proof/unexpected.json', '{}');
        chmod($workspace->worktree.'/.loop/proof/unexpected.json', 0644);
    } elseif ($defect === 'nested') {
        File::ensureDirectoryExists($workspace->worktree.'/.loop/proof/nested');
        File::move($fixture, $workspace->worktree.'/.loop/proof/nested/request.json');
        $input = '.loop/proof/nested/request.json';
    }
    $declared = [$input];
    if ($defect === 'reacquire') {
        File::put($workspace->worktree.'/.loop/proof/snapshot-reacquire.json', '{"schema":1,"purpose":"postinstall"}');
        $declared[] = '.loop/proof/snapshot-reacquire.json';
    }
    File::put($workspace->worktree.'/.loop/proof/ORB-91.json', json_encode([
        'setup' => [], 'acceptance' => [['id' => 'proof', 'node' => 'app-prod-1', 'argv' => ['true'], 'timeout_seconds' => 30]],
        'inputs' => $declared, 'snapshot_replacement' => $replacement,
    ], JSON_THROW_ON_ERROR));
    $candidate = trim(taskRuntimeGit($workspace->worktree, ['rev-parse', 'HEAD']));
    $check = ['sha' => $candidate, 'manifest_hash' => app(TaskRuntimePlan::class)->effectiveHash($workspace),
        'candidate_unchanged' => true, 'command' => ['composer', 'check'], 'exit_code' => 0, 'output' => 'passed', 'error_output' => ''];

    return [app(OrbitTaskProofEvidence::class), $workspace, $final, $check];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->runtimeDirectory = trim(Process::timeout(10)->run([
        'mktemp', '-d', sys_get_temp_dir().'/commander-task-runtime-XXXXXX',
    ])->throw()->output());
    $this->runtimeRepository = $this->runtimeDirectory.'/repository';
    $this->runtimeWorktreeRoot = $this->runtimeDirectory.'/worktrees';
    $this->runtimeWorktree = $this->runtimeWorktreeRoot.'/feature';
    File::makeDirectory($this->runtimeRepository);
    File::makeDirectory($this->runtimeWorktreeRoot);
    File::makeDirectory($this->runtimeDirectory.'/projects');
    config(['commander.projects_path' => $this->runtimeDirectory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    taskRuntimeGit($this->runtimeRepository, ['init', '--initial-branch=main']);
    taskRuntimeGit($this->runtimeRepository, ['config', 'user.email', 'tasks@example.test']);
    taskRuntimeGit($this->runtimeRepository, ['config', 'user.name', 'Task Test']);
    File::put($this->runtimeRepository.'/.gitignore', ".env\nvendor/\n");
    File::put($this->runtimeRepository.'/feature.txt', "base\n");
    taskRuntimeGit($this->runtimeRepository, ['add', '--all']);
    taskRuntimeGit($this->runtimeRepository, ['commit', '--message=Base']);
    taskRuntimeGit($this->runtimeRepository, ['worktree', 'add', '-b', 'feature', $this->runtimeWorktree]);
    $this->runtimeBase = trim(taskRuntimeGit($this->runtimeWorktree, ['rev-parse', 'HEAD']));
    config(['task-runtime.enabled' => true, 'task-runtime.projects.orbit' => [
        'repository' => $this->runtimeRepository, 'worktree_root' => $this->runtimeWorktreeRoot,
        'socket' => $this->runtimeDirectory.'/unused.sock', 'agent_kind' => 'codex', 'agent_arguments' => [],
        'orbit_node_id' => 9, 'orbit_herdr_session_id' => 41, 'orbit_herdr_session' => 'commander-tasks',
        'orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
        'flow_version' => 1, 'final_command' => [PHP_BINARY, '-r', 'exit(0);'], 'final_timeout' => 5,
        'instructions' => 'Use the task receipt as the explicit handoff. Validate the complete feature.',
    ]]);
    $this->runtimeAgents = new TaskRuntimeFakeAgents;
    app()->instance(TaskAgents::class, $this->runtimeAgents);
    $this->runtimeRoot = app(CreateTask::class)->handle('orbit', 'Integrated feature', 'Deliver both feature parts.',
        TaskKind::Group, acceptanceCriteria: 'Both parts work together, with passing final evidence.');
    $this->runtimeFirst = app(CreateTask::class)->handle('orbit', 'First part', 'Implement the first part.',
        parent: $this->runtimeRoot, acceptanceCriteria: 'The first part works and has focused checks.');
    $this->runtimeSecond = app(CreateTask::class)->handle('orbit', 'Second part', 'Integrate the second part.',
        parent: $this->runtimeRoot, acceptanceCriteria: 'The second part works with the first part.');
});

afterEach(fn () => File::deleteDirectory($this->runtimeDirectory));

function taskArtifactHandoff(array $inputs = []): array
{
    $test = test();
    taskRuntimeNativeFlow('{"schema":1,"flow":"proof"}');
    taskRuntimeGit($test->runtimeWorktree, ['branch', '-m', 'orb-91']);
    taskRuntimeGit($test->runtimeWorktree, ['remote', 'add', 'origin', 'git@github.com:nckrtl/orbit.git']);
    $workspace = app(StartTaskWorkspace::class)->handle($test->runtimeRoot, $test->runtimeWorktree,
        app(TaskRuntimePlan::class)->hash($test->runtimeRoot), 'ORB-91', true, 'proof', true);
    taskRuntimeAcceptNext($workspace, 'Real accepted code');
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::ensureDirectoryExists($workspace->worktree.'/.loop/proof');
    File::put($workspace->worktree.'/.loop/proof/ORB-91.json', json_encode([
        'snapshot_replacement' => true, 'inputs' => ['.loop/proof/request.json', ...$inputs],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    File::put($workspace->worktree.'/.loop/proof/request.json', '{"fixture":true}');
    chmod($workspace->worktree.'/.loop/proof/ORB-91.json', 0644);
    chmod($workspace->worktree.'/.loop/proof/request.json', 0644);
    taskRuntimeSubmit($implement);

    return [$workspace->refresh(), $implement->refresh(), app(TaskArtifactReviews::class)];
}

function taskArtifactBind(TaskWorkspace $workspace, TaskAgentDispatch $implement, bool $apply = true): array
{
    $action = app(TaskArtifactReviews::class);
    $args = [$workspace->id, $implement->task_run_id, $implement->id, $implement->round + 1,
        app(TaskRuntimePlan::class)->effectiveHash($workspace), 'Only ignored proof artifacts changed.', true, true];
    $preview = $action->capture(...$args);

    return $apply ? $action->capture(...[...$args, $preview['binding_hash'], true]) : $preview;
}

it('accepts an artifact-only result atomically on independent review without changing the code tip', function () {
    [$workspace, $implement, $artifacts] = taskArtifactHandoff();
    $before = $implement->run()->firstOrFail()->getRawOriginal();
    $base = trim(taskRuntimeGit($workspace->worktree, ['rev-parse', 'HEAD']));
    $preview = taskArtifactBind($workspace, $implement, false);
    expect($preview['recorded'])->toBeFalse()->and(TaskArtifactReviewBinding::query()->count())->toBe(0);
    $bound = taskArtifactBind($workspace, $implement);
    expect($bound['binding_hash'])->toBe($preview['binding_hash'])
        ->and(taskArtifactBind($workspace, $implement)['applied'])->toBeFalse()
        ->and($implement->run()->firstOrFail()->getRawOriginal())->toBe($before);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($review->kind)->toBe('review')->and($review->prompt)->toContain('artifact_review_binding', 'without a product commit');
    taskRuntimeSubmit($review, 'pass');
    $run = $implement->run()->firstOrFail();
    expect($run->status)->toBe(TaskRunStatus::Completed)->and($run->commit_sha)->toBeNull()
        ->and($run->base_sha)->toBe($base)->and($this->runtimeSecond->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($workspace->dispatches()->where('task_run_id', $run->id)->where('kind', 'commit')->exists())->toBeFalse()
        ->and(trim(taskRuntimeGit($workspace->worktree, ['rev-parse', 'HEAD'])))->toBe($base)
        ->and($artifacts->acceptedBindings($workspace))->toHaveCount(1);
    taskRuntimeSubmit($review, 'pass');
    expect(TaskArtifactReviewBinding::query()->count())->toBe(1);
});

it('holds artifact corrections for a fresh immutable round binding and preserves the original worker', function () {
    [$workspace, $implement] = taskArtifactHandoff();
    taskArtifactBind($workspace, $implement);
    $firstBinding = TaskArtifactReviewBinding::query()->sole()->getRawOriginal();
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($review, 'revise');
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->task_run_id)->toBe($implement->task_run_id)->and($correction->session)->toBe($implement->session);
    File::put($workspace->worktree.'/.loop/proof/request.json', '{"fixture":"corrected"}');
    taskRuntimeSubmit($correction);
    expect($workspace->fresh()->attention)->toContain('tasks:bind-artifacts')
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull();
    taskArtifactBind($workspace->refresh(), $correction);
    expect(TaskArtifactReviewBinding::query()->count())->toBe(2)
        ->and(TaskArtifactReviewBinding::query()->first()->getRawOriginal())->toBe($firstBinding);
    $secondReview = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($secondReview, 'pass');
    expect($secondReview->round)->toBe(2)->and($implement->run()->firstOrFail()->commit_sha)->toBeNull()
        ->and($workspace->dispatches()->where('task_run_id', $implement->task_run_id)->where('kind', 'commit')->count())->toBe(0);
});

it('rejects changed unsafe or secret artifact packages before binding or reviewer acceptance', function (string $case) {
    [$workspace, $implement] = taskArtifactHandoff();
    $preview = taskArtifactBind($workspace, $implement, false);
    if (in_array($case, ['review-change', 'review-mode', 'review-addition', 'dispatch-change'], true)) {
        taskArtifactBind($workspace, $implement);
    }
    $review = in_array($case, ['review-change', 'review-mode', 'review-addition'], true) ? app(AdvanceTaskWorkspace::class)->handle($workspace) : null;
    $path = $workspace->worktree.'/.loop/proof/request.json';
    match ($case) {
        'tracked' => File::put($workspace->worktree.'/feature.txt', 'unexpected tracked change'),
        'unexpected', 'review-addition' => (function () use ($workspace) {
            File::put($workspace->worktree.'/.loop/proof/extra.json', '{}');
            chmod($workspace->worktree.'/.loop/proof/extra.json', 0644);
        })(),
        'missing' => File::delete($path),
        'mode', 'review-mode' => chmod($path, 0664),
        'symlink' => (function () use ($path) {
            File::move($path, $path.'.real');
            symlink($path.'.real', $path);
        })(),
        'secret' => File::put($path, $implement->handoff_token),
        'nul' => File::put($path, "unsafe\0bytes"),
        default => File::put($path, '{"fixture":"changed"}'),
    };
    $call = match (true) {
        $review !== null => fn () => taskRuntimeSubmit($review, 'pass'),
        $case === 'dispatch-change' => fn () => app(AdvanceTaskWorkspace::class)->handle($workspace),
        default => fn () => app(TaskArtifactReviews::class)->capture($workspace->id, $implement->task_run_id,
            $implement->id, 1, $workspace->manifest_hash, 'Only ignored proof artifacts changed.', true, true, $preview['binding_hash'], true),
    };
    expect($call)->toThrow(Exception::class)->and($this->runtimeSecond->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($implement->run()->firstOrFail()->commit_sha)->toBeNull();
    if ($review !== null) {
        expect($review->fresh()->state)->toBe('sent')->and($review->run()->firstOrFail()->reviews()->latest('round')->first()->verdict)->toBeNull();
    }
})->with(['tracked', 'unexpected', 'missing', 'mode', 'symlink', 'secret', 'nul', 'preview-change', 'review-change', 'review-mode', 'review-addition', 'dispatch-change']);

it('rejects stale conflicting and unowned artifact binding requests', function (string $case) {
    [$workspace, $implement, $action] = taskArtifactHandoff();
    $args = [$workspace->id, $implement->task_run_id, $implement->id, 1, $workspace->manifest_hash, 'Only ignored proof artifacts changed.', true, true];
    if ($case === 'dispatched') {
        app(AdvanceTaskWorkspace::class)->handle($workspace);
    } elseif ($case === 'conflict') {
        taskArtifactBind($workspace, $implement);
        $args[5] = 'A different reason.';
    } else {
        $args[match ($case) {
            'round' => 3, 'manifest' => 4, 'exclusive' => 6, 'drained' => 7
        }] = match ($case) {
            'round' => 2, 'manifest' => str_repeat('c', 64), default => false,
        };
    }
    expect(fn () => $action->capture(...$args))->toThrow(Exception::class);
})->with(['dispatched', 'conflict', 'round', 'manifest', 'exclusive', 'drained']);

it('does not authorize null-commit acceptance outside an independent artifact review dispatch', function (bool $bound) {
    [$workspace, $implement] = taskArtifactHandoff();
    if ($bound) {
        taskArtifactBind($workspace, $implement);
    }
    $review = $implement->run()->firstOrFail()->reviews()->sole();
    app(RecordTaskRunReview::class)->handle($review, $review->taskRun->reviewer_ref,
        TaskReviewVerdict::Pass, 'Direct verdict', 'Not an artifact instruction.');
    expect(fn () => app(AcceptTaskRun::class)->handle($review->refresh()))
        ->toThrow(LogicException::class, $bound ? 'independent reviewer handoff' : 'accepted commit');
})->with([false, true]);

function taskArtifactFinalFixture(TaskWorkspace $workspace): array
{
    $round = app(TaskRuntimePlan::class)->finalRound($workspace);
    $final = $workspace->dispatches()->create(['step_key' => 'root:final_review:'.$round, 'kind' => 'final_review',
        'round' => $round, 'state' => 'prepared', 'token_hash' => str_repeat('c', 64),
        'handoff_token' => str_repeat('d', 64), 'prompt' => '', 'final_check_version' => 1]);
    $prompt = app(TaskAgentPrompt::class)->render($workspace->refresh(), $final, $final->handoff_token, null);
    expect($prompt)->toContain('accepted_artifact_results', 'artifact_modes');
    $final->update(['state' => 'sending']);
    $head = trim(taskRuntimeGit($workspace->worktree, ['rev-parse', 'HEAD']));
    $check = ['sha' => $head, 'manifest_hash' => app(TaskRuntimePlan::class)->effectiveHash($workspace),
        'candidate_unchanged' => true, 'exit_code' => 0, 'output' => 'fixture gate passed', 'error_output' => ''];

    return [$final, $check];
}

it('exports an artifact tail for proof and landing without inventing another code commit', function () {
    [$workspace, $implement, $artifacts] = taskArtifactHandoff();
    taskArtifactBind($workspace, $implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    [$final, $check] = taskArtifactFinalFixture($workspace);
    $inputs = app(OrbitTaskProofEvidence::class)->inputs($workspace->refresh(), $check);
    $accepted = $inputs['database']['accepted_tasks'];
    expect($accepted)->toHaveCount(2)->and($accepted[1]['commit_sha'])->toBeNull()
        ->and($accepted[1]['base_sha'])->toBe($accepted[0]['commit_sha'])
        ->and($inputs['repository']['candidate'])->toBe($accepted[0]['commit_sha'])
        ->and($accepted[1]['artifact_result']['binding']['contract'])->toBe($inputs['proof_contract']);
    $changedInputs = $inputs;
    $changedInputs['proof_contract'][1]['contents'] = '{"fixture":"different"}';
    $changedInputs['proof_contract'][1]['sha256'] = hash('sha256', $changedInputs['proof_contract'][1]['contents']);
    expect(fn () => app(NativeTaskLandingRepository::class)->artifact($workspace, $check['sha'], $changedInputs))
        ->toThrow(LogicException::class, 'proof package differs');
    $receipt = ['summary' => 'Independent final fixture verdict', 'evidence' => 'Fixture evidence', 'verdict' => 'pass'];
    $workspace->update(['herdr_workspace' => ['workspaceId' => 'workspace-'.$workspace->id], 'final_check' => $check,
        'final_result' => [...$receipt, 'native_proof_review' => []]]);
    $final->update(['state' => 'acknowledged', 'result' => $receipt, 'session' => $workspace->reviewer_session, 'final_check' => $check]);
    app(CompleteTaskGroup::class)->handle($workspace->root()->firstOrFail());
    $landing = app(TaskLandingEvidence::class)->database($workspace->refresh(), ['candidate' => $check['sha'],
        'manifest' => $check['manifest_hash'], 'final_dispatch' => $final->id]);
    expect($landing['accepted_tasks'])->toBe($accepted);
    File::put($workspace->worktree.'/.loop/proof/request.json', '{"fixture":"tampered"}');
    expect(fn () => $artifacts->assertPublication($workspace, $inputs['proof_contract']))->toThrow(LogicException::class, 'proof package differs');
});

it('continues from an artifact tail and lets a separately reviewed artifact correction advance only the artifact tip', function () {
    [$workspace, $implement, $artifacts] = taskArtifactHandoff();
    taskArtifactBind($workspace, $implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $original = TaskArtifactReviewBinding::query()->sole()->getRawOriginal();
    [$final, $check] = taskArtifactFinalFixture($workspace);
    $check['exit_code'] = 1;
    $final->update(['state' => 'check_failed', 'final_check' => $check, 'error' => 'Fixture proof-plan failure']);
    $workspace->update(['attention' => 'Fixture proof-plan failure', 'final_check' => $check]);
    $continued = taskFinalContinue($workspace, $final, taskFinalRequest());
    $child = Task::query()->findOrFail($continued['audit']['task_id']);
    expect($child->dependencies()->sole()->id)->toBe($this->runtimeSecond->id);
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->kind)->toBe('implement')->and($correction->task_run_id)->not->toBe($implement->task_run_id)
        ->and($correction->run()->firstOrFail()->base_sha)->toBe($check['sha']);
    File::put($workspace->worktree.'/.loop/proof/request.json', '{"fixture":"independently corrected"}');
    taskRuntimeSubmit($correction);
    taskArtifactBind($workspace->refresh(), $correction);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    [$nextFinal, $nextCheck] = taskArtifactFinalFixture($workspace);
    $inputs = app(OrbitTaskProofEvidence::class)->inputs($workspace->refresh(), $nextCheck);
    expect($inputs['database']['accepted_tasks'])->toHaveCount(3)
        ->and($artifacts->acceptedBindings($workspace))->toHaveCount(2)
        ->and(TaskArtifactReviewBinding::query()->first()->getRawOriginal())->toBe($original)
        ->and($inputs['proof_contract'][1]['contents'])->toContain('independently corrected')
        ->and($nextCheck['sha'])->toBe($check['sha'])
        ->and($nextFinal->round)->toBe(1);
});

it('keeps artifact audit models append-only', function () {
    [$workspace, $implement] = taskArtifactHandoff();
    taskArtifactBind($workspace, $implement);
    $audit = TaskArtifactReviewBinding::query()->sole();
    expect(fn () => $audit->update(['binding_hash' => str_repeat('f', 64)]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $audit->delete())->toThrow(LogicException::class, 'retain history');
});

it('preserves legacy coding acceptance when the artifact audit table is absent', function () {
    Schema::drop('task_artifact_review_bindings');
    $workspace = taskRuntimeStart();
    $run = taskRuntimeAcceptNext($workspace, 'Normal code before migration');
    expect($run->commit_sha)->not->toBeNull()->and($run->status)->toBe(TaskRunStatus::Completed);
});

it('previews and records the artifact command without enqueueing or exposing private handoff tokens', function () {
    Queue::fake();
    [$workspace, $implement] = taskArtifactHandoff();
    $args = ['workspace' => $workspace->id, '--run' => $implement->task_run_id, '--dispatch' => $implement->id,
        '--round' => 1, '--manifest' => $workspace->manifest_hash, '--reason' => 'Only ignored proof artifacts changed.',
        '--exclusive' => true, '--drained' => true];
    expect(Artisan::call('tasks:bind-artifacts', $args))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['recorded'])->toBeFalse()->and(TaskArtifactReviewBinding::query()->count())->toBe(0);
    expect(Artisan::call('tasks:bind-artifacts', [...$args, '--binding' => $preview['binding_hash'], '--apply' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain($implement->handoff_token)
        ->and(TaskArtifactReviewBinding::query()->count())->toBe(1)
        ->and($workspace->dispatches()->orderByDesc('id')->first()->id)->toBe($implement->id);
    Queue::assertNothingPushed();
});

it('rejects artifact activation for recovered workspaces', function () {
    [$workspace, $implement] = taskArtifactHandoff();
    DB::table('task_recoveries')->insert(['id' => (string) Str::uuid(), 'root_task_id' => $workspace->root_task_id,
        'evidence_path' => '/fixture/evidence', 'evidence_sha256' => str_repeat('a', 64),
        'source_path' => '/fixture/source', 'source_sha256' => str_repeat('b', 64), 'backup_path' => '/fixture/backup',
        'backup_sha256' => str_repeat('c', 64), 'provenance' => '{}', 'created_at' => now()]);
    expect(fn () => taskArtifactBind($workspace, $implement))->toThrow(LogicException::class, 'recovered workspaces');
});

it('preserves artifact acceptance across a native current-main integration and real merge commit', function () {
    [$workspace, $implement, $artifacts] = taskArtifactHandoff();
    taskArtifactBind($workspace, $implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $acceptedArtifact = TaskArtifactReviewBinding::query()->sole()->getRawOriginal();
    taskRuntimeGit($workspace->worktree, ['remote', 'set-url', 'origin', $this->runtimeRepository]);
    File::put($this->runtimeRepository.'/main.txt', 'New upstream main');
    taskRuntimeGit($this->runtimeRepository, ['add', '--all']);
    taskRuntimeGit($this->runtimeRepository, ['commit', '--message=Upstream changed']);
    $held = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($held->state)->toBe('integration_required');
    $continued = app(ContinueTaskFinal::class)->handle($workspace->id, $held->id, $held->final_preflight['head'],
        $held->final_preflight['manifest_hash'], ['mode' => 'integrate_main', 'main_sha' => $held->final_preflight['main_sha'],
            'reason' => 'Include current main', 'evidence' => 'Native preflight missing ancestry'], true, true);
    $integration = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeGit($workspace->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    taskRuntimeSubmit($integration);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($commit->kind)->toBe('commit');
    taskRuntimeCommit();
    taskRuntimeSubmit($commit);
    $merged = $commit->run()->firstOrFail();
    expect(trim(taskRuntimeGit($workspace->worktree, ['show', '-s', '--format=%P', 'HEAD'])))
        ->toBe($held->final_preflight['head'].' '.$held->final_preflight['main_sha'])
        ->and(TaskArtifactReviewBinding::query()->sole()->getRawOriginal())->toBe($acceptedArtifact)
        ->and($artifacts->acceptedBindings($workspace))->toHaveCount(1);
    taskRuntimeGit($workspace->worktree, ['remote', 'set-url', 'origin', 'git@github.com:nckrtl/orbit.git']);
    [$final, $check] = taskArtifactFinalFixture($workspace);
    $inputs = app(OrbitTaskProofEvidence::class)->inputs($workspace->refresh(), $check);
    expect($inputs['repository']['candidate'])->toBe($merged->commit_sha)
        ->and($inputs['database']['accepted_tasks'][1]['commit_sha'])->toBeNull()
        ->and($inputs['database']['accepted_tasks'][2]['main_integration']['audit_id'])->toBe($continued['audit']['id']);
});

function taskArtifactIntegratedInputs(): TaskWorkspace
{
    $test = test();
    File::put($test->runtimeRepository.'/declared.txt', 'Original input');
    taskRuntimeGit($test->runtimeRepository, ['add', '--all']);
    taskRuntimeGit($test->runtimeRepository, ['commit', '--message=Declared input']);
    taskRuntimeGit($test->runtimeWorktree, ['merge', '--ff-only', 'main']);
    [$workspace, $implement] = taskArtifactHandoff(['declared.txt']);
    taskArtifactBind($workspace, $implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    taskRuntimeGit($workspace->worktree, ['remote', 'set-url', 'origin', $test->runtimeRepository]);
    File::put($test->runtimeRepository.'/declared.txt', 'Current main input');
    taskRuntimeGit($test->runtimeRepository, ['add', '--all']);
    taskRuntimeGit($test->runtimeRepository, ['commit', '--message=Changed declared input']);
    $held = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($held->state)->toBe('integration_required')->and($held->final_check)->toBeNull();
    app(ContinueTaskFinal::class)->handle($workspace->id, $held->id, $held->final_preflight['head'],
        $held->final_preflight['manifest_hash'], ['mode' => 'integrate_main', 'main_sha' => $held->final_preflight['main_sha'],
            'reason' => 'Include current main', 'evidence' => 'Native preflight missing ancestry'], true, true);
    $integration = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeGit($workspace->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    taskRuntimeSubmit($integration);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeCommit();
    taskRuntimeSubmit($commit);

    return $workspace->refresh();
}

it('holds changed accepted artifact inputs before publication with the real green Builder receipt and supports an audited correction', function () {
    config(['task-runtime.projects.orbit.final_command' => [PHP_BINARY, '-r', 'echo "Builder actually passed"; fwrite(STDERR, "actual stderr");']]);
    $workspace = taskArtifactIntegratedInputs();
    $original = TaskArtifactReviewBinding::query()->sole()->getRawOriginal();
    $runs = TaskRun::query()->orderBy('id')->get()->toArray();
    $prompts = $this->runtimeAgents->prompts;
    Process::fake(function ($process) {
        expect($process->command[0])->toBeIn(['git', PHP_BINARY]);
        $local = new LocalProcess($process->command, $process->path, $process->environment, timeout: 10);
        $local->run();

        return new ProcessResult($local);
    })->preventStrayProcesses();
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($final->state)->toBe('check_failed')->and($final->session)->toBeNull()->and($final->result)->toBeNull()
        ->and($final->error)->toContain('held before proof publication', 'Builder passed')
        ->and($final->final_check)->toMatchArray(['exit_code' => 0, 'candidate_unchanged' => true,
            'output' => 'Builder actually passed', 'error_output' => 'actual stderr'])
        ->and($final->final_check)->not->toHaveKey('native_proof')
        ->and($workspace->fresh()->final_check)->toBe($final->final_check)
        ->and($final->final_check['artifact_input_mismatch']['changes'])->toBe([['path' => 'declared.txt',
            'expected' => ['mode' => '100644', 'sha256' => hash('sha256', 'Original input')],
            'actual' => ['mode' => '100644', 'sha256' => hash('sha256', 'Current main input')]]])
        ->and($this->runtimeAgents->prompts)->toBe($prompts)
        ->and(TaskRun::query()->orderBy('id')->get()->toArray())->toBe($runs);
    Process::assertNotRan(fn ($process) => str_contains(implode(' ', $process->command), 'loop-artifacts')
        || str_contains(implode(' ', $process->command), 'e2e-topology'));
    Process::assertRanTimes(fn ($process) => $process->command === $workspace->configuration['final_command'], 1);
    expect(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and(fn () => taskFinalContinue($workspace, $final, taskFinalRequest('retry_final_checks')))->toThrow(LogicException::class, 'uncertain');
    $held = $final->getRawOriginal();
    expect(taskFinalContinue($workspace, $final, taskFinalRequest(), false)['applied'])->toBeFalse();
    $continued = taskFinalContinue($workspace, $final, taskFinalRequest());
    expect(taskFinalContinue($workspace, $final, taskFinalRequest())['recorded'])->toBeTrue();
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->task_run_id)->not->toBe($runs[array_key_last($runs)]['id'])
        ->and($correction->run()->firstOrFail()->task_id)->toBe($continued['audit']['task_id']);
    taskRuntimeSubmit($correction);
    taskArtifactBind($workspace->refresh(), $correction);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    expect(app(TaskArtifactReviews::class)->publicationInputMismatch($workspace->refresh()))->toBeNull()
        ->and(TaskArtifactReviewBinding::query()->first()->getRawOriginal())->toBe($original)
        ->and($final->fresh()->getRawOriginal())->toBe($held);
    app(TaskArtifactReviews::class)->verifyAcceptedArtifacts($workspace);
});

it('does not classify changed ignored artifacts or invalid inventory as a safe declared-input hold', function (string $case) {
    $workspace = taskArtifactIntegratedInputs();
    match ($case) {
        'contents' => File::put($workspace->worktree.'/.loop/proof/request.json', '{"changed":true}'),
        'inventory' => File::put($workspace->worktree.'/.loop/proof/unexpected.json', '{}'),
        'mode' => chmod($workspace->worktree.'/.loop/proof/request.json', 0600),
    };
    $prompts = $this->runtimeAgents->prompts;
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))->toThrow(LogicException::class)
        ->and($workspace->dispatches()->latest('id')->first()->state)->toBe('ambiguous')
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($this->runtimeAgents->prompts)->toBe($prompts);
})->with(['contents', 'inventory', 'mode']);

it('refuses an artifact-input continuation when the recorded observation changes', function () {
    $workspace = taskArtifactIntegratedInputs();
    $held = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($workspace->worktree.'/.loop/proof/request.json', '{"changed":true}');
    expect(fn () => taskFinalContinue($workspace, $held, taskFinalRequest()))->toThrow(LogicException::class)
        ->and(TaskFinalContinuation::query()->count())->toBe(1);
});

it('does not hold ignored external input changes as accepted candidate input drift', function (string $case) {
    File::ensureDirectoryExists($this->runtimeWorktree.'/vendor');
    File::put($this->runtimeWorktree.'/vendor/fixture.txt', 'Original ignored dependency');
    File::put($this->runtimeWorktree.'/vendor/retained.txt', 'Unchanged ignored dependency');
    [$workspace, $implement] = taskArtifactHandoff(['vendor']);
    taskArtifactBind($workspace, $implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    taskRuntimeGit($workspace->worktree, ['remote', 'set-url', 'origin', $this->runtimeRepository]);
    if ($case === 'deleted') {
        File::delete($workspace->worktree.'/vendor/fixture.txt');
    } elseif ($case === 'added') {
        File::put($workspace->worktree.'/vendor/extra.txt', 'Unexpected new dependency');
    } else {
        File::put($workspace->worktree.'/vendor/fixture.txt', 'Changed ignored dependency');
    }
    $prompts = $this->runtimeAgents->prompts;
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))
        ->toThrow(LogicException::class, $case === 'changed' ? 'exact candidate blobs and modes' : 'Proof input inventory changes')
        ->and($workspace->dispatches()->latest('id')->first()->state)->toBe('ambiguous')
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($this->runtimeAgents->prompts)->toBe($prompts);
})->with(['changed', 'deleted', 'added']);

it('refuses artifact-input continuation when its candidate changes during reviewer observation', function () {
    $workspace = taskArtifactIntegratedInputs();
    $held = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $this->runtimeAgents->duringObservation = function () use ($workspace) {
        taskRuntimeGit($workspace->worktree, ['commit', '--allow-empty', '--message=Unexpected candidate advance']);
    };
    expect(fn () => taskFinalContinue($workspace, $held, taskFinalRequest()))->toThrow(LogicException::class, 'uncertain')
        ->and(TaskFinalContinuation::query()->count())->toBe(1)
        ->and($workspace->fresh()->final_check)->toBe($held->final_check)
        ->and($workspace->fresh()->attention)->toBe($held->error);
});

function taskRuntimeGit(string $directory, array $arguments): string
{
    return Process::path($directory)->timeout(10)->env([
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
    ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output();
}

function taskRuntimeStart(?string $orbitFlow = null, bool $snapshotReplacement = false): TaskWorkspace
{
    $test = test();

    return app(StartTaskWorkspace::class)->handle($test->runtimeRoot, $test->runtimeWorktree,
        app(TaskRuntimePlan::class)->hash($test->runtimeRoot), 'ORBIT-TEST', true, $orbitFlow, $snapshotReplacement);
}

function taskRuntimeNativeFlow(string $contents): void
{
    File::append(test()->runtimeRepository.'/.git/info/exclude', "\n.loop/\n");
    File::ensureDirectoryExists(test()->runtimeWorktree.'/.loop');
    File::put(test()->runtimeWorktree.'/.loop/flow.json', $contents);
}

function taskRuntimeReceipt(TaskAgentDispatch $dispatch, ?string $verdict = null, string $summary = 'Checks passed.'): array
{
    if (preg_match('/"token": "([a-f0-9]{64})"/', $dispatch->prompt, $match) !== 1) {
        throw new RuntimeException('The generated prompt is missing its receipt token.');
    }
    $receipt = ['token' => $match[1], 'summary' => $summary, 'evidence' => 'Focused checks and disposable-machine observations passed.'];
    if ($verdict !== null) {
        $receipt['verdict'] = $verdict;
    }

    return $receipt;
}

function taskRuntimeSubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): TaskAgentDispatch
{
    return app(SubmitTaskDispatch::class)->handle($dispatch, taskRuntimeReceipt($dispatch, $verdict));
}

function taskRuntimeCommit(): string
{
    $test = test();
    taskRuntimeGit($test->runtimeWorktree, ['add', '--all']);
    taskRuntimeGit($test->runtimeWorktree, ['commit', '--message=Accepted task']);

    return trim(taskRuntimeGit($test->runtimeWorktree, ['rev-parse', 'HEAD']));
}

function taskRuntimeAcceptNext(TaskWorkspace $workspace, string $content): TaskRun
{
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($implement->kind)->toBe('implement');
    File::put(test()->runtimeWorktree.'/feature.txt', $content);
    taskRuntimeSubmit($implement);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($review->kind)->toBe('review');
    taskRuntimeSubmit($review, 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($commit->kind)->toBe('commit');
    taskRuntimeCommit();
    taskRuntimeSubmit($commit);

    return $commit->run()->firstOrFail();
}

final class TaskRuntimeReconnectObserver implements TaskSessionObserver
{
    public array $calls = [];

    public bool $changed = false;

    public ?Closure $during = null;

    public function observe(TaskWorkspace $workspace, array $session, string $conversation, bool $yielded): array
    {
        $this->calls[] = [$session, $conversation, $yielded];
        if ($this->during !== null) {
            ($this->during)();
        }

        return ['session' => $session, 'process' => ['pid' => str_contains($session['agentName'], 'reviewer') ? 101 : 102,
            'start_time' => $this->changed ? 'changed' : 'original', 'argv' => ['codex', 'resume', $conversation]]];
    }
}

function taskRuntimeReconnectRequest(TaskWorkspace $workspace, TaskAgentDispatch $current): array
{
    $reviewer = $workspace->dispatches()->where('kind', 'commit')->orderByDesc('id')->firstOrFail();
    $sessions = [];
    foreach (['implementer' => $current, 'reviewer' => $reviewer] as $role => $dispatch) {
        $uuid = $role === 'implementer' ? '11111111-1111-4111-8111-111111111111' : '22222222-2222-4222-8222-222222222222';
        $path = test()->runtimeDirectory.'/'.$role.'.jsonl';
        File::put($path, json_encode(['type' => 'session_meta', 'payload' => ['id' => $uuid, 'cwd' => $workspace->worktree]])."\n"
            .json_encode(['type' => 'event_msg', 'payload' => ['type' => 'user_message', 'message' => $dispatch->prompt]])."\n");
        chmod($path, 0600);
        $session = HerdrTaskLandingReviewer::identity($dispatch->session);
        $session['terminalId'] = 'new-'.$role;
        $sessions[$role] = ['conversation_id' => $uuid, 'session' => $session,
            'transcript' => ['path' => $path, 'sha256' => hash_file('sha256', $path)]];
    }

    return ['dispatch_id' => $current->id, 'head' => $current->run->base_sha, 'manifest' => $workspace->manifest_hash,
        'reason' => 'Verified reboot; exact native conversations manually restored.', 'sessions' => $sessions];
}

function taskRuntimeReconnection(): array
{
    test()->runtimeAgents->nullNative = true;
    $observer = new TaskRuntimeReconnectObserver;
    app()->instance(TaskSessionObserver::class, $observer);
    $workspace = app(StartTaskWorkspace::class)->handle(test()->runtimeRoot, test()->runtimeWorktree,
        app(TaskRuntimePlan::class)->hash(test()->runtimeRoot), 'ORB-91', true);
    taskRuntimeAcceptNext($workspace, 'first accepted task');
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($workspace->worktree.'/feature.txt', 'interrupted second task');
    File::put($workspace->worktree.'/new.txt', 'untracked work');
    $request = taskRuntimeReconnectRequest($workspace->refresh(), $dispatch);

    return [$workspace, $dispatch, $request, $observer, app(ReconnectTaskSessions::class)];
}

it('reconnects only operational routing while preserving original task and dispatch bytes', function () {
    [$workspace, $dispatch, $request, $observer, $action] = taskRuntimeReconnection();
    $dispatches = $workspace->dispatches()->get()->map->getRawOriginal()->all();
    $runs = TaskRun::query()->with('reviews')->get()->toArray();
    $before = $workspace->getRawOriginal();
    $starts = $this->runtimeAgents->starts;
    $prompts = $this->runtimeAgents->prompts;
    Queue::fake();
    $file = $this->runtimeDirectory.'/reconnect-request.json';
    File::put($file, json_encode($request, JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    expect(Artisan::call('tasks:reconnect-sessions', ['workspace' => $workspace->id, '--file' => $file, '--exclusive' => true]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($preview['recorded'])->toBeFalse()->and(TaskSessionReconnection::query()->count())->toBe(0)
        ->and($workspace->fresh()->getRawOriginal())->toBe($before);
    $recorded = $action->handle($workspace->id, $request, true, $preview['request_hash'], true);
    expect($recorded['recorded'])->toBeTrue()->and(TaskSessionReconnection::query()->count())->toBe(1)
        ->and($workspace->fresh()->reviewer_session['terminalId'])->toBe('new-reviewer')
        ->and(array_diff_key($workspace->fresh()->getRawOriginal(), ['reviewer_session' => true, 'updated_at' => true]))
        ->toBe(array_diff_key($before, ['reviewer_session' => true, 'updated_at' => true]))
        ->and($workspace->dispatches()->get()->map->getRawOriginal()->all())->toBe($dispatches)
        ->and(TaskRun::query()->with('reviews')->get()->toArray())->toBe($runs)
        ->and(File::get($workspace->worktree.'/feature.txt'))->toBe('interrupted second task')
        ->and(File::get($workspace->worktree.'/new.txt'))->toBe('untracked work')
        ->and($this->runtimeAgents->starts)->toBe($starts)->and($this->runtimeAgents->prompts)->toBe($prompts);
    $observer->during = fn () => throw new LogicException('Replay must not observe.');
    expect($action->handle($workspace->id, $request, true, $preview['request_hash'], true))->toBe($recorded);
    $conflict = [...$request, 'reason' => 'A different request cannot replace this audit.'];
    expect(fn () => $action->handle($workspace->id, $conflict, true))->toThrow(LogicException::class, 'conflicting');
    $audit = TaskSessionReconnection::query()->sole();
    expect(fn () => $audit->update(['request_hash' => str_repeat('f', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $audit->fresh()->delete())->toThrow(LogicException::class);
    Artisan::call('tasks:inspect', ['project' => 'orbit', 'task' => $workspace->root_task_id]);
    expect(Artisan::output())->toContain('session_reconnections')->not->toContain($dispatch->handoff_token, 'token_hash', 'handoff_token');
    Queue::assertNothingPushed();
});

it('normalizes reconnection JSON object ordering but rejects unknown identity fields', function () {
    [$workspace, $dispatch, $request, $observer, $action] = taskRuntimeReconnection();
    $a = $action->handle($workspace->id, $request, true);
    $reverse = function (array $value) use (&$reverse): array {
        return array_reverse(array_map(fn ($item) => is_array($item) ? $reverse($item) : $item, $value), true);
    };
    $reordered = $reverse($request);
    $b = $action->handle($workspace->id, $reordered, true);
    expect($b['request_hash'])->toBe($a['request_hash']);
    $action->handle($workspace->id, $reordered, true, $a['request_hash'], true);
    expect($action->handle($workspace->id, $request, true)['recorded'])->toBeTrue();
    $request['sessions']['implementer']['session']['unexpected'] = 'value';
    expect(fn () => $action->handle($workspace->id, $request, true))->toThrow(LogicException::class);
});

it('refuses reconnection on source process assignment or lock-time drift', function (string $case) {
    [$workspace, $dispatch, $request, $observer, $action] = taskRuntimeReconnection();
    $preview = $action->handle($workspace->id, $request, true);
    match ($case) {
        'source' => File::put($workspace->worktree.'/new.txt', 'changed'),
        'process' => $observer->changed = true,
        'dispatch' => $request['dispatch_id']++,
        'terminal' => $request['sessions']['implementer']['session']['terminalId'] = $dispatch->session['terminalId'],
        'name' => $request['sessions']['reviewer']['session']['agentName'] = 'another',
        'native' => $request['sessions']['reviewer']['session']['agentId'] = 'other',
        'state' => $dispatch->update(['state' => 'ambiguous']),
        'disabled' => config(['task-runtime.enabled' => false]),
        'lock-source', 'lock-process' => $observer->during = function () use ($observer, $case, $workspace) {
            if (count($observer->calls) === 5) {
                if ($case === 'lock-source') {
                    File::put($workspace->worktree.'/new.txt', 'changed');
                } else {
                    $observer->changed = true;
                }
            }
        },
    };
    expect(fn () => $action->handle($workspace->id, $request, true, $preview['request_hash'], true))->toThrow(LogicException::class)
        ->and(TaskSessionReconnection::query()->count())->toBe(0)
        ->and($workspace->fresh()->reviewer_session['terminalId'])->not->toBe('new-reviewer');
})->with(['source', 'process', 'dispatch', 'terminal', 'name', 'native', 'state', 'disabled', 'lock-source', 'lock-process']);

it('refuses unbound or unsafe native transcript evidence', function (string $case) {
    [$workspace, $dispatch, $request, $observer, $action] = taskRuntimeReconnection();
    $input = &$request['sessions']['implementer'];
    $path = $input['transcript']['path'];
    $contents = File::get($path);
    match ($case) {
        'uuid' => $contents = str_replace($input['conversation_id'], '33333333-3333-4333-8333-333333333333', $contents),
        'checkout' => $contents = str_replace(str_replace('/', '\/', $workspace->worktree), '/another', $contents),
        'token' => $contents = str_replace($dispatch->handoff_token, 'not-the-token', $contents),
        'dispatch' => $contents = str_replace('tasks:submit '.$dispatch->id.' ', 'tasks:submit 9999 ', $contents),
        'mode' => chmod($path, 0644),
        'hash' => $input['transcript']['sha256'] = str_repeat('f', 64),
        'secret' => $request['reason'] = $dispatch->handoff_token,
    };
    File::put($path, $contents);
    if ($case !== 'hash') {
        $input['transcript']['sha256'] = hash_file('sha256', $path);
    }
    expect(fn () => $action->handle($workspace->id, $request, true))->toThrow(LogicException::class)
        ->and(TaskSessionReconnection::query()->count())->toBe(0);
})->with(['uuid', 'checkout', 'token', 'dispatch', 'mode', 'hash', 'secret']);

it('uses reconnected sessions through correction commit next task final review and landing eligibility', function () {
    app(CreateTask::class)->handle('orbit', 'Third part', 'Finish integration.', parent: $this->runtimeRoot, acceptanceCriteria: 'Works.');
    [$workspace, $dispatch, $request, $observer, $action] = taskRuntimeReconnection();
    $before = $dispatch->getRawOriginal();
    $preview = $action->handle($workspace->id, $request, true);
    $action->handle($workspace->id, $request, true, $preview['request_hash'], true);
    $resolver = app(TaskAgentSessions::class);
    $this->runtimeAgents->duringPrompt = fn ($ws) => $resolver->assertProcess($ws, $ws->dispatches()->orderByDesc('id')->firstOrFail()->session);
    $effective = $resolver->resolve($workspace, $dispatch->session);
    expect($effective['agentId'])->toBeNull()->and($resolver->resolve($workspace, $effective))->toBe($effective)
        ->and(app(TaskTerminalSessions::class)->resolve($dispatch->run->task, 'implementer')->terminalId)->toBe('new-implementer');
    app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($dispatch->fresh()->getRawOriginal())->toBe($before);
    taskRuntimeSubmit($dispatch);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($review->session['terminalId'])->toBe('new-reviewer');
    taskRuntimeSubmit($review, 'revise');
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->session['terminalId'])->toBe('new-implementer')->and($correction->task_run_id)->toBe($dispatch->task_run_id);
    taskRuntimeAcceptNext($workspace, 'corrected second task');
    taskRuntimeAcceptNext($workspace, 'third task');
    expect(count($this->runtimeAgents->starts))->toBe(4);
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($final->kind)->toBe('final_review')->and($final->session['terminalId'])->toBe('new-reviewer');
    taskRuntimeSubmit($final, 'pass');
    $evidence = app(TaskLandingEvidence::class)->database($workspace->refresh(), ['candidate' => $final->final_check['sha'],
        'manifest' => $workspace->manifest_hash, 'final_dispatch' => $final->id]);
    expect(count($evidence['accepted_tasks']))->toBe(3)->and($workspace->root->status)->toBe(TaskStatus::Completed)
        ->and($workspace->dispatches()->where('step_key', $dispatch->step_key)->count())->toBe(1);
    $observer->changed = true;
    expect(fn () => $resolver->assertProcess($workspace, $effective))->toThrow(LogicException::class)
        ->and(fn () => $resolver->assertProcess($workspace, $correction->session))->toThrow(LogicException::class);
});

it('retains pre-proof evidence compatibility after original-history session reconnection', function () {
    $this->runtimeAgents->nullNative = true;
    app()->instance(TaskSessionObserver::class, new TaskRuntimeReconnectObserver);
    [$proofs, $workspace, $final, $check] = taskRuntimeProofEvidence(afterFirst: function ($workspace) {
        $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
        $request = taskRuntimeReconnectRequest($workspace->refresh(), $dispatch);
        $action = app(ReconnectTaskSessions::class);
        $preview = $action->handle($workspace->id, $request, true);
        $action->handle($workspace->id, $request, true, $preview['request_hash'], true);
    });
    $inputs = $proofs->inputs($workspace, $check);
    expect($inputs['schema'])->toBe(2)->and($inputs['database']['accepted_tasks'])->toHaveCount(2)
        ->and($workspace->reviewer_session['terminalId'])->toBe('new-reviewer');
});

it('admits the explicit immutable Orbit profile through the CLI without switching native flow', function (?string $flow, bool $replacement) {
    Queue::fake();
    $selected = $flow ?? 'discovery';
    taskRuntimeNativeFlow(json_encode(['schema' => 1, 'flow' => $selected], JSON_THROW_ON_ERROR));
    $native = File::get($this->runtimeWorktree.'/.loop/flow.json');
    $arguments = ['project' => 'orbit', 'task' => $this->runtimeRoot->id, '--worktree' => $this->runtimeWorktree,
        '--manifest' => app(TaskRuntimePlan::class)->hash($this->runtimeRoot), '--source' => 'ORBIT-TEST', '--exclusive' => true];
    if ($flow !== null) {
        $arguments['--orbit-flow'] = $flow;
    }
    if ($replacement) {
        $arguments['--snapshot-replacement'] = true;
    }
    $this->artisan('tasks:start', $arguments)->assertSuccessful();
    $workspace = TaskWorkspace::query()->sole();
    $before = $workspace->getRawOriginal();

    expect($workspace->configuration['orbit_profile'])->toBe(['schema' => 1, 'flow' => $selected, 'snapshot_replacement' => $replacement])
        ->and(taskRuntimeStart($flow, $replacement)->getRawOriginal())->toBe($before)
        ->and(File::get($this->runtimeWorktree.'/.loop/flow.json'))->toBe($native)
        ->and(TaskRun::query()->count())->toBe(0)->and($this->runtimeAgents->starts)->toBe([]);
    Queue::assertPushed(AdvanceTaskRunner::class, 1);
})->with([[null, false], ['discovery', false], ['proof', false], ['proof', true]]);

it('records an immutable Orbit Herdr placement when a new task workspace is admitted', function () {
    config([
        'task-runtime.projects.orbit.orbit_node_id' => 9,
        'task-runtime.projects.orbit.orbit_herdr_session_id' => 41,
        'task-runtime.projects.orbit.orbit_herdr_session' => 'commander-tasks',
        'task-runtime.projects.orbit.orbit_herdr_observer_origin' => 'wss://commander-tasks.herdr.beast.test',
    ]);

    $workspace = taskRuntimeStart();

    expect($workspace->orbit_node_id)->toBe(9)
        ->and($workspace->orbit_herdr_session_id)->toBe(41)
        ->and($workspace->orbit_herdr_session)->toBe('commander-tasks')
        ->and($workspace->orbit_herdr_observer_origin)->toBe('wss://commander-tasks.herdr.beast.test')
        ->and(fn () => $workspace->update(['orbit_herdr_session_id' => 42]))
        ->toThrow(LogicException::class, 'immutable');
});

it('rejects partial or unsafe Orbit Herdr placement configuration', function (array $placement) {
    config($placement);

    expect(fn () => taskRuntimeStart())
        ->toThrow(LogicException::class, 'valid node, session, and WSS observer origin')
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with([
    [[
        'task-runtime.projects.orbit.orbit_node_id' => 9,
        'task-runtime.projects.orbit.orbit_herdr_session_id' => null,
        'task-runtime.projects.orbit.orbit_herdr_session' => null,
        'task-runtime.projects.orbit.orbit_herdr_observer_origin' => null,
    ]],
    [[
        'task-runtime.projects.orbit.orbit_node_id' => 9,
        'task-runtime.projects.orbit.orbit_herdr_session_id' => 41,
        'task-runtime.projects.orbit.orbit_herdr_session' => 'commander-tasks',
        'task-runtime.projects.orbit.orbit_herdr_observer_origin' => 'https://commander-tasks.herdr.beast.test',
    ]],
    [[
        'task-runtime.projects.orbit.orbit_node_id' => 9,
        'task-runtime.projects.orbit.orbit_herdr_session_id' => 41,
        'task-runtime.projects.orbit.orbit_herdr_session' => 'commander-tasks',
        'task-runtime.projects.orbit.orbit_herdr_observer_origin' => 'wss://good.test; script-src *',
    ]],
]);

it('rejects invalid Orbit profile selections without admission or dispatch', function (?string $flow, bool $replacement) {
    Queue::fake();
    expect(fn () => taskRuntimeStart($flow, $replacement))->toThrow(LogicException::class, 'Invalid Orbit execution profile')
        ->and(TaskWorkspace::query()->count())->toBe(0)->and(TaskRun::query()->count())->toBe(0)
        ->and($this->runtimeAgents->prompts)->toBe([]);
    Queue::assertNothingPushed();
})->with([['unknown', false], ['', false], ['Proof', false], [null, true], ['discovery', true]]);

it('rejects an explicit Orbit flow flag with no value instead of defaulting it', function () {
    Queue::fake();
    $this->artisan('tasks:start', ['project' => 'orbit', 'task' => $this->runtimeRoot->id,
        '--worktree' => $this->runtimeWorktree, '--manifest' => app(TaskRuntimePlan::class)->hash($this->runtimeRoot),
        '--source' => 'ORBIT-TEST', '--exclusive' => true, '--orbit-flow' => null])
        ->expectsOutputToContain('Invalid Orbit execution profile')->assertFailed();
    expect(TaskWorkspace::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects Orbit native flow drift and malformed selections without rewriting them', function (?string $contents, ?string $flow) {
    if ($contents !== null) {
        taskRuntimeNativeFlow($contents);
    }
    expect(fn () => taskRuntimeStart($flow))->toThrow(LogicException::class)
        ->and(TaskWorkspace::query()->count())->toBe(0)->and($this->runtimeAgents->starts)->toBe([]);
    if ($contents !== null) {
        expect(File::get($this->runtimeWorktree.'/.loop/flow.json'))->toBe($contents);
    }
})->with([[null, 'proof'], ['{"schema":1,"flow":"discovery"}', 'proof'], ['{"schema":1,"flow":"proof"}', null],
    ['{', null], ['null', null], ['{"schema":"1","flow":"discovery"}', null], ['{"schema":1,"flow":"other"}', null]]);

it('rejects redirected native Orbit selection paths', function (bool $directory) {
    taskRuntimeNativeFlow('{"schema":1,"flow":"proof"}');
    $path = $this->runtimeWorktree.'/.loop'.($directory ? '' : '/flow.json');
    $target = $this->runtimeDirectory.'/redirected-flow';
    rename($path, $target);
    symlink($target, $path);
    expect(fn () => OrbitTaskProfile::assertNativeFlow($this->runtimeWorktree, OrbitTaskProfile::select('orbit', 'proof')))
        ->toThrow(LogicException::class)
        ->and(fn () => taskRuntimeStart('proof'))->toThrow(Exception::class)
        ->and(TaskWorkspace::query()->count())->toBe(0)->and(is_link($path))->toBeTrue();
})->with([false, true]);

it('never reinterprets an admitted Orbit profile from later flags or shared configuration', function () {
    taskRuntimeNativeFlow('{"schema":1,"flow":"proof"}');
    $workspace = taskRuntimeStart('proof', true);
    $before = $workspace->fresh()->getRawOriginal();
    config(['task-runtime.projects.orbit.orbit_profile' => ['schema' => 1, 'flow' => 'discovery', 'snapshot_replacement' => false]]);
    expect(fn () => taskRuntimeStart())->toThrow(LogicException::class, 'different Orbit execution profile')
        ->and(fn () => taskRuntimeStart('proof'))->toThrow(LogicException::class, 'different Orbit execution profile')
        ->and(taskRuntimeStart('proof', true)->getRawOriginal())->toBe($before)
        ->and($workspace->fresh()->getRawOriginal())->toBe($before);
});

it('keeps historical missing Orbit profiles as discovery without rewriting their configuration or prompts', function () {
    $workspace = taskRuntimeStart();
    $configuration = $workspace->configuration;
    unset($configuration['orbit_profile']);
    DB::table('task_workspaces')->where('id', $workspace->id)->update(['configuration' => json_encode($configuration, JSON_THROW_ON_ERROR)]);
    $workspace->refresh();
    $before = $workspace->getRawOriginal();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $assignment = $dispatch->getRawOriginal();
    $before = $workspace->fresh()->getRawOriginal();
    taskRuntimeNativeFlow('{"schema":1,"flow":"proof"}');
    config(['task-runtime.projects.orbit.instructions' => 'Changed shared instructions.']);

    expect(OrbitTaskProfile::forWorkspace($workspace))->toBe(['schema' => 1, 'flow' => 'discovery', 'snapshot_replacement' => false])
        ->and(taskRuntimeStart()->configuration)->toBe($configuration)
        ->and(fn () => taskRuntimeStart('proof'))->toThrow(LogicException::class, 'different Orbit execution profile')
        ->and($workspace->fresh()->configuration)->toBe($configuration)
        ->and($workspace->fresh()->getRawOriginal())->toBe($before)
        ->and($dispatch->fresh()->getRawOriginal())->toBe($assignment)
        ->and($dispatch->prompt)->toContain('Orbit execution profile: discovery; snapshot replacement: no');
});

it('fails closed on a malformed present Orbit profile in repeated admission and new prompts', function (mixed $profile) {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    DB::table('task_workspaces')->where('id', $workspace->id)->update([
        'configuration' => json_encode([...$workspace->configuration, 'orbit_profile' => $profile], JSON_THROW_ON_ERROR),
    ]);
    $workspace->refresh();
    $before = $workspace->getRawOriginal();
    expect(fn () => taskRuntimeStart())->toThrow(LogicException::class, 'Invalid Orbit execution profile')
        ->and(fn () => app(TaskAgentPrompt::class)->render($workspace, $dispatch, $dispatch->handoff_token, $dispatch->run()->firstOrFail()))
        ->toThrow(LogicException::class, 'Invalid Orbit execution profile')
        ->and($workspace->fresh()->getRawOriginal())->toBe($before)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
})->with([[null], ['invalid'], [[]], [['schema' => 1, 'flow' => 'proof']],
    [['schema' => '1', 'flow' => 'discovery', 'snapshot_replacement' => false]],
    [['schema' => 1, 'flow' => 'discovery', 'snapshot_replacement' => true]],
    [['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => 'false']],
    [['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => false, 'extra' => true]]]);

it('limits Orbit profile flags to Orbit while preserving generic admission', function (?string $flow, bool $replacement) {
    Queue::fake();
    app(SharedKnowledgeProjectRepository::class)->create('other', ['name' => 'Other', 'status' => 'active']);
    config(['task-runtime.projects.other' => config('task-runtime.projects.orbit')]);
    $root = app(CreateTask::class)->handle('other', 'Other work', 'Approved scope.', TaskKind::Group, acceptanceCriteria: 'Complete.');
    app(CreateTask::class)->handle('other', 'Implement', 'Scoped task.', parent: $root, acceptanceCriteria: 'Complete.');
    $arguments = ['project' => 'other', 'task' => $root->id, '--worktree' => $this->runtimeWorktree,
        '--manifest' => app(TaskRuntimePlan::class)->hash($root), '--source' => 'OTHER-1', '--exclusive' => true];
    if ($flow !== null) {
        $arguments['--orbit-flow'] = $flow === '' ? null : $flow;
    }
    if ($replacement) {
        $arguments['--snapshot-replacement'] = true;
    }
    if ($flow !== null || $replacement) {
        $this->artisan('tasks:start', $arguments)->expectsOutputToContain('only supported for Orbit')->assertFailed();
        expect(TaskWorkspace::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    } else {
        $this->artisan('tasks:start', $arguments)->assertSuccessful();
        $workspace = TaskWorkspace::query()->sole();
        expect($workspace->configuration)->not->toHaveKey('orbit_profile')
            ->and(OrbitTaskProfile::instructions($workspace))->toBe('');
    }
})->with([[null, false], ['discovery', false], ['proof', false], [null, true], ['', false]]);

it('renders the stored Orbit profile in every future role without changing frozen guidance or assignments', function (string $flow, bool $replacement) {
    taskRuntimeNativeFlow(json_encode(['schema' => 1, 'flow' => $flow], JSON_THROW_ON_ERROR));
    $configuration = require config_path('task-runtime.php');
    $instructions = $configuration['projects']['orbit']['instructions'];
    config(['task-runtime.projects.orbit.instructions' => $instructions]);
    $workspace = taskRuntimeStart($flow, $replacement);
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $before = $dispatch->getRawOriginal();
    config(['task-runtime.projects.orbit.instructions' => 'Changed shared guidance.',
        'task-runtime.projects.orbit.orbit_profile' => ['schema' => 1, 'flow' => 'discovery', 'snapshot_replacement' => false]]);
    foreach (['implement', 'review', 'commit', 'final_review'] as $kind) {
        $role = clone $dispatch;
        $role->kind = $kind;
        $role->state = 'prepared';
        $prompt = app(TaskAgentPrompt::class)->render($workspace, $role, $dispatch->handoff_token, $dispatch->run()->firstOrFail());
        expect($prompt)->toContain($instructions, 'Orbit execution profile: '.$flow.'; snapshot replacement: '.($replacement ? 'yes' : 'no'),
            'Do not substitute discovery for proof', 'Profile selection alone does not authorize operations',
            'follow the approved task and Commander project handler', 'Verify both modes before writing the token')
            ->not->toContain('Keep discovery flow', 'Changed shared guidance.');
        if ($replacement) {
            expect($prompt)->toContain('Keep required postmerge installation and verification gates pending',
                'C12 when named by the task', 'premerge assignments cannot sign them off or claim postmerge acceptance');
        } else {
            expect($prompt)->not->toContain('C12', 'postmerge installation');
        }
    }
    expect($workspace->fresh()->configuration['instructions'])->toBe($instructions)
        ->and($dispatch->fresh()->getRawOriginal())->toBe($before);
})->with([['discovery', false], ['proof', false], ['proof', true]]);

it('builds schema-2 pre-proof evidence from the exact accepted chain, Builder gate, plan, and fixtures', function () {
    [$evidence, $workspace, $final, $check] = taskRuntimeProofEvidence();
    $inputs = $evidence->inputs($workspace, $check);

    expect($inputs['schema'])->toBe(2)
        ->and($inputs['database']['final_dispatch_id'])->toBe($final->id)
        ->and($inputs['database']['builder_gate'])->toBe($check)
        ->and($inputs['database']['accepted_tasks'])->toHaveCount(2)
        ->and(array_column($inputs['proof_contract'], 'path'))->toBe([
            '.loop/proof/ORB-91.json', '.loop/proof/request.json',
        ])
        ->and($inputs['proof_contract'][0]['contents'])->toContain('"snapshot_replacement":true')
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($final->fresh()->final_check)->toBeNull();
});

it('rejects unsafe or incomplete schema-2 proof contracts before publication', function (string $defect) {
    [$evidence, $workspace, , $check] = taskRuntimeProofEvidence($defect);

    expect(fn () => $evidence->inputs($workspace, $check))->toThrow(LogicException::class);
})->with(['escape', 'declaration', 'missing', 'symlink', 'executable', 'nul', 'nested']);

it('captures safe native proof fixtures without requiring them in repository inputs', function () {
    [$evidence, $workspace, , $check] = taskRuntimeProofEvidence('unexpected');

    expect(array_column($evidence->inputs($workspace, $check)['proof_contract'], 'path'))
        ->toContain('.loop/proof/unexpected.json');
});

it('retains the explicitly declared snapshot reacquisition descriptor in the pre-proof artifact inputs', function () {
    [$evidence, $workspace, , $check] = taskRuntimeProofEvidence('reacquire');

    expect(array_column($evidence->inputs($workspace, $check)['proof_contract'], 'path'))->toContain('.loop/proof/snapshot-reacquire.json');
});

it('refuses proof publication for a failed gate, changed candidate, unaccepted task, or private handoff material', function (string $defect) {
    [$evidence, $workspace, $final, $check] = taskRuntimeProofEvidence();
    if ($defect === 'failed-gate') {
        $check['exit_code'] = 1;
    } elseif ($defect === 'changed-candidate') {
        $check['candidate_unchanged'] = false;
    } elseif ($defect === 'unaccepted-task') {
        DB::table('tasks')->where('id', $this->runtimeFirst->id)->update(['status' => TaskStatus::Pending->value]);
    } else {
        File::put($workspace->worktree.'/.loop/proof/request.json', $final->handoff_token);
    }

    expect(fn () => $evidence->inputs($workspace, $check))->toThrow(LogicException::class)
        ->and($workspace->fresh()->final_check)->toBeNull()->and($final->fresh()->final_check)->toBeNull();
})->with(['failed-gate', 'changed-candidate', 'unaccepted-task', 'private-token']);

it('publishes and captures native proof before prompting and keeps uncertain effects ambiguous', function (?string $failure) {
    app()->useStoragePath($this->runtimeDirectory.'/storage');
    [, $workspace, , $check] = taskRuntimeProofEvidence(createFinal: false);
    taskRuntimeGit($workspace->worktree, ['update-ref', 'refs/remotes/origin/main', $this->runtimeBase]);
    $candidate = $check['sha'];
    $attempt = str_repeat('1', 32);
    $planHash = hash_file('sha256', $workspace->worktree.'/.loop/proof/ORB-91.json');
    $prove = ['status' => 'proved', 'issue' => 'ORB-91', 'attempt_id' => $attempt,
        'candidate_sha' => $candidate, 'plan_sha256' => $planHash, 'manifest_sha256' => str_repeat('2', 64),
        'actions' => [['id' => 'proof', 'node' => 'app-prod-1', 'exit_code' => 0]]];
    $topology = ['issue' => 'ORB-91', 'attempt_id' => $attempt, 'purpose' => 'proof',
        'construction' => ['snapshot_replacement' => true],
        'source' => ['host_sha' => $candidate, 'guest_sha' => $candidate], 'verification' => ['passed' => true]];
    $capture = ['schema' => 1, 'issue' => 'ORB-91', 'attempt_id' => $attempt,
        'candidate_sha' => $candidate, 'plan_sha256' => $planHash, 'manifest_sha256' => str_repeat('2', 64),
        'proof' => $prove, 'topology' => $topology, 'manifest' => [], 'fingerprint' => str_repeat('3', 64)];
    for ($index = 0; $index < 125; $index++) {
        $capture['manifest']['inputs'][] = ['path' => 'apps/e2e/app/E2E/Fixture'.$index.'.php',
            'classification' => 'runtime', 'mode' => '100644', 'blob' => hash('sha1', (string) $index)];
    }
    $status = ['state' => 'proof', 'issue' => 'ORB-91', 'worktree' => $workspace->worktree,
        'proof' => $prove, 'capture' => array_diff_key($capture, ['proof' => true, 'topology' => true, 'manifest' => true]),
        'retained_topology' => $topology, 'review_record' => null, 'review_evaluation' => null,
        'closeout' => null, 'snapshot_replacement' => null];
    File::ensureDirectoryExists($workspace->repository.'/bin');
    foreach (['loop-artifacts', 'e2e-topology'] as $script) {
        File::put($workspace->repository.'/bin/'.$script, "#!/bin/sh\nexit 99\n");
        chmod($workspace->repository.'/bin/'.$script, 0755);
    }
    $local = function (array $command, array $environment = [], ?string $path = null) use ($workspace): LocalProcess {
        $process = new LocalProcess($command, $path ?? $workspace->worktree,
            [...TaskProcessEnvironment::isolated(), 'GIT_CONFIG_GLOBAL' => '/dev/null',
                'GIT_CONFIG_NOSYSTEM' => '1', ...$environment], timeout: 10);
        $process->run();

        return $process;
    };
    $artifact = null;
    $operations = [];
    $ref = 'refs/tags/loop/orb-91/'.$candidate;
    Process::fake(function ($process) use ($workspace, $candidate, $capture, $prove, &$status, &$artifact, &$operations, $local, $ref, $failure) {
        expect($process->path)->toBeIn([$workspace->repository, $workspace->worktree]);
        $command = $process->command;
        if (($command[0] ?? '') === 'git' && in_array('fetch', $command, true)) {
            expect(array_slice($command, -4))->toBe(['fetch', '--no-tags', 'origin', '+refs/heads/main:refs/remotes/origin/main']);

            return Process::result();
        }
        if (($command[0] ?? '') === 'git' && array_slice($command, -4) === ['ls-remote', '--exit-code', 'origin', 'refs/heads/main']) {
            return Process::result(output: trim($local(['git', 'rev-parse', 'refs/remotes/origin/main'])->getOutput())."\trefs/heads/main\n");
        }
        if (($command[0] ?? '') === $workspace->repository.'/bin/loop-artifacts') {
            expect($command)->toBe([$workspace->repository.'/bin/loop-artifacts', 'publish', 'ORB-91']);
            $operations[] = 'publish';
            if ($failure === 'publication') {
                throw new RuntimeException('Uncertain publication transport.');
            }
            $environment = ['GIT_INDEX_FILE' => $workspace->repository.'/artifact-index'];
            foreach ([['read-tree', $candidate], ['add', '-f', '--', '.loop']] as $arguments) {
                expect($local(['git', ...$arguments], $environment)->isSuccessful())->toBeTrue();
            }
            $tree = trim($local(['git', 'write-tree'], $environment)->getOutput());
            $result = $local(['git', 'commit-tree', $tree, '-p', $candidate, '-m', 'Frozen pre-proof artifact']);
            expect($result->isSuccessful())->toBeTrue();
            $artifact = trim($result->getOutput());
            expect($local(['git', 'update-ref', $ref, $artifact])->isSuccessful())->toBeTrue();

            return Process::result();
        }
        if ($command === ['git', '--no-replace-objects', 'ls-remote', '--refs', 'origin', $ref]) {
            return Process::result(output: $artifact === null ? '' : $artifact."\t".$ref."\n");
        }
        if (($command[0] ?? '') === $workspace->worktree.'/bin/e2e-topology') {
            $action = $command[1];
            $operations[] = $action;
            if ($failure === 'prove' && $action === 'prove') {
                $status['proof'] = null;
                throw new RuntimeException('Uncertain proof transport.');
            }
            expect($artifact)->not->toBeNull();
            $response = match ($action) {
                'prove' => $prove, 'capture' => $capture, 'status' => $status,
            };

            return Process::result(output: json_encode($response, JSON_THROW_ON_ERROR));
        }
        expect($command[0])->toBeIn(['git', PHP_BINARY]);

        return new ProcessResult($local($command, $process->environment, $process->path));
    })->preventStrayProcesses();
    $promptCount = count($this->runtimeAgents->prompts);
    $this->runtimeAgents->duringPrompt = function () use (&$operations): void {
        expect($operations)->toBe(['publish', 'prove', 'capture', 'status']);
    };
    if ($failure !== null) {
        $this->runtimeAgents->failNextPrompt = $failure === 'prompt';
        expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))->toThrow(match ($failure) {
            'publication' => LogicException::class, 'prove' => InvalidArgumentException::class, 'prompt' => RuntimeException::class,
        });
        $final = $workspace->dispatches()->latest('id')->firstOrFail();
        expect($final->state)->toBe('ambiguous')
            ->and($this->runtimeAgents->prompts)->toHaveCount($promptCount + ($failure === 'prompt' ? 1 : 0))
            ->and($final->final_check ?? [])->not->toHaveKey('artifact_input_mismatch')
            ->and(fn () => app(ContinueTaskFinal::class)->handle($workspace->id, $final->id, $candidate,
                $check['manifest_hash'], taskFinalRequest(), true, true))->toThrow(LogicException::class);
        $effects = $operations;
        expect(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()->and($operations)->toBe($effects);

        return;
    }
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($final->state)->toBe('sent')->and($final->kind)->toBe('final_review')
        ->and($this->runtimeAgents->prompts)->toHaveCount($promptCount + 1)
        ->and($final->prompt)->toContain('exact retained proof topology', $attempt, $artifact)
        ->and($workspace->fresh()->final_check['native_proof'])->toBe($final->final_check['native_proof']);
    $reference = app(TaskProofReviewFiles::class)->reference($workspace->id, $final->final_check);
    expect(TaskLandingReviewTransport::inspect($final->session, $final->prompt)['wire_bytes'])
        ->toBeLessThan(TaskLandingReviewTransport::MAX_REQUEST_BYTES)
        ->and($final->prompt)->toContain($reference['path'], $reference['raw_sha256'], 'final_check.retained_evidence')
        ->and($final->prompt)->not->toContain('Fixture124.php', '"artifact_inputs":')
        ->and(TaskLandingData::file($reference['path'], 8_388_608))->toBe(TaskLandingData::json($final->final_check))
        ->and(hash_file('sha256', $reference['path']))->toBe($reference['raw_sha256'])
        ->and(filesize($reference['path']))->toBe($reference['raw_bytes'])
        ->and(fileperms($reference['path']) & 0777)->toBe(0600)
        ->and(fileperms(dirname($reference['path'])) & 0777)->toBe(0700);
    $retainedCheck = TaskLandingData::file($reference['path'], 8_388_608);
    File::put($reference['path'], '{}');
    expect(fn () => taskRuntimeSubmit($final, 'pass'))->toThrow(LogicException::class, 'private proof review evidence changed')
        ->and($workspace->root()->firstOrFail()->status)->toBe(TaskStatus::Pending)
        ->and($final->fresh()->state)->toBe('sent');
    File::put($reference['path'], $retainedCheck);
    expect(fn () => taskRuntimeSubmit($final, 'pass'))->toThrow(InvalidArgumentException::class)
        ->and($workspace->root()->firstOrFail()->status)->toBe(TaskStatus::Pending)
        ->and($final->fresh()->state)->toBe('sent');

    $review = ['schema' => 1, 'issue' => 'ORB-91', 'candidate_sha' => $candidate,
        'attempt_id' => $attempt, 'actions' => [['id' => 'browser', 'required' => true, 'status' => 'passed']]];
    $evaluation = ['schema' => 1, 'issue' => 'ORB-91', 'candidate_sha' => $candidate,
        'attempt_id' => $attempt, 'status' => 'ready', 'required_incomplete' => [], 'required_failed' => [], 'exploratory_failed' => []];
    $status = [...$status, 'review_record' => $review, 'review_evaluation' => $evaluation];
    foreach (['proof-evidence' => $capture, 'proof-review' => $review, 'proof-review-evaluation' => $evaluation] as $directory => $value) {
        $path = $workspace->repository.'/.e2e/'.$directory.'/ORB-91/'.$attempt.'.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($value, JSON_THROW_ON_ERROR));
    }
    taskRuntimeSubmit($final->fresh(), 'pass');
    expect($workspace->root()->firstOrFail()->status)->toBe(TaskStatus::Completed)
        ->and($workspace->fresh()->final_result['native_proof_review']['review_evaluation']['status'])->toBe('ready')
        ->and($final->fresh()->state)->toBe('acknowledged')
        ->and(array_count_values($operations)['prove'])->toBe(1)
        ->and(array_count_values($operations)['capture'])->toBe(1)
        ->and(array_count_values($operations)['publish'])->toBe(1);
})->with([null, 'publication', 'prove', 'prompt']);

it('requires private-from-creation handoffs for every runtime role without changing the assignment', function (string $kind) {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $before = $dispatch->getRawOriginal();
    $role = clone $dispatch;
    $role->kind = $kind;
    $prompt = app(TaskAgentPrompt::class)->render($workspace, $role, $dispatch->handoff_token, $dispatch->run()->firstOrFail());

    expect($prompt)->toContain('Create the handoff privately from the start',
        'fresh mktemp -d directory outside the checkout (mode 0700)',
        'create the empty JSON file with mode 0600 under umask 077',
        'Verify both modes before writing the token', 'do not write it first and chmod afterward',
        'Quote the generated absolute file path in --file',
        escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tasks:submit '.$dispatch->id.' --file=',
        'Do not print or commit the handoff token', 'stop all work and wait for a new Commander prompt')
        ->and($dispatch->fresh()->getRawOriginal())->toBe($before)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
})->with(['implement', 'review', 'commit', 'final_review']);

it('runs two tasks sequentially with same-session corrections and a separate root final review', function () {
    $admittedInstructions = config('task-runtime.projects.orbit.instructions');
    $workspace = taskRuntimeStart();
    config(['task-runtime.projects.orbit.instructions' => 'Replacement guidance for future workspaces only.']);
    expect(taskRuntimeStart()->id)->toBe($workspace->id)->and($this->runtimeAgents->prompts)->toBe([]);
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $firstRun = $implement->run()->firstOrFail();
    expect($implement->kind)->toBe('implement')->and($implement->round)->toBe(0)
        ->and($implement->session['agentStatus'])->toBe('working')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::Running)
        ->and($this->runtimeSecond->runs()->count())->toBe(0)
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace)->id)->toBe($implement->id)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
    File::put($this->runtimeWorktree.'/feature.txt', 'first version');
    taskRuntimeSubmit($implement);
    taskRuntimeSubmit($implement);
    expect($firstRun->fresh()->commit_sha)->toBeNull()
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($firstRun->reviews()->count())->toBe(1)
        ->and(trim(taskRuntimeGit($this->runtimeWorktree, ['rev-parse', 'HEAD'])))->toBe($this->runtimeBase);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($review, 'revise');
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->kind)->toBe('implement')->and($correction->round)->toBe(1)
        ->and($correction->task_run_id)->toBe($firstRun->id)
        ->and($correction->session)->toBe($implement->session)
        ->and($this->runtimeFirst->runs()->count())->toBe(1)
        ->and($this->runtimeAgents->starts)->toHaveCount(2);
    File::put($this->runtimeWorktree.'/feature.txt', 'corrected first part');
    taskRuntimeSubmit($correction);
    $secondReview = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($secondReview->session)->toBe($review->session)->and($secondReview->round)->toBe(2);
    taskRuntimeSubmit($secondReview, 'pass');
    $passedReview = $firstRun->reviews()->where('round', $secondReview->round)->sole();
    $commitInstruction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $assignmentContext = json_decode(
        explode("\n\nAssignment context:\n", $commitInstruction->prompt, 2)[1],
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($commitInstruction->kind)->toBe('commit')
        ->and($commitInstruction->session)->toBe($review->session)
        ->and($commitInstruction->prompt)->toContain(
            'report only the commit SHA, reviewed tree SHA, assigned base as sole parent, and clean-worktree result',
            'Cite the passed review ID and round',
        )
        ->and($assignmentContext)->toBe([
            'root_id' => $this->runtimeRoot->id,
            'task' => ['id' => $this->runtimeFirst->id, 'title' => $this->runtimeFirst->title],
            'run_id' => $firstRun->id,
            'base_sha' => $this->runtimeBase,
            'round' => $commitInstruction->round,
            'passed_review' => [
                'id' => $passedReview->id,
                'round' => $passedReview->round,
                'tree_sha' => $passedReview->tree_sha,
                'verdict' => $passedReview->verdict->value,
                'reviewer_ref' => $firstRun->reviewer_ref,
            ],
        ])
        ->and($assignmentContext)->not->toHaveKeys([
            'root', 'latest_review', 'accepted_tasks', 'final_check', 'reattempt', 'reattempt_checkpoint', 'final_continuation',
        ])
        ->and($commitInstruction->prompt)->not->toContain(
            'Both parts work together, with passing final evidence.',
            'Focused checks and disposable-machine observations passed.',
        )
        ->and($this->runtimeSecond->runs()->count())->toBe(0)
        ->and($firstRun->fresh()->commit_sha)->toBeNull();
    $firstCommit = taskRuntimeCommit();
    taskRuntimeSubmit($commitInstruction);
    taskRuntimeSubmit($commitInstruction);
    $next = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $secondRun = $next->run()->firstOrFail();
    expect($firstRun->fresh()->status)->toBe(TaskRunStatus::Completed)
        ->and($firstRun->fresh()->commit_sha)->toBe($firstCommit)
        ->and($firstRun->reviews()->orderBy('round')->pluck('round')->all())->toBe([1, 2])
        ->and($firstRun->reviews()->where('round', 1)->sole()->verdict)->toBe(TaskReviewVerdict::Revise)
        ->and($secondRun->base_sha)->toBe($firstCommit)
        ->and($secondRun->worker_ref)->not->toBe($firstRun->worker_ref)
        ->and($secondRun->reviewer_ref)->toBe($firstRun->reviewer_ref)
        ->and($next->session['agentId'])->not->toBe($implement->session['agentId']);
    $acceptedSecond = taskRuntimeAcceptNext($workspace, 'integrated feature');
    expect($acceptedSecond->id)->toBe($secondRun->id)
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($workspace->fresh()->final_result)->toBeNull();
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($final->kind)->toBe('final_review')->and($final->task_run_id)->toBeNull()
        ->and($final->session)->toBe($review->session)
        ->and($workspace->fresh()->final_check['exit_code'])->toBe(0)
        ->and($workspace->fresh()->final_check['sha'])->toBe($acceptedSecond->commit_sha)
        ->and($final->prompt)->toContain('Both parts work together', 'final_check', $firstCommit, $acceptedSecond->commit_sha)
        ->and($final->prompt)->toContain('Account for every accepted task in a compact coverage list',
            'reuse only your own recorded independent assessment when the assignment context identifies the same reviewer, task/run, and source tree',
            'Cite the prior review ID and tree once',
            'do not manually hash, byte-count, copy, or restate unchanged handoff and review evidence',
            'Native identity and tree binding establishes which recorded assessment is yours and unchanged, not that its judgment was correct or its evidence adequate',
            "Another agent's summary or approval is not your assessment",
            'Inspect all new, changed, unresolved or inadequately recorded content completely',
            'If your own prior assessment is unavailable or its native binding does not match, perform the full relevant independent review',
            'Always judge integration, cross-task interactions, every root acceptance criterion and all changed or unresolved claims',
            'Inspect the actual new coordinator final-check result completely',
            'Submit a fresh verdict for this exact assignment and integrated tree; prior approval never transfers')
        ->and($final->prompt)->not->toContain('full-byte evidence-hash bindings')
        ->and($this->runtimeRoot->fresh()->completed_at)->toBeNull()
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace)->id)->toBe($final->id);
    taskRuntimeSubmit($final, 'pass');
    taskRuntimeSubmit($final, 'pass');
    expect($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($workspace->fresh()->final_result['verdict'])->toBe('pass')
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and(TaskRun::query()->count())->toBe(2)
        ->and($workspace->dispatches()->count())->toBe(9)
        ->and($this->runtimeAgents->prompts)->toHaveCount(9)
        ->and($this->runtimeAgents->starts)->toHaveCount(3)
        ->and($workspace->fresh()->configuration['instructions'])->toBe($admittedInstructions);

    foreach ($this->runtimeAgents->prompts as $prompt) {
        expect($prompt['prompt'])->toContain($admittedInstructions)
            ->not->toContain('Replacement guidance for future workspaces only.');
    }
});

it('uses current project guidance when admitting a new workspace', function () {
    $configuration = require config_path('task-runtime.php');
    $instructions = $configuration['projects']['orbit']['instructions'];
    config(['task-runtime.projects.orbit.instructions' => $instructions]);

    $workspace = taskRuntimeStart();
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);

    expect($workspace->configuration['instructions'])->toBe($instructions)
        ->and($implement->prompt)->toContain($instructions)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1)
        ->and($this->runtimeAgents->prompts[0]['prompt'])->toBe($implement->prompt);
});

it('rejects startup without explicit adoption and an unchanged approved task manifest', function (string $case) {
    $manifest = app(TaskRuntimePlan::class)->hash($this->runtimeRoot);
    if ($case === 'disabled') {
        config(['task-runtime.enabled' => false]);
    } elseif ($case === 'stale manifest') {
        $manifest = str_repeat('0', 64);
    }
    expect(fn () => app(StartTaskWorkspace::class)->handle($this->runtimeRoot, $this->runtimeWorktree, $manifest,
        $case === 'missing source' ? '' : 'ORBIT-TEST', $case !== 'no adoption'))
        ->toThrow(LogicException::class)
        ->and(TaskWorkspace::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0)
        ->and($this->runtimeAgents->starts)->toBe([]);
})->with(['disabled', 'stale manifest', 'no adoption', 'missing source']);

it('refuses execution after the adopted manifest changes', function () {
    $workspace = taskRuntimeStart();
    $this->runtimeFirst->update(['description' => 'Changed after adoption.']);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))->toThrow(LogicException::class, 'manifest')
        ->and(TaskRun::query()->count())->toBe(0)->and($this->runtimeAgents->prompts)->toBe([]);
});

it('rejects invalid or malformed receipts without advancing the task', function (string $case) {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $receipt = taskRuntimeReceipt($dispatch);
    if ($case === 'token') {
        $receipt['token'] = str_repeat('0', 64);
    } elseif ($case === 'missing token') {
        unset($receipt['token']);
    } elseif ($case === 'verdict') {
        $receipt['verdict'] = 'pass';
    } elseif ($case === 'unknown field') {
        $receipt['next_task_id'] = $this->runtimeSecond->id;
    } else {
        $receipt[$case] = '';
    }
    expect(fn () => app(SubmitTaskDispatch::class)->handle($dispatch, $receipt))->toThrow(InvalidArgumentException::class)
        ->and($dispatch->fresh()->state)->toBe('sent')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::Running)
        ->and($dispatch->run()->firstOrFail()->reviews()->count())->toBe(0)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
})->with(['token', 'missing token', 'summary', 'evidence', 'verdict', 'unknown field']);

it('keeps acknowledged receipts immutable and rejects an old token on a new instruction', function () {
    $workspace = taskRuntimeStart();
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/feature.txt', 'ready');
    $receipt = taskRuntimeReceipt($implement);
    app(SubmitTaskDispatch::class)->handle($implement, $receipt);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect(app(SubmitTaskDispatch::class)->handle($implement, $receipt)->state)->toBe('acknowledged')
        ->and(fn () => app(SubmitTaskDispatch::class)->handle($implement, [...$receipt, 'summary' => 'Replacement']))
        ->toThrow(LogicException::class)
        ->and(fn () => app(SubmitTaskDispatch::class)->handle($review, [...taskRuntimeReceipt($review, 'pass'), 'token' => $receipt['token']]))
        ->toThrow(InvalidArgumentException::class)
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($this->runtimeAgents->prompts)->toHaveCount(2);
});

it('rejects a valid token when the active task has already moved to another round', function () {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $run = $dispatch->run()->firstOrFail();
    $tree = app(GitTaskWorktree::class)->snapshot($this->runtimeWorktree, $this->runtimeBase);
    app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 1, ['summary' => 'Independent handoff.'], $tree);
    expect(fn () => taskRuntimeSubmit($dispatch))->toThrow(LogicException::class, 'active task run and round')
        ->and($dispatch->fresh()->state)->toBe('sent')->and($run->reviews()->count())->toBe(1);
});

it('does not accept a reviewer commit whose real Git tree differs from the reviewed snapshot', function () {
    $workspace = taskRuntimeStart();
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/feature.txt', 'reviewed');
    taskRuntimeSubmit($implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/feature.txt', 'unreviewed drift');
    taskRuntimeCommit();
    expect(fn () => taskRuntimeSubmit($commit))->toThrow(RuntimeException::class, 'exact reviewed tree')
        ->and($commit->fresh()->state)->toBe('sent')
        ->and($commit->run()->firstOrFail()->commit_sha)->toBeNull()
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::AwaitingCommit)
        ->and($this->runtimeSecond->runs()->count())->toBe(0)
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace)->id)->toBe($commit->id)
        ->and($this->runtimeAgents->prompts)->toHaveCount(3);
});

it('rejects review receipts if the submitted working tree changed', function () {
    $workspace = taskRuntimeStart();
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/feature.txt', 'reviewed');
    taskRuntimeSubmit($implement);
    $review = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/unexpected.txt', 'drift');
    expect(fn () => taskRuntimeSubmit($review, 'pass'))->toThrow(RuntimeException::class, 'changed after')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($review->run()->firstOrFail()->reviews()->sole()->verdict)->toBeNull();
});

it('never replays an ambiguous prompt and permits its matching late receipt to resolve it', function () {
    $workspace = taskRuntimeStart();
    $this->runtimeAgents->failNextPrompt = true;
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))->toThrow(RuntimeException::class, 'timed out');
    $dispatch = $workspace->dispatches()->sole();
    expect($dispatch->state)->toBe('ambiguous')->and($dispatch->session)->not->toBeNull()
        ->and($workspace->fresh()->attention)->toContain('will not be resent')
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and($this->runtimeAgents->starts)->toHaveCount(1)->and($this->runtimeAgents->prompts)->toHaveCount(1);
    File::put($this->runtimeWorktree.'/feature.txt', 'late completed implementation');
    taskRuntimeSubmit($dispatch);
    expect($dispatch->fresh()->state)->toBe('acknowledged')->and($workspace->fresh()->attention)->toBeNull()
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace)->kind)->toBe('review')
        ->and($this->runtimeAgents->prompts)->toHaveCount(2);
});

it('preserves a handoff received while the prompt request is still in flight', function () {
    $workspace = taskRuntimeStart();
    $this->runtimeAgents->duringPrompt = function (TaskWorkspace $workspace): void {
        $dispatch = $workspace->dispatches()->orderByDesc('id')->firstOrFail();
        expect($dispatch->state)->toBe('prompting');
        taskRuntimeSubmit($dispatch);
    };
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($dispatch->state)->toBe('acknowledged')
        ->and($dispatch->result['summary'])->toBe('Checks passed.')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
});

it('pauses on an explicit blocked handoff without accepting the task', function () {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $receipt = taskRuntimeReceipt($dispatch, 'blocked', 'Required machine evidence is unavailable.');
    app(SubmitTaskDispatch::class)->handle($dispatch, $receipt);
    expect($workspace->fresh()->attention)->toBe('Required machine evidence is unavailable.')
        ->and($dispatch->fresh()->state)->toBe('acknowledged')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::Running)
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
});

it('requires successful final checks before the reviewer can close the root', function (string $case) {
    config(['task-runtime.projects.orbit.final_command' => [PHP_BINARY, '-r', $case === 'failure'
        ? 'exit(7);' : 'file_put_contents("unexpected.txt", "drift");']]);
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first task');
    taskRuntimeAcceptNext($workspace, 'second task');
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($dispatch->state)->toBe('check_failed')
        ->and($dispatch->final_check['exit_code'])->toBe($case === 'failure' ? 7 : 0)
        ->and($dispatch->final_check['candidate_unchanged'])->toBe($case === 'failure')
        ->and($dispatch->session)->toBeNull()
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($workspace->fresh()->final_result)->toBeNull()
        ->and($workspace->fresh()->attention)->not->toBeNull()
        ->and($this->runtimeAgents->prompts)->toHaveCount(6);
})->with(['failure', 'candidate changed']);

it('runs the final project check without the coordinator environment', function () {
    config(['task-runtime.projects.orbit.final_command' => [PHP_BINARY, '-r',
        'echo json_encode(array_map(getenv(...), ["DB_DATABASE", "DB_URL", "APP_URL", "APP_BASE_PATH", "APP_CONFIG_CACHE", "COMMANDER_FINAL_CHECK_SECRET"]));',
    ]]);
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first task');
    taskRuntimeAcceptNext($workspace, 'second task');
    $previous = $_ENV;
    $_ENV['DB_URL'] = 'sqlite:///'.$this->runtimeDirectory.'/coordinator-only.sqlite';
    $_ENV['APP_URL'] = 'https://commander.test';
    $_ENV['COMMANDER_FINAL_CHECK_SECRET'] = 'coordinator-only';

    try {
        $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    } finally {
        $_ENV = $previous;
    }

    expect($final->kind)->toBe('final_review')
        ->and($workspace->fresh()->final_check['exit_code'])->toBe(0)
        ->and(json_decode($workspace->fresh()->final_check['output'], true, flags: JSON_THROW_ON_ERROR))
        ->toBe([false, false, false, false, false, false]);
});

it('keeps a root requiring final revisions incomplete after every child is accepted', function () {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first task');
    taskRuntimeAcceptNext($workspace, 'second task');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($final, 'revise');
    expect($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($workspace->fresh()->final_result['verdict'])->toBe('revise')
        ->and($workspace->fresh()->attention)->not->toBeNull()
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull();
});

it('rejects an early implementer commit and preserves the original task base', function () {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($this->runtimeWorktree.'/feature.txt', 'committed too early');
    taskRuntimeCommit();
    expect(fn () => taskRuntimeSubmit($dispatch))->toThrow(RuntimeException::class, 'recorded base')
        ->and($this->runtimeFirst->fresh()->status)->toBe(TaskStatus::Running)
        ->and($dispatch->run()->firstOrFail()->reviews()->count())->toBe(0)
        ->and($dispatch->run()->firstOrFail()->base_sha)->toBe($this->runtimeBase)
        ->and($dispatch->fresh()->state)->toBe('sent');
});

it('rejects final approval after the checked candidate advances to another clean commit', function () {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first task');
    taskRuntimeAcceptNext($workspace, 'second task');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeGit($this->runtimeWorktree, ['commit', '--allow-empty', '--message=Unexpected']);
    expect(fn () => taskRuntimeSubmit($final, 'pass'))->toThrow(LogicException::class, 'checked candidate')
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($workspace->fresh()->final_result)->toBeNull()
        ->and($final->fresh()->state)->toBe('sent');
});

it('stores handoff prompts encrypted and excludes tokens from serialized dispatch records', function () {
    $workspace = taskRuntimeStart();
    $dispatch = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $token = taskRuntimeReceipt($dispatch)['token'];
    expect($dispatch->getRawOriginal('prompt'))->not->toContain($token, 'Implement only')
        ->and($dispatch->toArray())->not->toHaveKeys(['prompt', 'token_hash', 'handoff_token'])
        ->and($dispatch->token_hash)->toBe(hash('sha256', $token));
});

it('marks only the current job-owned in-flight dispatch ambiguous on worker failure', function () {
    $workspace = taskRuntimeStart();
    $job = new AdvanceTaskRunner($workspace->id);
    $this->runtimeAgents->duringPrompt = function (TaskWorkspace $workspace) use ($job): void {
        $dispatch = $workspace->dispatches()->sole();
        expect($dispatch->execution_key)->toBe($job->executionKey)->and($dispatch->state)->toBe('prompting');
        $job->failed(null);
    };
    $job->handle(app(AdvanceTaskWorkspace::class));
    $dispatch = $workspace->dispatches()->sole();
    expect($dispatch->state)->toBe('ambiguous')
        ->and($workspace->fresh()->attention)->toContain('stopped before its outcome')
        ->and(app(AdvanceTaskWorkspace::class)->handle($workspace))->toBeNull()
        ->and($this->runtimeAgents->prompts)->toHaveCount(1);
    taskRuntimeSubmit($dispatch);
    expect($dispatch->fresh()->state)->toBe('acknowledged')->and($workspace->fresh()->attention)->toBeNull();
});

it('does not let a stale job failure mark a later instruction ambiguous', function () {
    $workspace = taskRuntimeStart();
    $previousJob = new AdvanceTaskRunner($workspace->id);
    $previousJob->handle(app(AdvanceTaskWorkspace::class));
    taskRuntimeSubmit($workspace->dispatches()->sole());
    $currentJob = new AdvanceTaskRunner($workspace->id);
    $this->runtimeAgents->duringPrompt = function (TaskWorkspace $workspace) use ($previousJob, $currentJob): void {
        $dispatch = $workspace->dispatches()->orderByDesc('id')->firstOrFail();
        expect($dispatch->execution_key)->toBe($currentJob->executionKey)->and($dispatch->state)->toBe('prompting');
        $previousJob->failed(null);
        expect($dispatch->fresh()->state)->toBe('prompting')->and($workspace->fresh()->attention)->toBeNull();
    };
    $currentJob->handle(app(AdvanceTaskWorkspace::class));
    $currentJob->failed(null);
    $review = $workspace->dispatches()->orderByDesc('id')->firstOrFail();
    expect($review->kind)->toBe('review')->and($review->state)->toBe('sent')
        ->and($workspace->fresh()->attention)->toBeNull()
        ->and($this->runtimeAgents->prompts)->toHaveCount(2);
});

it('bounds the final command timeout below the queue worker timeout', function (int $timeout) {
    config(['task-runtime.projects.orbit.final_timeout' => $timeout]);
    expect(fn () => taskRuntimeStart())->toThrow(LogicException::class)
        ->and(TaskWorkspace::query()->count())->toBe(0);
})->with([0, 3601]);

it('accepts the maximum supported final command timeout', function () {
    config(['task-runtime.projects.orbit.final_timeout' => 3600]);
    expect(taskRuntimeStart()->configuration['final_timeout'])->toBe(3600);
});

it('reuses the most recently observed implementer session through repeated corrections', function () {
    $workspace = taskRuntimeStart();
    $this->runtimeAgents->advanceSequence = true;
    $first = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($first);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'revise');
    $correction = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($correction->session['stateChangeSeq'])->toBe(3);
    taskRuntimeSubmit($correction);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'revise');
    $latest = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($latest->task_run_id)->toBe($first->task_run_id)
        ->and($latest->round)->toBe(2)
        ->and($latest->session['stateChangeSeq'])->toBe(4)
        ->and($latest->session['agentId'])->toBe($first->session['agentId'])
        ->and($this->runtimeAgents->starts)->toHaveCount(2);
});

it('refuses final verification at a clean commit beyond the last accepted child', function () {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first task');
    taskRuntimeAcceptNext($workspace, 'second task');
    taskRuntimeGit($this->runtimeWorktree, ['commit', '--allow-empty', '--message=Unexpected']);
    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))
        ->toThrow(LogicException::class, 'last accepted task commit')
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($workspace->fresh()->final_result)->toBeNull()
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($this->runtimeAgents->prompts)->toHaveCount(6);
});

function taskFinalRequest(string $mode = 'append_correction'): array
{
    $request = ['mode' => $mode, 'reason' => 'The final finding is within the approved feature.',
        'evidence' => 'Final review found an integration defect; reproduce and verify its correction.'];
    if ($mode === 'append_correction') {
        $request['task'] = ['title' => 'Correct the integration', 'description' => 'Resolve the recorded final integration finding.',
            'acceptance_criteria' => 'The integration regression passes and both existing parts still work.',
            'root_criterion' => test()->runtimeRoot->acceptance_criteria];
    } else {
        $request['environment_repair'] = ['cause' => 'A disposable dependency was unavailable.',
            'change' => 'Restored that dependency without changing source.', 'verification' => 'Its readiness check exited zero.'];
    }

    return $request;
}

function taskFinalHeld(TaskWorkspace $workspace, string $verdict = 'revise'): TaskAgentDispatch
{
    taskRuntimeAcceptNext($workspace, 'first accepted');
    taskRuntimeAcceptNext($workspace, 'second accepted');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($final, $verdict);

    return $final->refresh();
}

function taskFinalContinue(TaskWorkspace $workspace, TaskAgentDispatch $final, array $request, bool $apply = true): array
{
    return app(ContinueTaskFinal::class)->handle($workspace->id, $final->id, $final->final_check['sha'],
        $final->final_check['manifest_hash'], $request, true, $apply);
}

it('appends an audited final correction and accepts it through a fresh worker and retained reviewer', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $originalManifest = $workspace->manifest_hash;
    $originalRuns = TaskRun::query()->orderBy('id')->get()->toArray();
    $originalReview = $final->toArray();
    $reviewer = $workspace->fresh()->reviewer_session;
    $request = taskFinalRequest();

    $continued = taskFinalContinue($workspace, $final, $request);

    expect($continued['applied'])->toBeTrue()
        ->and($workspace->fresh()->manifest_hash)->toBe($originalManifest)
        ->and($workspace->fresh()->attention)->toBeNull()
        ->and($workspace->fresh()->final_result)->toBeNull();
    $correction = Task::query()->findOrFail($continued['audit']['task_id']);
    expect($correction->dependencies()->sole()->id)->toBe($this->runtimeSecond->id)
        ->and($correction->parent_id)->toBe($this->runtimeRoot->id)
        ->and(fn () => app(CreateTask::class)->handle('orbit', 'Unauthorized append', parent: $this->runtimeRoot))->toThrow(LogicException::class)
        ->and(fn () => app(UpdateTask::class)->handle($correction, $correction->contentVersion(), 'Changed', '', ''))->toThrow(LogicException::class);
    $accepted = taskRuntimeAcceptNext($workspace, 'integration corrected');
    expect($accepted->base_sha)->toBe($final->final_check['sha'])
        ->and($accepted->worker_ref)->not->toBeIn(array_column($originalRuns, 'worker_ref'))
        ->and(TaskRun::query()->whereIn('id', array_column($originalRuns, 'id'))->orderBy('id')->get()->toArray())->toBe($originalRuns)
        ->and($final->fresh()->toArray())->toBe($originalReview);
    $nextFinal = app(AdvanceTaskWorkspace::class)->handle($workspace);
    expect($nextFinal->round)->toBe(1)->and($nextFinal->session)->toBe($reviewer)
        ->and($nextFinal->token_hash)->not->toBe($final->token_hash)
        ->and($nextFinal->final_check['manifest_hash'])->toBe($continued['audit']['manifest_hash'])
        ->and($nextFinal->final_check['sha'])->toBe($accepted->commit_sha)
        ->and($nextFinal->prompt)->toContain('final_continuation', 'Correct the integration');
    taskRuntimeSubmit($nextFinal, 'pass');
    expect($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Completed)
        ->and(taskFinalContinue($workspace, $final, $request)['applied'])->toBeFalse()
        ->and(TaskFinalContinuation::query()->count())->toBe(1);
});

it('keeps repeated final corrections in one accepted child chain', function () {
    $workspace = taskRuntimeStart();
    $firstFinal = taskFinalHeld($workspace);
    $first = taskFinalContinue($workspace, $firstFinal, taskFinalRequest());
    $firstCorrection = taskRuntimeAcceptNext($workspace, 'first correction');
    $secondFinal = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($secondFinal, 'revise');
    $second = taskFinalContinue($workspace, $secondFinal->refresh(), taskFinalRequest());
    $secondCorrection = taskRuntimeAcceptNext($workspace, 'second correction');
    $thirdFinal = app(AdvanceTaskWorkspace::class)->handle($workspace);

    expect(Task::query()->findOrFail($second['audit']['task_id'])->dependencies()->sole()->id)
        ->toBe($first['audit']['task_id'])
        ->and($secondCorrection->base_sha)->toBe($firstCorrection->commit_sha)
        ->and($thirdFinal->round)->toBe(2)
        ->and($thirdFinal->session['agentName'])->toBe($firstFinal->session['agentName'])
        ->and(TaskFinalContinuation::query()->count())->toBe(2);
    taskRuntimeSubmit($thirdFinal, 'pass');
    expect($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Completed);
});

it('retries an evidenced environment failure at the same commit without new tasks or runs', function () {
    $marker = $this->runtimeDirectory.'/environment-restored';
    config(['task-runtime.projects.orbit.final_command' => [PHP_BINARY, '-r', 'exit(is_file($argv[1]) ? 0 : 7);', $marker]]);
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $failed = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $failedEvidence = $failed->final_check;
    $request = taskFinalRequest('retry_final_checks');
    $request['reason'] = 'The disposable environment dependency is restored.';
    $request['evidence'] = 'An external marker was missing; it now exists. Source and command are unchanged.';
    File::put($marker, 'restored');

    $continuation = taskFinalContinue($workspace, $failed, $request);
    $retry = app(AdvanceTaskWorkspace::class)->handle($workspace);

    expect($continuation['audit']['task_id'])->toBeNull()
        ->and($retry->round)->toBe(1)
        ->and($retry->final_check['sha'])->toBe($failedEvidence['sha'])
        ->and($retry->final_check['manifest_hash'])->toBe($failedEvidence['manifest_hash'])
        ->and($retry->final_check['command'])->toBe($failedEvidence['command'])
        ->and($retry->final_check['exit_code'])->toBe(0)
        ->and($failed->fresh()->final_check)->toBe($failedEvidence)
        ->and($this->runtimeRoot->children()->count())->toBe(2)
        ->and(TaskRun::query()->count())->toBe(2);
    taskRuntimeSubmit($retry, 'pass');
    expect($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Completed);
});

it('continues an acknowledged final blocker after evidence is supplied without reopening accepted work', function () {
    $workspace = taskRuntimeStart();
    $blocked = taskFinalHeld($workspace, 'blocked');
    $request = taskFinalRequest('retry_final_checks');
    $request['evidence'] = 'The required disposable machine evidence is now available; HEAD is unchanged.';

    taskFinalContinue($workspace, $blocked, $request);
    $next = app(AdvanceTaskWorkspace::class)->handle($workspace);

    expect($next->round)->toBe(1)->and($next->final_check['sha'])->toBe($blocked->final_check['sha'])
        ->and($blocked->fresh()->result['verdict'])->toBe('blocked')
        ->and(TaskRun::query()->count())->toBe(2);
});

it('previews final continuation without writing tasks audits refs or dispatches', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $before = $workspace->fresh()->toArray();
    $refs = taskRuntimeGit($workspace->worktree, ['show-ref']);
    $dispatches = $workspace->dispatches()->count();
    $prompts = count($this->runtimeAgents->prompts);
    config(['task-runtime.enabled' => false]);

    expect(taskFinalContinue($workspace, $final, taskFinalRequest(), false)['recorded'])->toBeFalse()
        ->and($workspace->fresh()->toArray())->toBe($before)
        ->and(TaskFinalContinuation::query()->count())->toBe(0)
        ->and($workspace->dispatches()->count())->toBe($dispatches)
        ->and($this->runtimeRoot->children()->count())->toBe(2)
        ->and(taskRuntimeGit($workspace->worktree, ['show-ref']))->toBe($refs)
        ->and($this->runtimeAgents->prompts)->toHaveCount($prompts);
});

it('rejects conflicting continuation retries and preserves immutable audit and acceptance data', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $request = taskFinalRequest();
    $first = taskFinalContinue($workspace, $final, $request);
    $same = taskFinalContinue($workspace, $final, array_reverse($request, true));
    $changed = $request;
    $changed['task']['title'] = 'Different correction';
    $audit = TaskFinalContinuation::query()->findOrFail($first['audit']['id']);

    expect($same['applied'])->toBeFalse()
        ->and(fn () => taskFinalContinue($workspace, $final, $changed))->toThrow(LogicException::class, 'conflicting')
        ->and(fn () => $audit->update(['mode' => 'retry_final_checks']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $audit->fresh()->delete())->toThrow(LogicException::class, 'history')
        ->and(fn () => $final->update(['final_check' => []]))->toThrow(LogicException::class, 'immutable')
        ->and($this->runtimeRoot->children()->count())->toBe(3)
        ->and(TaskFinalContinuation::query()->count())->toBe(1);
});

it('refuses continuation when exact final pins ownership or scope changed', function (string $case) {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $head = $final->final_check['sha'];
    $manifest = $final->final_check['manifest_hash'];
    $request = taskFinalRequest();
    $exclusive = true;
    match ($case) {
        'head' => $head = str_repeat('a', 40),
        'manifest' => $manifest = str_repeat('b', 64),
        'ownership' => $exclusive = false,
        'scope' => $request['task']['root_criterion'] = 'An entirely new product requirement.',
        'hold' => $workspace->refresh()->update(['attention' => 'A separate operator hold.']),
        'reviewer' => $this->runtimeAgents->reviewerMissing = true,
        'dirty' => File::put($workspace->worktree.'/unexpected.txt', 'unreviewed'),
        'advanced' => taskRuntimeGit($workspace->worktree, ['commit', '--allow-empty', '--message=Unexpected']),
        'disabled' => config(['task-runtime.enabled' => false]),
    };
    $before = $workspace->fresh()->toArray();

    expect(fn () => app(ContinueTaskFinal::class)->handle($workspace->id, $final->id, $head, $manifest, $request, $exclusive, true))
        ->toThrow(Exception::class)
        ->and($workspace->fresh()->toArray())->toBe($before)
        ->and(TaskFinalContinuation::query()->count())->toBe(0)
        ->and($this->runtimeRoot->children()->count())->toBe(2);
})->with(['head', 'manifest', 'ownership', 'scope', 'hold', 'reviewer', 'dirty', 'advanced', 'disabled']);

it('rolls back an appended correction if recording its continuation audit fails', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $before = $workspace->fresh()->toArray();
    DB::statement("CREATE TRIGGER fail_final_audit BEFORE INSERT ON task_final_continuations BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");

    expect(fn () => taskFinalContinue($workspace, $final, taskFinalRequest()))->toThrow(QueryException::class, 'audit unavailable')
        ->and($workspace->fresh()->toArray())->toBe($before)
        ->and(TaskFinalContinuation::query()->count())->toBe(0)
        ->and($this->runtimeRoot->children()->count())->toBe(2)
        ->and($this->runtimeSecond->dependents()->count())->toBe(0);
});

it('rejects uncertain legacy final dispatches and recovery holds without reinterpreting them', function (string $case) {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    $last = taskRuntimeAcceptNext($workspace, 'second');
    $legacy = $workspace->dispatches()->create(['kind' => 'final_review', 'round' => 0, 'step_key' => 'root:final_review:0',
        'state' => 'ambiguous', 'token_hash' => hash('sha256', 'legacy'), 'handoff_token' => 'legacy', 'prompt' => 'legacy']);
    $workspace->refresh()->update(['attention' => $case === 'recovery' ? 'Recovery retains its explicit hold.' : 'Dispatch outcome unknown.']);

    expect(fn () => app(ContinueTaskFinal::class)->handle($workspace->id, $legacy->id, $last->commit_sha,
        $workspace->manifest_hash, taskFinalRequest('retry_final_checks'), true, true))->toThrow(LogicException::class, 'legacy')
        ->and($legacy->fresh()->state)->toBe('ambiguous')
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
})->with(['ambiguous', 'recovery']);

it('rejects stale final receipts and ignores late failure callbacks after a continuation', function () {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $oldJob = new AdvanceTaskRunner($workspace->id);
    $oldJob->handle(app(AdvanceTaskWorkspace::class));
    $oldFinal = $workspace->dispatches()->orderByDesc('id')->firstOrFail();
    taskRuntimeSubmit($oldFinal, 'revise');
    taskFinalContinue($workspace, $oldFinal->refresh(), taskFinalRequest());
    $new = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $oldJob->failed(null);

    expect(fn () => taskRuntimeSubmit($oldFinal, 'pass'))->toThrow(LogicException::class, 'cannot be replaced')
        ->and($workspace->fresh()->attention)->toBeNull()
        ->and($new->fresh()->state)->toBe('sent')
        ->and($oldFinal->fresh()->result['verdict'])->toBe('revise');
});

it('observes the reviewer outside runtime project and database locks', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $level = DB::transactionLevel();
    $observations = 0;
    $this->runtimeAgents->duringObservation = function (TaskWorkspace $observed) use ($level, &$observations): void {
        $observations++;
        expect(DB::transactionLevel())->toBe($level);
        foreach (['tasks:runtime:'.$observed->id, 'tasks:project:'.hash('sha256', $observed->project_id)] as $key) {
            $lock = Cache::lock($key, 10);
            expect($lock->get())->toBeTrue();
            $lock->release();
        }
    };

    taskFinalContinue($workspace, $final, taskFinalRequest());

    expect($observations)->toBe(1)->and(TaskFinalContinuation::query()->count())->toBe(1);
});

it('rejects changed ledger bindings after observation without releasing the new hold', function (string $case) {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $this->runtimeAgents->duringObservation = function (TaskWorkspace $observed) use ($case): void {
        $current = $observed->fresh();
        if ($case === 'reviewer') {
            $session = $current->reviewer_session;
            $session['paneId'] = 'replacement';
            $current->update(['reviewer_session' => $session]);
        } else {
            $current->update(['attention' => 'New operator hold.']);
        }
    };

    expect(fn () => taskFinalContinue($workspace, $final, taskFinalRequest()))
        ->toThrow(LogicException::class, 'changed during observation')
        ->and(TaskFinalContinuation::query()->count())->toBe(0)
        ->and($this->runtimeRoot->children()->count())->toBe(2)
        ->and($workspace->fresh()->attention)->toBe($case === 'reviewer' ? $final->result['summary'] : 'New operator hold.');
})->with(['reviewer', 'attention']);

it('requires structured repair observations and rejects fields from the other mode', function (string $case) {
    $workspace = taskRuntimeStart();
    $request = taskFinalRequest('retry_final_checks');
    match ($case) {
        'missing-repair' => $request['environment_repair'] = null,
        'empty-change' => $request['environment_repair']['change'] = ' ',
        'missing-verification' => $request['environment_repair'] = ['cause' => 'Observed', 'change' => 'Repaired'],
        'extra-repair' => $request['environment_repair']['claim'] = 'Pass',
        'retry-task' => $request['task'] = taskFinalRequest()['task'],
        'append-repair' => $request = [...taskFinalRequest(), 'environment_repair' => $request['environment_repair']],
        'extra-field' => $request['reset'] = true,
    };

    expect(fn () => app(ContinueTaskFinal::class)->handle($workspace->id, 1, $workspace->base_sha,
        $workspace->manifest_hash, $request, true, true))->toThrow(InvalidArgumentException::class)
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
})->with(['missing-repair', 'empty-change', 'missing-verification', 'extra-repair', 'retry-task', 'append-repair', 'extra-field']);

it('previews applies and deduplicates the continuation command without duplicate enqueue', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $file = $this->runtimeDirectory.'/continuation.json';
    File::put($file, json_encode(taskFinalRequest(), JSON_THROW_ON_ERROR));
    $arguments = ['workspace' => $workspace->id, '--dispatch' => $final->id, '--head' => $final->final_check['sha'],
        '--manifest' => $final->final_check['manifest_hash'], '--file' => $file, '--exclusive' => true];
    Queue::fake();

    expect(Artisan::call('tasks:continue-final', $arguments))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['applied'])->toBeFalse()
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    $arguments['--apply'] = true;
    $arguments['--advance'] = true;
    expect(Artisan::call('tasks:continue-final', $arguments))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['advancement'])->toBe('queued')
        ->and(Artisan::call('tasks:continue-final', $arguments))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['applied'])->toBeFalse()
        ->and(TaskFinalContinuation::query()->count())->toBe(1);
    Queue::assertPushed(AdvanceTaskRunner::class, 1);
});

it('rejects unsafe continuation command input without changing the workspace', function (string $case) {
    $workspace = taskRuntimeStart();
    $file = $this->runtimeDirectory.'/continuation.json';
    File::put($file, match ($case) {
        'invalid' => '{', 'list' => '[]', 'numeric-key' => '{"0":"invalid"}',
        'oversized' => str_repeat('x', 262_145), default => json_encode(taskFinalRequest(), JSON_THROW_ON_ERROR),
    });
    $arguments = ['workspace' => $workspace->id, '--dispatch' => 1, '--head' => $workspace->base_sha,
        '--manifest' => $workspace->manifest_hash, '--file' => $file, '--exclusive' => true];
    if ($case === 'inside' || $case === 'symlink-inside') {
        File::put($workspace->worktree.'/request.json', '{}');
        if ($case === 'symlink-inside') {
            File::delete($file);
            symlink($workspace->worktree.'/request.json', $file);
        } else {
            $arguments['--file'] = $workspace->worktree.'/request.json';
        }
    } elseif ($case === 'relative') {
        $arguments['--file'] = 'request.json';
    } elseif ($case === 'advance') {
        $arguments['--advance'] = true;
    } elseif ($case === 'id') {
        $arguments['workspace'] = -1;
    }
    $before = $workspace->fresh()->toArray();
    Queue::fake();

    expect(Artisan::call('tasks:continue-final', $arguments))->toBe(1)
        ->and($workspace->fresh()->toArray())->toBe($before)
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['invalid', 'list', 'numeric-key', 'oversized', 'inside', 'symlink-inside', 'relative', 'advance', 'id']);

it('does not reinterpret a legacy prepared final instruction under the new runner', function () {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $legacy = $workspace->dispatches()->create(['kind' => 'final_review', 'round' => 0, 'step_key' => 'root:final_review:0',
        'token_hash' => hash('sha256', 'legacy'), 'handoff_token' => 'legacy', 'prompt' => 'Existing prepared final instruction']);
    $before = $legacy->refresh()->toArray();
    $prompts = count($this->runtimeAgents->prompts);

    expect(fn () => app(AdvanceTaskWorkspace::class)->handle($workspace))->toThrow(LogicException::class, 'original runner')
        ->and($legacy->fresh()->toArray())->toBe($before)
        ->and($legacy->fresh()->prompt)->toBe('Existing prepared final instruction')
        ->and($workspace->fresh()->final_check)->toBeNull()
        ->and($this->runtimeAgents->prompts)->toHaveCount($prompts);
});

it('keeps known failed checks immutable when their old job later fails', function () {
    config(['task-runtime.projects.orbit.final_command' => [PHP_BINARY, '-r', 'exit(7);']]);
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $job = new AdvanceTaskRunner($workspace->id);
    $job->handle(app(AdvanceTaskWorkspace::class));
    $final = $workspace->dispatches()->orderByDesc('id')->firstOrFail();
    $before = $final->toArray();
    $held = $workspace->fresh()->toArray();
    $job->failed(new RuntimeException('Delayed queue failure'));

    expect($final->fresh()->state)->toBe('check_failed')
        ->and($final->fresh()->toArray())->toBe($before)
        ->and($workspace->fresh()->toArray())->toBe($held)
        ->and(fn () => $final->update(['final_check' => []]))->toThrow(LogicException::class, 'immutable');
});

it('rejects final approval after a continuation when its checked head or manifest changes', function (string $case) {
    $workspace = taskRuntimeStart();
    $old = taskFinalHeld($workspace);
    taskFinalContinue($workspace, $old, taskFinalRequest());
    taskRuntimeAcceptNext($workspace, 'corrected');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    if ($case === 'head') {
        taskRuntimeGit($workspace->worktree, ['commit', '--allow-empty', '--message=Unexpected']);
    } else {
        DB::table('tasks')->where('id', $this->runtimeRoot->id)->update(['acceptance_criteria' => 'Changed outside authorized path']);
    }

    expect(fn () => taskRuntimeSubmit($final, 'pass'))->toThrow(LogicException::class)
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($workspace->fresh()->final_result)->toBeNull();
})->with(['head', 'manifest']);

it('preserves a pre-final instruction across the additive migration and reaches a new bound final round', function () {
    $workspace = taskRuntimeStart();
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $migration = require database_path('migrations/2026_09_13_000000_add_task_final_continuations.php');
    $preflightMigration = require database_path('migrations/2026_09_14_093211_add_final_preflight_to_task_agent_dispatches.php');
    $preflightMigration->down();
    $migration->down();
    $legacy = DB::table('task_agent_dispatches')->where('id', $implement->id)->first();
    $migration->up();
    $preflightMigration->up();
    $restored = (array) DB::table('task_agent_dispatches')->where('id', $implement->id)->first();
    unset($restored['final_check'], $restored['final_check_version'], $restored['final_preflight'], $restored['final_preflight_version']);
    expect($restored)->toBe((array) $legacy);

    File::put($workspace->worktree.'/feature.txt', 'pre-final accepted after upgrade');
    taskRuntimeSubmit($implement->refresh());
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeCommit();
    taskRuntimeSubmit($commit);
    taskRuntimeAcceptNext($workspace, 'second accepted');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    taskRuntimeSubmit($final, 'revise');

    expect($final->final_check_version)->toBe(1)
        ->and(taskFinalContinue($workspace, $final->refresh(), taskFinalRequest())['applied'])->toBeTrue();
});

it('accepts legacy sent final receipts without making their results continuable', function (string $verdict) {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $check = $final->final_check;
    $head = $check['sha'];
    unset($check['manifest_hash'], $check['candidate_unchanged']);
    DB::table('task_agent_dispatches')->where('id', $final->id)->update(['final_check' => null, 'final_check_version' => null]);
    DB::table('task_workspaces')->where('id', $workspace->id)->update(['final_check' => json_encode($check, JSON_THROW_ON_ERROR)]);
    $prompt = $final->prompt;
    taskRuntimeSubmit($final->refresh(), $verdict);

    expect($final->fresh()->state)->toBe('acknowledged')->and($final->fresh()->prompt)->toBe($prompt)
        ->and($final->fresh()->final_check)->toBeNull()
        ->and(fn () => app(ContinueTaskFinal::class)->handle($workspace->id, $final->id, $head, $workspace->manifest_hash,
            taskFinalRequest(), true, true))->toThrow(LogicException::class)
        ->and(TaskFinalContinuation::query()->count())->toBe(0)
        ->and($this->runtimeRoot->fresh()->status)->toBe($verdict === 'pass' ? TaskStatus::Completed : TaskStatus::Pending);
})->with(['pass', 'revise', 'blocked']);

it('refuses passing roots and children that are no longer accepted', function (string $case) {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace, $case === 'passed' ? 'pass' : 'revise');
    if ($case === 'unfinished') {
        DB::table('tasks')->where('id', $this->runtimeSecond->id)->update(['status' => TaskStatus::Pending->value]);
    }
    $before = $workspace->fresh()->toArray();

    expect(fn () => taskFinalContinue($workspace, $final, taskFinalRequest()))->toThrow(LogicException::class)
        ->and($workspace->fresh()->toArray())->toBe($before)
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
})->with(['passed', 'unfinished']);

it('keeps corrective review revisions in one new task run and leaves another recovery hold untouched', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $otherRoot = app(CreateTask::class)->handle('orbit', 'Other recovered feature', kind: TaskKind::Group);
    $other = TaskWorkspace::query()->create(['root_task_id' => $otherRoot->id, 'project_id' => 'orbit', 'source_key' => 'OTHER',
        'repository' => '/unused', 'worktree' => '/unused/recovery', 'base_sha' => str_repeat('a', 40),
        'manifest_hash' => str_repeat('b', 64), 'configuration' => [], 'attention' => 'Recovery requires separate reconciliation.']);
    $otherBefore = $other->refresh()->toArray();
    taskFinalContinue($workspace, $final, taskFinalRequest());
    $implement = app(AdvanceTaskWorkspace::class)->handle($workspace);
    File::put($workspace->worktree.'/feature.txt', 'correction requiring review revision');
    taskRuntimeSubmit($implement);
    taskRuntimeSubmit(app(AdvanceTaskWorkspace::class)->handle($workspace), 'revise');
    $revision = app(AdvanceTaskWorkspace::class)->handle($workspace);

    expect($revision->task_run_id)->toBe($implement->task_run_id)
        ->and($revision->session)->toBe($implement->session)
        ->and($revision->round)->toBe(1)
        ->and(TaskRun::query()->where('task_id', $implement->run()->firstOrFail()->task_id)->count())->toBe(1)
        ->and($other->fresh()->toArray())->toBe($otherBefore);
});

it('retains a continuation if its post-transaction enqueue fails', function () {
    $workspace = taskRuntimeStart();
    $final = taskFinalHeld($workspace);
    $file = $this->runtimeDirectory.'/continuation.json';
    File::put($file, json_encode(taskFinalRequest(), JSON_THROW_ON_ERROR));
    $level = DB::transactionLevel();
    Bus::shouldReceive('dispatch')->once()->withArgs(function ($job) use ($workspace, $level) {
        expect(DB::transactionLevel())->toBe($level)
            ->and(TaskFinalContinuation::query()->count())->toBe(1)
            ->and($workspace->fresh()->attention)->toBeNull();

        return $job instanceof AdvanceTaskRunner && $job->workspaceId === $workspace->id;
    })->andThrow(new RuntimeException('Fixture enqueue unavailable'));
    $this->artisan('tasks:continue-final', ['workspace' => $workspace->id, '--dispatch' => $final->id,
        '--head' => $final->final_check['sha'], '--manifest' => $final->final_check['manifest_hash'],
        '--file' => $file, '--exclusive' => true, '--apply' => true, '--advance' => true])
        ->expectsOutputToContain('Continuation recorded; enqueue failed.')->assertFailed();

    expect(TaskFinalContinuation::query()->count())->toBe(1)
        ->and($workspace->fresh()->attention)->toBeNull()
        ->and(taskFinalContinue($workspace, $final, taskFinalRequest())['applied'])->toBeFalse();
});

it('never falls back to workspace evidence for a versioned final dispatch', function (string $case) {
    $workspace = taskRuntimeStart();
    taskRuntimeAcceptNext($workspace, 'first');
    taskRuntimeAcceptNext($workspace, 'second');
    $final = app(AdvanceTaskWorkspace::class)->handle($workspace);
    $check = $final->final_check;
    if ($case === 'missing') {
        $check = null;
    } elseif ($case === 'manifest') {
        unset($check['manifest_hash']);
    } else {
        $check['candidate_unchanged'] = false;
    }
    DB::table('task_agent_dispatches')->where('id', $final->id)
        ->update(['final_check' => $check === null ? null : json_encode($check, JSON_THROW_ON_ERROR)]);

    expect(fn () => taskRuntimeSubmit($final->refresh(), 'pass'))->toThrow(LogicException::class, 'checked candidate')
        ->and($this->runtimeRoot->fresh()->status)->toBe(TaskStatus::Pending);
})->with(['missing', 'manifest', 'candidate']);
