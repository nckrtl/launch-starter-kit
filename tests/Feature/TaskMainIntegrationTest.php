<?php

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskWorkspace;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\AcceptTaskRun;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\ContinueTaskFinal;
use App\Tasks\Runtime\SubmitTaskDispatch;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskMainIntegration;
use App\Tasks\Runtime\TaskManifestAmendmentHistory;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

final class MainIntegrationAgents implements TaskAgents
{
    public array $prompts = [];

    public ?Closure $observation = null;

    public function assertSession(TaskWorkspace $workspace, array $session): void
    {
        if ($this->observation !== null) {
            ($this->observation)($workspace);
        }
    }

    public function start(TaskWorkspace $workspace, string $name): array
    {
        return ['agentName' => $name, 'workingDirectory' => $workspace->worktree, 'agentId' => 'native-'.$name];
    }

    public function prompt(TaskWorkspace $workspace, array $session, string $prompt): array
    {
        $this->prompts[] = $prompt;

        return $session;
    }
}

function mainIntegrationGit(string $path, array $args): string
{
    $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', ...$args], $path);
    $process->mustRun();

    return trim($process->getOutput());
}

function mainIntegrationSubmit(TaskAgentDispatch $dispatch, ?string $verdict = null): TaskAgentDispatch
{
    return app(SubmitTaskDispatch::class)->handle($dispatch, ['token' => $dispatch->handoff_token,
        'summary' => 'Exact assigned work checked.', 'evidence' => 'Focused fixture verification passed.',
        ...($verdict === null ? [] : ['verdict' => $verdict])]);
}

function mainIntegrationCommit(): void
{
    mainIntegrationGit(test()->worktree, ['add', '--all']);
    mainIntegrationGit(test()->worktree, ['commit', '-m', 'Reviewed task']);
}

function mainIntegrationAdvanceRemote(string $content = 'new main'): string
{
    File::put(test()->repository.'/main.txt', $content);
    mainIntegrationGit(test()->repository, ['add', '--all']);
    mainIntegrationGit(test()->repository, ['commit', '-m', 'Main moved']);

    return mainIntegrationGit(test()->repository, ['rev-parse', 'HEAD']);
}

function mainIntegrationHold(): TaskAgentDispatch
{
    $implement = app(AdvanceTaskWorkspace::class)->handle(test()->workspace);
    File::put(test()->worktree.'/feature.txt', 'first accepted task');
    mainIntegrationSubmit($implement);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle(test()->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle(test()->workspace);
    mainIntegrationCommit();
    mainIntegrationSubmit($commit);
    mainIntegrationAdvanceRemote();

    return app(AdvanceTaskWorkspace::class)->handle(test()->workspace);
}

function mainIntegrationRequest(TaskAgentDispatch $held, bool $apply = true, array $replace = []): array
{
    return app(ContinueTaskFinal::class)->handle(test()->workspace->id, $held->id, $held->final_preflight['head'],
        $held->final_preflight['manifest_hash'], [...['mode' => 'integrate_main',
            'main_sha' => $held->final_preflight['main_sha'], 'reason' => 'Current main is required by proof.',
            'evidence' => 'The preflight observed missing ancestry.'], ...$replace], true, $apply);
}

beforeEach(function () {
    $this->directory = storage_path('framework/testing/main-integration-'.bin2hex(random_bytes(5)));
    $this->repository = $this->directory.'/repository';
    $this->worktree = $this->directory.'/worktree';
    File::makeDirectory($this->repository, 0755, true);
    File::makeDirectory($this->directory.'/projects');
    config(['task-runtime.enabled' => true, 'commander.projects_path' => $this->directory.'/projects']);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    mainIntegrationGit($this->repository, ['init', '--initial-branch=main']);
    mainIntegrationGit($this->repository, ['config', 'user.name', 'Tasks Test']);
    mainIntegrationGit($this->repository, ['config', 'user.email', 'tasks@example.test']);
    File::put($this->repository.'/README.md', "initial\n");
    mainIntegrationGit($this->repository, ['add', '--all']);
    mainIntegrationGit($this->repository, ['commit', '-m', 'Initial']);
    mainIntegrationGit($this->repository, ['remote', 'add', 'origin', $this->repository]);
    mainIntegrationGit($this->repository, ['worktree', 'add', '-b', 'feature', $this->worktree]);
    $this->root = app(CreateTask::class)->handle('orbit', 'Feature', 'Feature behavior.', TaskKind::Group, acceptanceCriteria: 'Feature works and meets proof requirements.');
    $this->child = app(CreateTask::class)->handle('orbit', 'Implement feature', 'Implement isolated feature.', parent: $this->root, acceptanceCriteria: 'Feature works.');
    $this->builder = $this->directory.'/builder-ran';
    $this->workspace = TaskWorkspace::query()->create(['root_task_id' => $this->root->id, 'project_id' => 'orbit', 'source_key' => 'ORB-91',
        'repository' => $this->repository, 'worktree' => $this->worktree,
        'base_sha' => mainIntegrationGit($this->worktree, ['rev-parse', 'HEAD']),
        'manifest_hash' => app(TaskRuntimePlan::class)->hash($this->root),
        'configuration' => ['flow_version' => 1, 'instructions' => 'Do not merge or deploy.',
            'orbit_profile' => ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true],
            'final_command' => [PHP_BINARY, '-r', 'file_put_contents('.var_export($this->builder, true).', "ran"); exit(7);'], 'final_timeout' => 10],
    ]);
    $this->agents = new MainIntegrationAgents;
    app()->instance(TaskAgents::class, $this->agents);
});

afterEach(fn () => File::deleteDirectory($this->directory));

it('holds before Builder or proof then independently reviews and accepts exactly one merge', function () {
    $held = mainIntegrationHold();
    expect($held->state)->toBe('integration_required')->and($held->session)->toBeNull()
        ->and($held->final_check)->toBeNull()->and(file_exists($this->builder))->toBeFalse()
        ->and($this->agents->prompts)->toHaveCount(3)
        ->and(mainIntegrationGit($this->worktree, ['rev-parse', 'origin/main']))->toBe($held->final_preflight['main_sha']);
    $originalRun = $this->child->fresh()->acceptedRun()->firstOrFail()->toArray();
    $originalHeld = $held->toArray();
    expect(mainIntegrationRequest($held, false)['applied'])->toBeFalse()->and(TaskFinalContinuation::query()->count())->toBe(0);
    $applied = mainIntegrationRequest($held);
    $task = Task::query()->findOrFail($applied['audit']['task_id']);
    expect($task->dependencies()->sole()->id)->toBe($this->child->id)
        ->and($this->workspace->fresh()->attention)->toBeNull();
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($implement->prompt)->toContain('git merge --no-commit --no-ff', 'sole exception')->not->toContain('commit early, merge,');
    mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    mainIntegrationSubmit($implement);
    $review = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($review->prompt)->toContain('BOTH ordered parents');
    mainIntegrationSubmit($review, 'revise');
    $revision = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($revision->session)->toBe($implement->session);
    File::put($this->worktree.'/integration.txt', "review correction\n");
    mainIntegrationSubmit($revision);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($commit->prompt)->toContain('both ordered parent SHAs')->not->toContain('sole parent');
    mainIntegrationCommit();
    mainIntegrationSubmit($commit);
    $accepted = $task->fresh()->acceptedRun()->firstOrFail();
    expect($task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and(mainIntegrationGit($this->worktree, ['show', '-s', '--format=%P', 'HEAD']))
        ->toBe($held->final_preflight['head'].' '.$held->final_preflight['main_sha'])
        ->and($this->child->fresh()->acceptedRun()->firstOrFail()->toArray())->toBe($originalRun)
        ->and($held->fresh()->toArray())->toBe($originalHeld)
        ->and(app(TaskMainIntegration::class)->binding($accepted)['parents'])->toBe([$held->final_preflight['head'], $held->final_preflight['main_sha']]);
    $next = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($next->round)->toBe(1)->and($next->state)->toBe('check_failed')
        ->and($next->final_check['sha'])->toBe($accepted->commit_sha)->and(file_exists($this->builder))->toBeTrue();
    expect(mainIntegrationRequest($held)['recorded'])->toBeTrue()
        ->and(fn () => mainIntegrationRequest($held, replace: ['reason' => 'Conflicting request']))->toThrow(LogicException::class, 'conflicting')
        ->and(TaskFinalContinuation::query()->count())->toBe(1);
    app(TaskManifestAmendmentHistory::class)->ledger($this->workspace, app(TaskRuntimePlan::class)->manifest($this->root));
});

it('refuses stale main A but allows a fresh descendant B request without rewriting the hold', function () {
    $held = mainIntegrationHold();
    $before = $held->toArray();
    $newMain = mainIntegrationAdvanceRemote('newer main');
    expect(fn () => mainIntegrationRequest($held))->toThrow(LogicException::class, 'stale')
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
    $applied = mainIntegrationRequest($held, replace: ['main_sha' => $newMain]);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect(app(TaskMainIntegration::class)->binding($implement->run()->firstOrFail())['main_sha'])->toBe($newMain)
        ->and($held->fresh()->toArray())->toBe($before)->and($applied['applied'])->toBeTrue();
});

it('refuses dirty work and lock-time workspace drift', function (string $case) {
    $held = mainIntegrationHold();
    if ($case === 'dirty') {
        File::put($this->worktree.'/unowned.txt', 'dirty');
    } else {
        $this->agents->observation = fn (TaskWorkspace $workspace) => TaskWorkspace::query()->findOrFail($workspace->id)->update(['attention' => 'Changed owner']);
    }
    expect(fn () => mainIntegrationRequest($held))->toThrow($case === 'dirty' ? RuntimeException::class : LogicException::class)
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
})->with(['dirty', 'state']);

it('refuses missing or wrong MERGE_HEAD and keeps ordinary construction single-parent', function (string $case) {
    $held = mainIntegrationHold();
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    if ($case !== 'missing') {
        mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
        $path = mainIntegrationGit($this->worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'MERGE_HEAD']);
        File::put($path, $case === 'extra' ? $held->final_preflight['main_sha']."\n".$held->final_preflight['head']."\n" : $held->final_preflight['head']."\n");
    }
    expect(fn () => mainIntegrationSubmit($implement))->toThrow(RuntimeException::class, 'MERGE_HEAD')
        ->and($implement->fresh()->state)->toBe('sent')
        ->and(fn () => new TaskCommit(str_repeat('a', 40), str_repeat('b', 40), [str_repeat('c', 40), str_repeat('d', 40)]))
        ->toThrow(InvalidArgumentException::class, 'exactly one parent');
})->with(['missing', 'wrong', 'extra']);

it('refuses delayed review receipts and two-parent commits for ordinary tasks', function () {
    $held = mainIntegrationHold();
    $ordinary = $this->child->fresh()->acceptedRun()->firstOrFail();
    $review = $ordinary->reviews()->latest('round')->firstOrFail();
    $fake = new TaskCommit(str_repeat('a', 40), $review->tree_sha, [$ordinary->base_sha, $held->final_preflight['main_sha']], $held->final_preflight['main_sha']);
    expect(fn () => app(AcceptTaskRun::class)->handle($review, $fake))->toThrow(LogicException::class, 'reviewed tree');
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect(fn () => mainIntegrationSubmit($held, 'pass'))->toThrow(LogicException::class)
        ->and($implement->fresh()->state)->toBe('sent');
});

it('preserves approved pinned integration when main advances and holds the next final round', function () {
    $held = mainIntegrationHold();
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    mainIntegrationSubmit($implement);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    $newMain = mainIntegrationAdvanceRemote('main changed after review');
    mainIntegrationCommit();
    mainIntegrationSubmit($commit);
    $next = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($implement->run()->firstOrFail()->commit_sha)->toBe(mainIntegrationGit($this->worktree, ['rev-parse', 'HEAD']))
        ->and($next->state)->toBe('integration_required')->and($next->round)->toBe(1)
        ->and($next->final_preflight['main_sha'])->toBe($newMain)->and(file_exists($this->builder))->toBeFalse();
});

it('rejects a changed reviewed tree or wrong ordered merge parents', function (string $case) {
    $held = mainIntegrationHold();
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    mainIntegrationSubmit($implement);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    if ($case === 'tree') {
        File::put($this->worktree.'/unreviewed.txt', 'not reviewed');
    }
    mainIntegrationCommit();
    if ($case !== 'tree') {
        $parents = $case === 'reverse' ? [$held->final_preflight['main_sha'], $held->final_preflight['head']] : [$held->final_preflight['head']];
        $arguments = ['commit-tree', mainIntegrationGit($this->worktree, ['rev-parse', 'HEAD^{tree}'])];
        foreach ($parents as $parent) {
            array_push($arguments, '-p', $parent);
        }
        $wrong = mainIntegrationGit($this->worktree, [...$arguments, '-m', 'Invalid parent construction']);
        mainIntegrationGit($this->worktree, ['update-ref', 'HEAD', $wrong]);
    }
    expect(fn () => mainIntegrationSubmit($commit))->toThrow(RuntimeException::class, 'exact reviewed tree')
        ->and($commit->fresh()->state)->toBe('sent')
        ->and($implement->run()->firstOrFail()->commit_sha)->toBeNull();
})->with(['tree', 'reverse', 'single']);

it('refuses unresolved merge index entries before review', function () {
    $held = mainIntegrationHold();
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    $blob = mainIntegrationGit($this->worktree, ['rev-parse', 'HEAD:README.md']);
    $process = new Process(['git', 'update-index', '--index-info'], $this->worktree);
    $process->setInput('100644 '.$blob." 1\tconflict.txt\n");
    $process->mustRun();
    expect(fn () => mainIntegrationSubmit($implement))->toThrow(RuntimeException::class, 'conflicts')
        ->and($implement->fresh()->state)->toBe('sent');
});

it('refuses stale preview B when main advances to C and rejects force-pushed main', function () {
    $held = mainIntegrationHold();
    $b = mainIntegrationAdvanceRemote('main B');
    expect(mainIntegrationRequest($held, false, ['main_sha' => $b])['recorded'])->toBeFalse();
    $c = mainIntegrationAdvanceRemote('main C');
    expect(fn () => mainIntegrationRequest($held, replace: ['main_sha' => $b]))->toThrow(LogicException::class, 'stale');
    $unrelated = mainIntegrationGit($this->repository, ['commit-tree', 'HEAD^{tree}', '-m', 'Unrelated main']);
    mainIntegrationGit($this->repository, ['update-ref', 'refs/heads/main', $unrelated]);
    expect(fn () => mainIntegrationRequest($held, replace: ['main_sha' => $unrelated]))->toThrow(LogicException::class, 'force-push')
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
    mainIntegrationGit($this->repository, ['update-ref', 'refs/heads/main', $c]);
    expect(mainIntegrationRequest($held, replace: ['main_sha' => $c])['applied'])->toBeTrue();
});

it('previews and applies through the CLI without starting a worker', function () {
    $held = mainIntegrationHold();
    $arguments = ['workspace' => $this->workspace->id, '--dispatch' => $held->id,
        '--head' => $held->final_preflight['head'], '--manifest' => $held->final_preflight['manifest_hash'],
        '--main' => $held->final_preflight['main_sha'], '--reason' => 'Main integration required.',
        '--evidence' => 'Preflight ancestry hold.', '--exclusive' => true];
    expect(Artisan::call('tasks:integrate-main', $arguments))->toBe(0)
        ->and(TaskFinalContinuation::query()->count())->toBe(0);
    expect(Artisan::call('tasks:integrate-main', [...$arguments, '--apply' => true]))->toBe(0)
        ->and(TaskFinalContinuation::query()->count())->toBe(1)
        ->and($this->agents->prompts)->toHaveCount(3);
});

it('executes a normal correction after integration and can integrate again on the continued manifest', function () {
    $held = mainIntegrationHold();
    mainIntegrationRequest($held);
    $implement = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationGit($this->worktree, ['merge', '--no-commit', '--no-ff', $held->final_preflight['main_sha']]);
    mainIntegrationSubmit($implement);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $commit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationCommit();
    mainIntegrationSubmit($commit);
    $integratedHead = mainIntegrationGit($this->worktree, ['rev-parse', 'HEAD']);
    $failed = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    $prior = TaskFinalContinuation::query()->sole()->toArray();
    $request = ['mode' => 'append_correction', 'reason' => 'Resolve a bounded final-check defect.',
        'evidence' => 'The retained Builder check failed.', 'task' => ['title' => 'Correct final defect',
            'description' => 'Correct the scoped final-check defect.', 'acceptance_criteria' => 'The focused regression passes.',
            'root_criterion' => $this->root->acceptance_criteria]];
    app(ContinueTaskFinal::class)->handle($this->workspace->id, $failed->id, $integratedHead,
        $failed->final_check['manifest_hash'], $request, true, true);
    $correction = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($correction->prompt)->toContain('Do not commit.')->not->toContain('sole exception')
        ->and(app(TaskMainIntegration::class)->binding($correction->run()->firstOrFail()))->toBeNull();
    File::put($this->worktree.'/correction.txt', "focused regression correction\n");
    mainIntegrationSubmit($correction);
    mainIntegrationSubmit(app(AdvanceTaskWorkspace::class)->handle($this->workspace), 'pass');
    $correctCommit = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    mainIntegrationCommit();
    mainIntegrationSubmit($correctCommit);
    expect(mainIntegrationGit($this->worktree, ['show', '-s', '--format=%P', 'HEAD']))->toBe($integratedHead);
    mainIntegrationAdvanceRemote('main advanced after ordinary correction');
    $nextHold = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect($nextHold->state)->toBe('integration_required')->and($nextHold->round)->toBe(2);
    mainIntegrationRequest($nextHold);
    $next = app(AdvanceTaskWorkspace::class)->handle($this->workspace);
    expect(app(TaskMainIntegration::class)->binding($next->run()->firstOrFail())['main_sha'])->toBe($nextHold->final_preflight['main_sha'])
        ->and(TaskFinalContinuation::query()->orderBy('id')->first()->toArray())->toBe($prior)
        ->and(TaskFinalContinuation::query()->orderBy('final_round')->pluck('mode')->all())
        ->toBe(['integrate_main', 'append_correction', 'integrate_main']);
    $manifest = app(TaskRuntimePlan::class)->manifest($this->root);
    expect(app(TaskManifestAmendmentHistory::class)->ledger($this->workspace, $manifest))->toBe([])
        ->and(app(TaskRuntimePlan::class)->effectiveHash($this->workspace))->toBe(TaskManifestAmendmentHistory::hash($manifest));
});
