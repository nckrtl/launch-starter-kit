<?php

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\AcceptTaskRun;
use App\Tasks\Actions\AddTaskDependency;
use App\Tasks\Actions\CompleteTaskGroup;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\FailTaskRun;
use App\Tasks\Actions\MarkTaskRunReadyForReview;
use App\Tasks\Actions\RecordTaskRunReview;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskCommit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-reviews-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->task = app(CreateTask::class)->handle('orbit', 'Implement feature');
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function startReviewRunFixture(Task $task, string $key = 'first', ?string $baseSha = null): TaskRun
{
    return app(StartTaskRun::class)->handle($task, $key, 'worker-'.$task->id, 'feature-reviewer', baseSha: $baseSha);
}

function requestTaskReviewFixture(TaskRun $run, int $round = 1, array $output = ['summary' => 'Ready']): TaskRunReview
{
    $treeSha = $run->base_sha === null ? null : str_repeat('b', mb_strlen($run->base_sha));

    return app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, $round, $output, $treeSha);
}

function recordTaskReviewFixture(TaskRunReview $review, TaskReviewVerdict $verdict = TaskReviewVerdict::Pass): TaskRunReview
{
    return app(RecordTaskRunReview::class)->handle($review, 'feature-reviewer', $verdict, 'Review findings.', 'test://review/'.$review->id);
}

function acceptTaskReviewFixture(TaskRunReview $review): Task
{
    $run = $review->taskRun()->sole();
    $commit = $run->base_sha === null ? null : new TaskCommit(str_repeat('c', mb_strlen($run->base_sha)), $review->tree_sha, [$run->base_sha]);

    return app(AcceptTaskRun::class)->handle($review, $commit);
}

it('keeps a run active through review and only releases dependents after commit acceptance', function () {
    $dependent = app(CreateTask::class)->handle('orbit', 'Next task');
    app(AddTaskDependency::class)->handle($dependent, $this->task);
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $review = requestTaskReviewFixture($run);

    expect($run->fresh()->status)->toBe(TaskRunStatus::Running)
        ->and($run->fresh()->finished_at)->toBeNull()
        ->and($run->fresh()->commit_sha)->toBeNull()
        ->and($run->fresh()->output)->toBeNull()
        ->and($run->fresh()->active_task_id)->toBe($this->task->id)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($this->task->fresh()->completed_at)->toBeNull()
        ->and(fn () => startReviewRunFixture($dependent))->toThrow(LogicException::class)
        ->and(fn () => acceptTaskReviewFixture($review))->toThrow(LogicException::class);

    $verdict = recordTaskReviewFixture($review);

    expect($review->taskRun()->sole()->is($run))->toBeTrue()
        ->and($run->reviews()->sole()->is($review))->toBeTrue()
        ->and($verdict->reviewed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCommit)
        ->and($run->fresh()->commit_sha)->toBeNull()
        ->and(fn () => startReviewRunFixture($dependent))->toThrow(LogicException::class)
        ->and(fn () => requestTaskReviewFixture($run, 2))->toThrow(LogicException::class)
        ->and(fn () => app(AcceptTaskRun::class)->handle($review))->toThrow(LogicException::class);

    $accepted = acceptTaskReviewFixture($review);

    expect($accepted->status)->toBe(TaskStatus::Completed)
        ->and($accepted->accepted_task_run_id)->toBe($run->id)
        ->and($accepted->acceptedRun()->sole()->is($run))->toBeTrue()
        ->and($accepted->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($run->fresh()->status)->toBe(TaskRunStatus::Completed)
        ->and($run->fresh()->commit_sha)->toBe(str_repeat('c', 40))
        ->and($run->fresh()->finished_at->equalTo($accepted->completed_at))->toBeTrue()
        ->and($run->fresh()->active_root_task_id)->toBeNull()
        ->and(startReviewRunFixture($dependent)->attempt)->toBe(1);
});

it('requires accepted children before explicit group completion', function () {
    $group = app(CreateTask::class)->handle('orbit', 'Feature', kind: TaskKind::Group);
    $child = app(CreateTask::class)->handle('orbit', 'Code', parent: $group);
    $review = requestTaskReviewFixture(startReviewRunFixture($child));
    recordTaskReviewFixture($review);

    expect(fn () => app(CompleteTaskGroup::class)->handle($group))->toThrow(LogicException::class);
    acceptTaskReviewFixture($review);

    expect($group->fresh()->status)->toBe(TaskStatus::Pending)
        ->and(app(CompleteTaskGroup::class)->handle($group)->status)->toBe(TaskStatus::Completed);
});

it('supports immutable non-code output without requiring a Git commit', function () {
    $run = startReviewRunFixture($this->task);
    $review = requestTaskReviewFixture($run);
    recordTaskReviewFixture($review);
    acceptTaskReviewFixture($review);

    expect($this->task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($run->fresh()->commit_sha)->toBeNull()
        ->and($run->fresh()->output)->toBe(['summary' => 'Ready']);
});

it('keeps requested changes in the same attempt and preserves every review round', function () {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $first = requestTaskReviewFixture($run, 1, ['summary' => 'First version']);
    recordTaskReviewFixture($first, TaskReviewVerdict::Revise);

    expect($this->task->fresh()->status)->toBe(TaskStatus::ChangesRequested)
        ->and($run->fresh()->status)->toBe(TaskRunStatus::Running)
        ->and($run->fresh()->finished_at)->toBeNull()
        ->and(fn () => startReviewRunFixture($this->task, 'correction'))->toThrow(LogicException::class)
        ->and(startReviewRunFixture($this->task, baseSha: str_repeat('a', 40))->is($run))->toBeTrue()
        ->and(fn () => acceptTaskReviewFixture($first))->toThrow(LogicException::class);

    $second = app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 2, ['summary' => 'Corrected'], str_repeat('d', 40));
    requestTaskReviewFixture($run, 1, ['summary' => 'First version']);
    recordTaskReviewFixture($first, TaskReviewVerdict::Revise);

    expect($this->task->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($this->task->runs()->count())->toBe(1)
        ->and($run->reviews()->orderBy('round')->pluck('round')->all())->toBe([1, 2])
        ->and(fn () => recordTaskReviewFixture($first))->toThrow(LogicException::class)
        ->and(fn () => app(FailTaskRun::class)->handle($run, 1, TaskStatus::AwaitingReview, 'Old failure'))->toThrow(LogicException::class)
        ->and(fn () => acceptTaskReviewFixture($second))->toThrow(LogicException::class);

    recordTaskReviewFixture($second);
    acceptTaskReviewFixture($second);

    expect($this->task->fresh()->accepted_task_run_id)->toBe($run->id)
        ->and($run->fresh()->output)->toBe(['summary' => 'Corrected'])
        ->and($first->fresh()->tree_sha)->toBe(str_repeat('b', 40))
        ->and($first->fresh()->output)->toBe(['summary' => 'First version'])
        ->and($first->fresh()->verdict)->toBe(TaskReviewVerdict::Revise)
        ->and(fn () => acceptTaskReviewFixture($first))->toThrow(LogicException::class);
});

it('rejects malformed Git identities without changing the handoff', function (string $sha) {
    expect(fn () => startReviewRunFixture($this->task, baseSha: $sha))->toThrow(InvalidArgumentException::class);
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));

    expect(fn () => app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 1, treeSha: $sha))->toThrow(InvalidArgumentException::class)
        ->and($run->reviews()->count())->toBe(0)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Running);
})->with(['', 'main', 'abc123', str_repeat('A', 40), str_repeat('a', 41), str_repeat('a', 40)."\n"]);

it('supports full SHA-256 Git identities', function () {
    $review = requestTaskReviewFixture(startReviewRunFixture($this->task, baseSha: str_repeat('a', 64)));
    recordTaskReviewFixture($review);

    expect(acceptTaskReviewFixture($review)->status)->toBe(TaskStatus::Completed);
});

it('requires a tree snapshot exactly when the run has a code baseline', function (?string $base, ?string $tree) {
    $run = startReviewRunFixture($this->task, baseSha: $base);

    expect(fn () => app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 1, treeSha: $tree))
        ->toThrow(InvalidArgumentException::class)
        ->and($run->reviews()->exists())->toBeFalse();
})->with([
    [str_repeat('a', 40), null],
    [null, str_repeat('b', 40)],
    [str_repeat('a', 40), str_repeat('b', 64)],
]);

it('rejects commits with a different tree or parent and leaves the passed run recoverable', function (string $field) {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $review = requestTaskReviewFixture($run);
    recordTaskReviewFixture($review);
    $commit = new TaskCommit(str_repeat('c', 40), str_repeat($field === 'tree' ? 'd' : 'b', 40), [str_repeat($field === 'parent' ? 'd' : 'a', 40)]);

    expect(fn () => app(AcceptTaskRun::class)->handle($review, $commit))->toThrow(LogicException::class)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCommit)
        ->and($run->fresh()->active_task_id)->toBe($this->task->id)
        ->and($run->fresh()->commit_sha)->toBeNull();

    expect(acceptTaskReviewFixture($review)->status)->toBe(TaskStatus::Completed);
})->with(['tree', 'parent']);

it('rejects merge commits root commits and invalid commit identities', function (string $sha, string $tree, array $parents) {
    expect(fn () => new TaskCommit($sha, $tree, $parents))->toThrow(InvalidArgumentException::class);
})->with([
    [str_repeat('c', 40), str_repeat('b', 40), []],
    [str_repeat('c', 40), str_repeat('b', 40), [str_repeat('a', 40), str_repeat('d', 40)]],
    [str_repeat('c', 40), str_repeat('b', 40), [1 => str_repeat('a', 40)]],
    ['short', str_repeat('b', 40), [str_repeat('a', 40)]],
    [str_repeat('c', 40), str_repeat('b', 64), [str_repeat('a', 40)]],
    [str_repeat('a', 40), str_repeat('b', 40), [str_repeat('a', 40)]],
]);

it('rejects commits for non-code runs', function () {
    $review = requestTaskReviewFixture(startReviewRunFixture($this->task));
    recordTaskReviewFixture($review);

    expect(fn () => app(AcceptTaskRun::class)->handle($review, new TaskCommit(str_repeat('c', 40), str_repeat('b', 40), [str_repeat('a', 40)])))
        ->toThrow(LogicException::class);
});

it('rejects stale verdicts after a failed attempt is replaced', function () {
    $first = startReviewRunFixture($this->task);
    $review = requestTaskReviewFixture($first);
    app(FailTaskRun::class)->handle($first, 1, TaskStatus::AwaitingReview, 'Reviewer unavailable');
    $second = startReviewRunFixture($this->task, 'retry');

    expect(fn () => recordTaskReviewFixture($review))->toThrow(LogicException::class)
        ->and(fn () => acceptTaskReviewFixture($review))->toThrow(LogicException::class)
        ->and(fn () => requestTaskReviewFixture($first, 2))->toThrow(LogicException::class)
        ->and($review->fresh()->verdict)->toBeNull()
        ->and($second->fresh()->status)->toBe(TaskRunStatus::Running);
});

it('requires reviewer identity and durable review evidence', function (string $reviewer, string $summary, string $evidence) {
    $review = requestTaskReviewFixture(startReviewRunFixture($this->task));

    expect(fn () => app(RecordTaskRunReview::class)->handle($review, $reviewer, TaskReviewVerdict::Pass, $summary, $evidence))
        ->toThrow(InvalidArgumentException::class)
        ->and($review->fresh()->verdict)->toBeNull();
})->with([
    [' ', 'Pass.', 'test://evidence'],
    [str_repeat('a', 256), 'Pass.', 'test://evidence'],
    ['feature-reviewer', ' ', 'test://evidence'],
    ['feature-reviewer', 'Pass.', ' '],
]);

it('rejects handoffs by an unassigned actor or the other role', function () {
    $run = startReviewRunFixture($this->task);

    foreach (['other-worker', $run->reviewer_ref] as $actor) {
        expect(fn () => app(MarkTaskRunReadyForReview::class)->handle($run, $actor, 1))->toThrow(LogicException::class);
    }

    $review = requestTaskReviewFixture($run);

    foreach (['other-reviewer', $run->worker_ref] as $actor) {
        expect(fn () => app(RecordTaskRunReview::class)->handle($review, $actor, TaskReviewVerdict::Pass, 'Pass.', 'test://review'))
            ->toThrow(LogicException::class);
    }

    expect($review->fresh()->verdict)->toBeNull()
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingReview);
});

it('validates distinct and stable agent assignments', function (string $worker, string $reviewer) {
    expect(fn () => app(StartTaskRun::class)->handle($this->task, 'first', $worker, $reviewer))->toThrow(InvalidArgumentException::class)
        ->and($this->task->runs()->count())->toBe(0);
})->with([['', 'reviewer'], ['worker', ''], ['same', 'same'], [' worker', 'worker'], [str_repeat('a', 256), 'reviewer']]);

it('rejects changed assignment or baseline under the same start key', function (string $field) {
    startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $arguments = ['workerRef' => 'worker-'.$this->task->id, 'reviewerRef' => 'feature-reviewer', 'baseSha' => str_repeat('a', 40)];
    $arguments[$field] = $field === 'baseSha' ? str_repeat('d', 40) : 'replacement';

    expect(fn () => app(StartTaskRun::class)->handle($this->task, 'first', ...$arguments))->toThrow(LogicException::class)
        ->and($this->task->runs()->count())->toBe(1);
})->with(['workerRef', 'reviewerRef', 'baseSha']);

it('preserves identical handoff review and acceptance retries without changing timestamps', function () {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $review = requestTaskReviewFixture($run);
    $verdict = recordTaskReviewFixture($review);
    $this->travel(1)->minute();
    $accepted = acceptTaskReviewFixture($review);
    $this->travel(1)->minute();

    expect(requestTaskReviewFixture($run)->requested_at->equalTo($review->requested_at))->toBeTrue()
        ->and(recordTaskReviewFixture($review)->reviewed_at->equalTo($verdict->reviewed_at))->toBeTrue()
        ->and(acceptTaskReviewFixture($review)->completed_at->equalTo($accepted->completed_at))->toBeTrue()
        ->and($run->reviews()->count())->toBe(1)
        ->and($run->fresh()->finished_at->greaterThan($verdict->reviewed_at))->toBeTrue()
        ->and(fn () => startReviewRunFixture($this->task, 'second'))->toThrow(LogicException::class)
        ->and(fn () => app(AcceptTaskRun::class)->handle($review, new TaskCommit(str_repeat('d', 40), str_repeat('b', 40), [str_repeat('a', 40)])))
        ->toThrow(LogicException::class);
});

it('rejects conflicting verdict retries without changing accepted state', function (string $field) {
    $review = requestTaskReviewFixture(startReviewRunFixture($this->task));
    recordTaskReviewFixture($review);
    acceptTaskReviewFixture($review);
    $arguments = ['reviewerRef' => 'feature-reviewer', 'verdict' => TaskReviewVerdict::Pass, 'summary' => 'Review findings.', 'evidenceRef' => 'test://review/'.$review->id];
    $arguments[$field] = $field === 'verdict' ? TaskReviewVerdict::Revise : 'changed';

    expect(fn () => app(RecordTaskRunReview::class)->handle($review, ...$arguments))->toThrow(LogicException::class)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::Completed)
        ->and($review->fresh()->verdict)->toBe(TaskReviewVerdict::Pass);
})->with(['verdict', 'reviewerRef', 'summary', 'evidenceRef']);

it('rejects conflicting or skipped handoffs and keeps the original snapshot', function () {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));

    expect(fn () => requestTaskReviewFixture($run, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => requestTaskReviewFixture($run, 2))->toThrow(LogicException::class);
    $review = requestTaskReviewFixture($run);

    expect(fn () => requestTaskReviewFixture($run, 1, ['summary' => 'Different']))->toThrow(LogicException::class)
        ->and(fn () => app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 1, ['summary' => 'Ready'], str_repeat('d', 40)))->toThrow(LogicException::class)
        ->and(fn () => requestTaskReviewFixture($run, 2))->toThrow(LogicException::class)
        ->and($run->reviews()->count())->toBe(1)
        ->and($review->fresh()->tree_sha)->toBe(str_repeat('b', 40));
});

it('preserves submitted content recorded verdicts and accepted output through models', function () {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $review = requestTaskReviewFixture($run);

    foreach (['tree_sha' => str_repeat('d', 40), 'round' => 2, 'output' => ['replaced' => true], 'requested_at' => now()->addMinute()] as $field => $value) {
        expect(fn () => $review->fresh()->update([$field => $value]))->toThrow(LogicException::class);
    }

    recordTaskReviewFixture($review);
    acceptTaskReviewFixture($review);

    expect(fn () => $run->fresh()->update(['commit_sha' => str_repeat('d', 40)]))->toThrow(LogicException::class)
        ->and(fn () => $review->fresh()->update(['verdict' => TaskReviewVerdict::Revise]))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class);

    foreach (['status' => TaskStatus::Pending, 'completed_at' => null, 'accepted_task_run_id' => null] as $field => $value) {
        expect(fn () => $this->task->fresh()->update([$field => $value]))->toThrow(LogicException::class);
    }
});

it('enforces unique review rounds in the database', function () {
    $run = startReviewRunFixture($this->task);
    $review = requestTaskReviewFixture($run)->fresh();
    $attributes = $review->getAttributes();
    unset($attributes['id']);

    expect(fn () => DB::transaction(fn () => DB::table($review->getTable())->insert($attributes)))->toThrow(QueryException::class)
        ->and($run->reviews()->count())->toBe(1);
});

it('holds the root execution slot across implementation review corrections and commit', function () {
    $root = app(CreateTask::class)->handle('orbit', 'Feature', kind: TaskKind::Group);
    $nested = app(CreateTask::class)->handle('orbit', 'Nested', kind: TaskKind::Group, parent: $root);
    $first = app(CreateTask::class)->handle('orbit', 'First', parent: $nested);
    $second = app(CreateTask::class)->handle('orbit', 'Second', parent: $root);
    $run = startReviewRunFixture($first, baseSha: str_repeat('a', 40));

    expect(fn () => startReviewRunFixture($second))->toThrow(LogicException::class);
    $review = requestTaskReviewFixture($run);
    expect(fn () => startReviewRunFixture($second))->toThrow(LogicException::class);
    recordTaskReviewFixture($review, TaskReviewVerdict::Revise);
    expect(fn () => startReviewRunFixture($second))->toThrow(LogicException::class);
    $review = requestTaskReviewFixture($run, 2);
    recordTaskReviewFixture($review);
    expect(fn () => startReviewRunFixture($second))->toThrow(LogicException::class);
    acceptTaskReviewFixture($review);

    expect(fn () => startReviewRunFixture($second, baseSha: str_repeat('a', 40)))->toThrow(LogicException::class)
        ->and(fn () => app(StartTaskRun::class)->handle($second, 'first', $run->worker_ref, $run->reviewer_ref))->toThrow(LogicException::class)
        ->and(fn () => app(StartTaskRun::class)->handle($second, 'first', 'second-worker', 'different-reviewer'))->toThrow(LogicException::class);

    app(CompleteTaskGroup::class)->handle($nested);

    expect(fn () => startReviewRunFixture($second, baseSha: str_repeat('a', 40)))->toThrow(LogicException::class)
        ->and(fn () => app(StartTaskRun::class)->handle($second, 'first', $run->worker_ref, $run->reviewer_ref))->toThrow(LogicException::class)
        ->and(fn () => app(StartTaskRun::class)->handle($second, 'first', 'second-worker', 'different-reviewer'))->toThrow(LogicException::class);

    $next = startReviewRunFixture($second, baseSha: str_repeat('c', 40));

    expect($next->root_task_id)->toBe($root->id)
        ->and($next->active_root_task_id)->toBe($root->id)
        ->and($next->reviewer_ref)->toBe($run->reviewer_ref)
        ->and($next->worker_ref)->not->toBe($run->worker_ref);
});

it('enforces one active root owner in the database even for unrelated descendants', function () {
    $root = app(CreateTask::class)->handle('orbit', 'Feature', kind: TaskKind::Group);
    $first = app(CreateTask::class)->handle('orbit', 'First', parent: $root);
    $second = app(CreateTask::class)->handle('orbit', 'Second', parent: $root);
    $run = startReviewRunFixture($first)->fresh();
    $attributes = $run->getAttributes();
    unset($attributes['id']);
    $attributes['task_id'] = $second->id;
    $attributes['active_task_id'] = $second->id;
    $attributes['worker_ref'] = 'second-worker';

    expect(fn () => DB::transaction(fn () => DB::table($run->getTable())->insert($attributes)))->toThrow(QueryException::class)
        ->and($second->runs()->count())->toBe(0);
});

it('ignores old task handoffs after the persistent reviewer moves to the next task', function () {
    $root = app(CreateTask::class)->handle('orbit', 'Feature', kind: TaskKind::Group);
    $first = app(CreateTask::class)->handle('orbit', 'First', parent: $root);
    $second = app(CreateTask::class)->handle('orbit', 'Second', parent: $root);
    $run = startReviewRunFixture($first);
    $review = requestTaskReviewFixture($run);
    recordTaskReviewFixture($review);
    acceptTaskReviewFixture($review);
    $next = startReviewRunFixture($second);
    $nextReview = requestTaskReviewFixture($next);

    requestTaskReviewFixture($run);
    recordTaskReviewFixture($review);
    acceptTaskReviewFixture($review);

    expect($second->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($nextReview->fresh()->verdict)->toBeNull()
        ->and($next->fresh()->active_root_task_id)->toBe($root->id);
});

it('does not abort passed work when commit outcome is uncertain', function () {
    $run = startReviewRunFixture($this->task, baseSha: str_repeat('a', 40));
    $review = requestTaskReviewFixture($run);
    recordTaskReviewFixture($review);

    expect(fn () => app(FailTaskRun::class)->handle($run, 1, TaskStatus::AwaitingReview, 'Delayed failure'))->toThrow(LogicException::class)
        ->and(fn () => app(FailTaskRun::class)->handle($run, 1, TaskStatus::AwaitingCommit, 'Commit timed out'))->toThrow(InvalidArgumentException::class)
        ->and($run->fresh()->status)->toBe(TaskRunStatus::Running)
        ->and($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCommit);
});

it('rolls back interrupted handoffs atomically and safely retries them', function (string $stage) {
    $run = startReviewRunFixture($this->task);
    $review = $stage === 'ready' ? null : requestTaskReviewFixture($run);
    if ($stage === 'accept') {
        recordTaskReviewFixture($review);
    }

    $beforeTask = $this->task->fresh()->getAttributes();
    $beforeRun = $run->fresh()->getAttributes();
    $beforeReviews = $run->reviews()->get()->map->getAttributes()->all();
    $crash = true;
    Event::listen('eloquent.updating: '.Task::class, function () use (&$crash) {
        if ($crash) {
            throw new RuntimeException('Injected interruption');
        }
    });

    $operation = match ($stage) {
        'ready' => fn () => requestTaskReviewFixture($run),
        'review' => fn () => recordTaskReviewFixture($review),
        'accept' => fn () => acceptTaskReviewFixture($review),
        'fail' => fn () => app(FailTaskRun::class)->handle($run, 1, TaskStatus::AwaitingReview, 'Failed'),
    };

    expect($operation)->toThrow(RuntimeException::class, 'Injected interruption')
        ->and($this->task->fresh()->getAttributes())->toBe($beforeTask)
        ->and($run->fresh()->getAttributes())->toBe($beforeRun)
        ->and($run->reviews()->get()->map->getAttributes()->all())->toBe($beforeReviews);

    $crash = false;
    $operation();
    $operation();

    expect($run->reviews()->count())->toBe(1);
})->with(['ready', 'review', 'accept', 'fail']);
