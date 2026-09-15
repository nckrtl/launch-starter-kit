<?php

use App\Models\ProjectOrchestration;
use App\Models\Task;
use App\Models\TaskRun;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);

    $projects = app(SharedKnowledgeProjectRepository::class);
    $projects->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $projects->create('launch', ['name' => 'Launch', 'status' => 'active']);
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

function taskFixture(string $title, TaskKind $kind = TaskKind::Executable, ?Task $parent = null, string $projectId = 'orbit'): Task
{
    return app(CreateTask::class)->handle($projectId, $title, kind: $kind, parent: $parent);
}

function startTaskRunFixture(Task $task, string $key, array $input = []): TaskRun
{
    return app(StartTaskRun::class)->handle($task, $key, 'worker-'.$task->id, 'test-reviewer', $input);
}

function completeTaskFixture(Task $task): TaskRun
{
    $run = startTaskRunFixture($task, 'complete');
    $review = app(MarkTaskRunReadyForReview::class)->handle($run, $run->worker_ref, 1);
    app(RecordTaskRunReview::class)->handle($review, 'test-reviewer', TaskReviewVerdict::Pass, 'Criteria verified.', 'test://review/'.$review->id);
    app(AcceptTaskRun::class)->handle($review);

    return $run->refresh();
}

it('creates a standalone task for a manifest project without orchestration', function () {
    $task = app(CreateTask::class)->handle('orbit', '  Implement paths output  ', 'Keep JSON unchanged.');
    taskFixture('Launch task', projectId: 'launch');
    $fresh = $task->fresh();

    $this->assertModelExists($task);
    expect($fresh->project_id)->toBe('orbit')
        ->and($fresh->title)->toBe('Implement paths output')
        ->and($fresh->description)->toBe('Keep JSON unchanged.')
        ->and($fresh->parent_id)->toBeNull()
        ->and($fresh->kind)->toBe(TaskKind::Executable)
        ->and($fresh->status)->toBe(TaskStatus::Pending)
        ->and($fresh->completed_at)->toBeNull()
        ->and(Task::query()->forProject('orbit')->pluck('id')->all())->toBe([$task->id])
        ->and(ProjectOrchestration::query()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0);
});

it('rejects missing and invalid project identities', function (string $projectId) {
    expect(fn () => taskFixture('Task', projectId: $projectId))->toThrow(InvalidArgumentException::class)
        ->and(Task::query()->count())->toBe(0);
})->with(['missing', '../orbit', 'Orbit', str_repeat('a', 81)]);

it('validates task titles', function (string $title) {
    expect(fn () => taskFixture($title))->toThrow(InvalidArgumentException::class)
        ->and(Task::query()->count())->toBe(0);
})->with(['', '   ', str_repeat('a', 256)]);

it('allows nested group children and exposes their relationships', function () {
    $root = taskFixture('Feature', TaskKind::Group);
    $group = taskFixture('Implementation', TaskKind::Group, $root);
    $child = taskFixture('Paths output', parent: $group);

    expect($root->children()->sole()->is($group))->toBeTrue()
        ->and($group->parent()->sole()->is($root))->toBeTrue()
        ->and($child->parent()->sole()->is($group))->toBeTrue()
        ->and($child->project_id)->toBe('orbit');
});

it('rejects children of executable tasks and parents from another project', function () {
    $executable = taskFixture('Executable');
    $group = taskFixture('Launch group', TaskKind::Group, projectId: 'launch');

    expect(fn () => taskFixture('Child', parent: $executable))->toThrow(LogicException::class)
        ->and(fn () => taskFixture('Child', parent: $group))->toThrow(InvalidArgumentException::class)
        ->and(Task::query()->count())->toBe(2);
});

it('adds dependencies idempotently and exposes the reverse relationship', function () {
    $task = taskFixture('Implement');
    $dependency = taskFixture('Plan');
    $action = app(AddTaskDependency::class);
    $action->handle($task, $dependency);
    $action->handle($task, $dependency);

    expect($task->dependencies()->sole()->is($dependency))->toBeTrue()
        ->and($dependency->dependents()->sole()->is($task))->toBeTrue();
});

it('allows dependencies between siblings within the same group', function () {
    $group = taskFixture('Feature', TaskKind::Group);
    $dependency = taskFixture('Plan', parent: $group);
    $task = taskFixture('Implement', parent: $group);
    app(AddTaskDependency::class)->handle($task, $dependency);

    expect($task->dependencies()->sole()->is($dependency))->toBeTrue()
        ->and(fn () => startTaskRunFixture($task, 'implement'))->toThrow(LogicException::class);

    completeTaskFixture($dependency);

    expect(startTaskRunFixture($task, 'implement')->attempt)->toBe(1);
});

it('rejects self and cross-project dependencies', function () {
    $task = taskFixture('Orbit task');
    $other = taskFixture('Launch task', projectId: 'launch');
    $action = app(AddTaskDependency::class);

    expect(fn () => $action->handle($task, $task))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->handle($task, $other))->toThrow(InvalidArgumentException::class)
        ->and($task->dependencies()->count())->toBe(0);
});

it('rejects transitive dependency cycles and rolls back the proposed edge', function () {
    $first = taskFixture('First');
    $second = taskFixture('Second');
    $third = taskFixture('Third');
    $action = app(AddTaskDependency::class);
    $action->handle($first, $second);
    $action->handle($second, $third);

    expect(fn () => $action->handle($third, $first))->toThrow(LogicException::class)
        ->and($third->dependencies()->count())->toBe(0)
        ->and($first->dependencies()->count())->toBe(1)
        ->and($second->dependencies()->count())->toBe(1);
});

it('rejects dependencies between a group and its own descendant', function (bool $groupDependsOnChild) {
    $group = taskFixture('Group', TaskKind::Group);
    $nested = taskFixture('Nested', TaskKind::Group, $group);
    $child = taskFixture('Child', parent: $nested);
    [$task, $dependency] = $groupDependsOnChild ? [$group, $child] : [$child, $group];

    expect(fn () => app(AddTaskDependency::class)->handle($task, $dependency))->toThrow(InvalidArgumentException::class)
        ->and($task->dependencies()->count())->toBe(0);
})->with([true, false]);

it('rejects dependencies between root tasks and unrelated nested tasks in either direction', function (bool $rootDependsOnChild) {
    $group = taskFixture('Group', TaskKind::Group);
    $child = taskFixture('Child', parent: $group);
    $external = taskFixture('External');
    [$task, $dependency] = $rootDependsOnChild ? [$external, $child] : [$child, $external];

    expect(fn () => app(AddTaskDependency::class)->handle($task, $dependency))->toThrow(InvalidArgumentException::class)
        ->and($task->dependencies()->count())->toBe(0);
})->with([true, false]);

it('rejects cross-group dependencies even when both groups belong to the same project', function () {
    $firstGroup = taskFixture('First group', TaskKind::Group);
    $firstChild = taskFixture('First child', parent: $firstGroup);
    $secondGroup = taskFixture('Second group', TaskKind::Group);
    $secondChild = taskFixture('Second child', parent: $secondGroup);
    $action = app(AddTaskDependency::class);
    $action->handle($firstGroup, $secondGroup);

    expect(fn () => $action->handle($secondChild, $firstChild))->toThrow(InvalidArgumentException::class)
        ->and($secondChild->dependencies()->count())->toBe(0);
});

it('rejects transitive dependency cycles among nested siblings', function () {
    $group = taskFixture('Group', TaskKind::Group);
    $first = taskFixture('First', parent: $group);
    $second = taskFixture('Second', parent: $group);
    $third = taskFixture('Third', parent: $group);
    $action = app(AddTaskDependency::class);
    $action->handle($second, $first);
    $action->handle($third, $second);

    expect(fn () => $action->handle($first, $third))->toThrow(LogicException::class)
        ->and($first->dependencies()->count())->toBe(0);
});

it('requires successful dependencies before starting and does not create blocked attempts', function () {
    $task = taskFixture('Implement');
    $dependency = taskFixture('Plan');
    app(AddTaskDependency::class)->handle($task, $dependency);

    expect(fn () => startTaskRunFixture($task, 'implement'))->toThrow(LogicException::class);
    $failed = startTaskRunFixture($dependency, 'plan');
    app(FailTaskRun::class)->handle($failed, 0, TaskStatus::Running, 'Missing context');

    expect(fn () => startTaskRunFixture($task, 'implement'))->toThrow(LogicException::class)
        ->and($task->runs()->count())->toBe(0)
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending);

    completeTaskFixture($dependency);
    $run = startTaskRunFixture($task, 'implement');

    expect($run->attempt)->toBe(1)
        ->and($run->status)->toBe(TaskRunStatus::Running);
});

it('inherits prerequisites from every ancestor before a grandchild can start', function () {
    $root = taskFixture('Feature', TaskKind::Group);
    $nestedPrerequisite = taskFixture('Review plan', parent: $root);
    $nested = taskFixture('Implementation', TaskKind::Group, $root);
    $child = taskFixture('Code', parent: $nested);
    $rootPrerequisite = taskFixture('Plan');
    app(AddTaskDependency::class)->handle($root, $rootPrerequisite);
    app(AddTaskDependency::class)->handle($nested, $nestedPrerequisite);

    expect(fn () => startTaskRunFixture($child, 'code'))->toThrow(LogicException::class);
    completeTaskFixture($rootPrerequisite);
    expect(fn () => startTaskRunFixture($child, 'code'))->toThrow(LogicException::class);
    completeTaskFixture($nestedPrerequisite);

    expect(startTaskRunFixture($child, 'code')->attempt)->toBe(1);
});

it('does not dispatch groups or complete empty groups or executable tasks as groups', function () {
    $group = taskFixture('Group', TaskKind::Group);
    $task = taskFixture('Executable');

    expect(fn () => startTaskRunFixture($group, 'group'))->toThrow(LogicException::class)
        ->and(fn () => app(CompleteTaskGroup::class)->handle($group))->toThrow(LogicException::class)
        ->and(fn () => app(CompleteTaskGroup::class)->handle($task))->toThrow(LogicException::class)
        ->and(TaskRun::query()->count())->toBe(0);
});

it('completes nested groups explicitly only after every child succeeds', function () {
    $root = taskFixture('Feature', TaskKind::Group);
    $nested = taskFixture('Implementation', TaskKind::Group, $root);
    $first = taskFixture('First', parent: $nested);
    $second = taskFixture('Second', parent: $nested);
    $complete = app(CompleteTaskGroup::class);
    completeTaskFixture($first);

    expect(fn () => $complete->handle($nested))->toThrow(LogicException::class)
        ->and(fn () => $complete->handle($root))->toThrow(LogicException::class);
    completeTaskFixture($second);

    expect($nested->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($root->fresh()->status)->toBe(TaskStatus::Pending);
    $finishedNested = $complete->handle($nested);
    $finishedRoot = $complete->handle($root);

    expect($finishedNested->status)->toBe(TaskStatus::Completed)
        ->and($finishedRoot->status)->toBe(TaskStatus::Completed)
        ->and($finishedRoot->completed_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($complete->handle($root)->completed_at->equalTo($finishedRoot->completed_at))->toBeTrue()
        ->and($root->runs()->count())->toBe(0)
        ->and($nested->runs()->count())->toBe(0);
});

it('rejects late children using a stale group model after completion', function () {
    $group = taskFixture('Group', TaskKind::Group);
    $child = taskFixture('Child', parent: $group);
    completeTaskFixture($child);
    app(CompleteTaskGroup::class)->handle($group);

    expect($group->status)->toBe(TaskStatus::Pending)
        ->and(fn () => taskFixture('Late child', parent: $group))->toThrow(LogicException::class)
        ->and($group->children()->count())->toBe(1);
});

it('freezes dependency edits after any nested descendant has attempted execution', function () {
    $root = taskFixture('Root', TaskKind::Group);
    $nested = taskFixture('Nested', TaskKind::Group, $root);
    $child = taskFixture('Child', parent: $nested);
    $rootDependency = taskFixture('Root prerequisite');
    $nestedDependency = taskFixture('Nested prerequisite', parent: $root);
    $childDependency = taskFixture('Child prerequisite', parent: $nested);
    $run = startTaskRunFixture($child, 'first');
    app(FailTaskRun::class)->handle($run, 0, TaskStatus::Running, 'Needs correction');

    foreach ([[$root, $rootDependency], [$nested, $nestedDependency], [$child, $childDependency]] as [$task, $dependency]) {
        expect(fn () => app(AddTaskDependency::class)->handle($task, $dependency))->toThrow(LogicException::class)
            ->and($task->dependencies()->count())->toBe(0);
    }
});

it('starts an attempt once and rejects competing attempts or changed idempotent inputs', function () {
    $task = taskFixture('Task');
    $start = startTaskRunFixture(...);
    $run = $start($task, 'first', ['candidate' => 'abc']);
    $repeated = $start($task, 'first', ['candidate' => 'abc']);

    expect($repeated->is($run))->toBeTrue()
        ->and($run->attempt)->toBe(1)
        ->and($run->active_task_id)->toBe($task->id)
        ->and($run->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($run->task()->sole()->is($task))->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and(fn () => $start($task, 'second'))->toThrow(LogicException::class)
        ->and(fn () => $start($task, 'first', ['candidate' => 'def']))->toThrow(LogicException::class)
        ->and($task->runs()->count())->toBe(1);
});

it('validates attempt idempotency keys', function (string $key) {
    $task = taskFixture('Task');

    expect(fn () => startTaskRunFixture($task, $key))->toThrow(InvalidArgumentException::class)
        ->and($task->runs()->count())->toBe(0)
        ->and($task->fresh()->status)->toBe(TaskStatus::Pending);
})->with(['', '  ', str_repeat('a', 101)]);

it('scopes idempotency keys to each task', function () {
    $first = taskFixture('First');
    $second = taskFixture('Second');
    $firstRun = startTaskRunFixture($first, 'shared');
    $secondRun = startTaskRunFixture($second, 'shared');

    expect($firstRun->is($secondRun))->toBeFalse()
        ->and($firstRun->attempt)->toBe(1)
        ->and($secondRun->attempt)->toBe(1);
});

it('recognizes equivalent nested payloads after JSON persistence without ignoring types or list order', function () {
    $task = taskFixture('Task');
    $original = ['details' => ['count' => 1.0, 'enabled' => true], 'items' => ['first', 'second']];
    $equivalent = ['items' => ['first', 'second'], 'details' => ['enabled' => true, 'count' => 1]];
    $run = startTaskRunFixture($task, 'first', $original);
    $ready = app(MarkTaskRunReadyForReview::class);

    expect(startTaskRunFixture($task, 'first', $equivalent)->is($run))->toBeTrue();
    $review = $ready->handle($run, $run->worker_ref, 1, $original);

    expect($ready->handle($run, $run->worker_ref, 1, $equivalent)->is($review))->toBeTrue();

    foreach ([
        ['details' => ['count' => 2, 'enabled' => true], 'items' => ['first', 'second']],
        ['details' => ['count' => '1', 'enabled' => true], 'items' => ['first', 'second']],
        ['details' => ['count' => 1, 'enabled' => 1], 'items' => ['first', 'second']],
        ['details' => ['count' => 1, 'enabled' => true], 'items' => ['second', 'first']],
    ] as $different) {
        expect(fn () => startTaskRunFixture($task, 'first', $different))->toThrow(LogicException::class)
            ->and(fn () => $ready->handle($run, $run->worker_ref, 1, $different))->toThrow(LogicException::class);
    }
});

it('preserves failed attempts while retries receive fresh output and monotonic numbers', function () {
    $task = taskFixture('Task');
    $fail = app(FailTaskRun::class);
    $first = startTaskRunFixture($task, 'first', ['candidate' => 'abc']);
    $failed = $fail->handle($first, 0, TaskStatus::Running, 'Checks failed', ['finding' => 'Missing test']);
    $repeated = startTaskRunFixture($task, 'first', ['candidate' => 'abc']);

    expect($repeated->is($first))->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($failed->active_task_id)->toBeNull()
        ->and($failed->active_root_task_id)->toBeNull()
        ->and($task->fresh()->completed_at)->toBeNull();

    $second = startTaskRunFixture($task, 'second', ['candidate' => 'def'])->fresh();

    expect($second->attempt)->toBe(2)
        ->and($second->input)->toBe(['candidate' => 'def'])
        ->and($second->output)->toBeNull()
        ->and($second->failure_message)->toBeNull()
        ->and($first->fresh()->input)->toBe(['candidate' => 'abc'])
        ->and($first->fresh()->output['result'])->toBe(['finding' => 'Missing test']);

    $fail->handle($first, 0, TaskStatus::Running, 'Checks failed', ['finding' => 'Missing test']);
    startTaskRunFixture($task, 'first', ['candidate' => 'abc']);

    expect($task->fresh()->status)->toBe(TaskStatus::Running)
        ->and(fn () => app(MarkTaskRunReadyForReview::class)->handle($first, $first->worker_ref, 1))->toThrow(LogicException::class)
        ->and($second->fresh()->status)->toBe(TaskRunStatus::Running);

    $review = app(MarkTaskRunReadyForReview::class)->handle($second, $second->worker_ref, 1, ['candidate' => 'def']);

    expect($second->fresh()->active_task_id)->toBe($task->id)
        ->and($second->fresh()->finished_at)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::AwaitingReview)
        ->and($task->runs()->orderBy('attempt')->pluck('attempt')->all())->toBe([1, 2])
        ->and(fn () => startTaskRunFixture($task, 'third'))->toThrow(LogicException::class);

    app(RecordTaskRunReview::class)->handle($review, $second->reviewer_ref, TaskReviewVerdict::Pass, 'Verified.', 'test://review');
    app(AcceptTaskRun::class)->handle($review);

    expect($second->fresh()->status)->toBe(TaskRunStatus::Completed)
        ->and($second->fresh()->output)->toBe(['candidate' => 'def'])
        ->and($second->fresh()->active_task_id)->toBeNull()
        ->and($second->fresh()->finished_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('accepts identical terminal failure results without changing them and rejects conflicting results', function () {
    $task = taskFixture('Task');
    $run = startTaskRunFixture($task, 'first');
    $fail = app(FailTaskRun::class);
    $finished = $fail->handle($run, 0, TaskStatus::Running, 'Failed checks', ['candidate' => 'abc']);
    $this->travel(1)->minute();
    $repeated = $fail->handle($run, 0, TaskStatus::Running, 'Failed checks', ['candidate' => 'abc']);

    expect($repeated->is($finished))->toBeTrue()
        ->and($repeated->finished_at->equalTo($finished->finished_at))->toBeTrue()
        ->and(fn () => $fail->handle($run, 0, TaskStatus::Running, 'Failed checks', ['candidate' => 'def']))->toThrow(LogicException::class)
        ->and(fn () => $fail->handle($run, 0, TaskStatus::Running, 'Different failure', ['candidate' => 'abc']))->toThrow(LogicException::class)
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($run->fresh()->output['result'])->toBe(['candidate' => 'abc']);
});

it('rejects malformed failure results without settling the active attempt', function (int $round, TaskStatus $status, string $reason) {
    $task = taskFixture('Task');
    $run = startTaskRunFixture($task, 'first');

    expect(fn () => app(FailTaskRun::class)->handle($run, $round, $status, $reason))->toThrow(InvalidArgumentException::class)
        ->and($run->fresh()->status)->toBe(TaskRunStatus::Running)
        ->and($run->fresh()->active_task_id)->toBe($task->id)
        ->and($task->fresh()->status)->toBe(TaskStatus::Running);
})->with([
    'invalid round' => [-1, TaskStatus::Running, 'Failure'],
    'failed without reason' => [0, TaskStatus::Running, ''],
    'failed with blank reason' => [0, TaskStatus::Running, '  '],
    'completed is not a handoff' => [0, TaskStatus::Completed, 'Failure'],
    'committing cannot be blindly aborted' => [0, TaskStatus::AwaitingCommit, 'Failure'],
]);

it('enforces active attempt, attempt number, and idempotency uniqueness in the database', function (string $constraint) {
    $task = taskFixture('Task');
    $run = startTaskRunFixture($task, 'first')->fresh();
    $attributes = $run->getAttributes();
    unset($attributes['id']);

    if ($constraint === 'active') {
        $attributes['attempt'] = 2;
        $attributes['idempotency_key'] = 'second';
    } else {
        $attributes['active_task_id'] = null;
        $attributes['active_root_task_id'] = null;
        $attributes['status'] = TaskRunStatus::Failed->value;
        $attributes['failure_message'] = 'Failed';
        $attributes['finished_at'] = now()->toDateTimeString();
        $attributes['attempt'] = $constraint === 'attempt' ? 1 : 2;
        $attributes['idempotency_key'] = $constraint === 'idempotency' ? 'first' : 'second';
    }

    expect(fn () => DB::transaction(fn () => DB::table($run->getTable())->insert($attributes)))->toThrow(QueryException::class)
        ->and($task->runs()->count())->toBe(1)
        ->and($run->fresh()->active_task_id)->toBe($task->id);
})->with(['active', 'attempt', 'idempotency']);

it('enforces dependency uniqueness in the database', function () {
    $task = taskFixture('Task');
    $dependency = taskFixture('Dependency');
    app(AddTaskDependency::class)->handle($task, $dependency);

    expect(fn () => DB::transaction(fn () => $task->dependencies()->attach($dependency->id)))->toThrow(QueryException::class)
        ->and($task->dependencies()->count())->toBe(1);
});

it('preserves task identity and run inputs through model updates', function () {
    $task = taskFixture('Task');
    $group = taskFixture('Group', TaskKind::Group);
    $run = startTaskRunFixture($task, 'first', ['candidate' => 'abc']);

    foreach (['project_id' => 'launch', 'parent_id' => $group->id, 'kind' => TaskKind::Group, 'title' => 'Changed', 'description' => 'Changed'] as $field => $value) {
        expect(fn () => $task->fresh()->update([$field => $value]))->toThrow(LogicException::class);
    }

    foreach (['task_id' => $group->id, 'root_task_id' => $group->id, 'worker_ref' => 'replacement', 'reviewer_ref' => 'replacement', 'base_sha' => str_repeat('a', 40), 'attempt' => 2, 'idempotency_key' => 'changed', 'input' => ['candidate' => 'def'], 'started_at' => now()->addMinute()] as $field => $value) {
        expect(fn () => $run->fresh()->update([$field => $value]))->toThrow(LogicException::class);
    }

    expect($task->fresh()->title)->toBe('Task')
        ->and($run->fresh()->input)->toBe(['candidate' => 'abc']);
});

it('prevents terminal result edits and deletion of task history through models', function () {
    $task = taskFixture('Task');
    $run = completeTaskFixture($task);

    expect(fn () => $run->fresh()->update(['output' => ['replaced' => true]]))->toThrow(LogicException::class)
        ->and(fn () => $run->fresh()->update(['status' => TaskRunStatus::Running]))->toThrow(LogicException::class)
        ->and(fn () => $run->delete())->toThrow(LogicException::class)
        ->and(fn () => $task->delete())->toThrow(LogicException::class);

    $this->assertModelExists($task);
    $this->assertModelExists($run);
});
