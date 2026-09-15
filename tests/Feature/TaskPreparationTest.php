<?php

use App\Models\TaskRun;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Actions\CreateTask;
use App\Tasks\Actions\FailTaskRun;
use App\Tasks\Actions\ReorderTaskChildren;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Actions\UpdateTask;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskCatalog;
use App\Tasks\TaskGraph;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\UsesTaskSharedLocks;

uses(RefreshDatabase::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/task-preparation-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config(['commander.projects_path' => $this->projectsPath]);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->group = app(CreateTask::class)->handle('orbit', 'Feature', kind: TaskKind::Group);
    $this->first = app(CreateTask::class)->handle('orbit', 'First', parent: $this->group);
    $this->second = app(CreateTask::class)->handle('orbit', 'Second', parent: $this->group);
    $this->third = app(CreateTask::class)->handle('orbit', 'Third', parent: $this->group);
    $this->order = [$this->first->id, $this->second->id, $this->third->id];
});

afterEach(fn () => File::deleteDirectory($this->projectsPath));

it('appends subtasks as one chain and keeps root tasks independent', function () {
    $root = app(CreateTask::class)->handle('orbit', 'Another feature', kind: TaskKind::Group);
    expect($this->first->dependencies()->count())->toBe(0)
        ->and($this->second->dependencies()->sole()->is($this->first))->toBeTrue()
        ->and($this->third->dependencies()->sole()->is($this->second))->toBeTrue()
        ->and($root->dependencies()->count())->toBe(0)
        ->and(app(TaskCatalog::class)->detail('orbit', $this->group->id)['ordered_ids'])->toBe($this->order);
});

it('reorders links atomically and makes identical reorder retries harmless', function () {
    $order = array_reverse($this->order);
    $action = app(ReorderTaskChildren::class);
    $action->handle($this->group, $order, $this->order);
    $action->handle($this->group, $order, $this->order);

    expect(app(TaskCatalog::class)->detail('orbit', $this->group->id)['ordered_ids'])->toBe($order)
        ->and($this->third->dependencies()->count())->toBe(0)
        ->and($this->second->dependencies()->sole()->is($this->third))->toBeTrue()
        ->and($this->first->dependencies()->sole()->is($this->second))->toBeTrue();
    $fourth = app(CreateTask::class)->handle('orbit', 'Fourth', parent: $this->group);
    expect($fourth->dependencies()->sole()->is($this->first))->toBeTrue();
});

it('rejects incomplete duplicate foreign and stale order submissions', function () {
    $foreign = app(CreateTask::class)->handle('orbit', 'Other');
    foreach ([[$this->first->id], [$this->first->id, $this->first->id, $this->third->id], [$this->first->id, $this->second->id, $foreign->id]] as $invalid) {
        expect(fn () => app(ReorderTaskChildren::class)->handle($this->group, $invalid, $this->order))->toThrow(InvalidArgumentException::class);
    }
    $reversed = array_reverse($this->order);
    app(ReorderTaskChildren::class)->handle($this->group, $reversed, $this->order);
    expect(fn () => app(ReorderTaskChildren::class)->handle($this->group, $this->order, $this->order))->toThrow(LogicException::class, 'Task order changed')
        ->and(app(TaskCatalog::class)->detail('orbit', $this->group->id)['ordered_ids'])->toBe($reversed);
});

it('rolls back every link if a reorder fails part way through', function () {
    $inserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$inserts) {
        if (str_starts_with($query->sql, 'insert into "task_dependencies"') && ++$inserts === 2) {
            throw new LogicException('Simulated write failure');
        }
    });

    expect(fn () => app(ReorderTaskChildren::class)->handle($this->group, array_reverse($this->order), $this->order))
        ->toThrow(LogicException::class, 'Simulated write failure')
        ->and(app(TaskCatalog::class)->detail('orbit', $this->group->id)['ordered_ids'])->toBe($this->order);
});

it('rejects invalid chains on both read and execution without creating a run', function (string $shape) {
    match ($shape) {
        'two heads' => $this->second->dependencies()->detach(),
        'branch' => $this->third->dependencies()->sync([$this->first->id]),
        'cycle' => $this->first->dependencies()->sync([$this->third->id]),
        'two predecessors' => $this->third->dependencies()->attach($this->first->id),
        'foreign' => $this->first->dependencies()->attach($this->group->id),
        'disconnected cycle' => $this->second->dependencies()->sync([$this->third->id]),
    };

    expect(fn () => app(TaskGraph::class)->orderedChildren($this->group))->toThrow(LogicException::class)
        ->and(fn () => app(StartTaskRun::class)->handle($this->first, 'start', 'worker', 'reviewer'))->toThrow(LogicException::class)
        ->and(TaskRun::query()->count())->toBe(0);
})->with(['two heads', 'branch', 'cycle', 'two predecessors', 'foreign', 'disconnected cycle']);

it('makes creation retries safe and rejects a reused key with different content', function () {
    $create = app(CreateTask::class);
    $task = $create->handle('orbit', 'Retry safe', parent: $this->group, acceptanceCriteria: 'Tests pass', creationKey: 'client-1');
    $retry = $create->handle('orbit', 'Retry safe', parent: $this->group, acceptanceCriteria: 'Tests pass', creationKey: 'client-1');

    expect($retry->is($task))->toBeTrue()
        ->and($task->acceptance_criteria)->toBe('Tests pass')
        ->and(fn () => $create->handle('orbit', 'Changed', creationKey: 'client-1'))->toThrow(LogicException::class)
        ->and($this->group->children()->count())->toBe(4);
});

it('uses content versions to reject stale edits without losing the new brief', function () {
    $version = $this->first->contentVersion();
    $updated = app(UpdateTask::class)->handle($this->first, $version, 'Revised objective', 'Context', 'Pass the focused tests');
    expect($updated->contentVersion())->not->toBe($version)
        ->and(fn () => app(UpdateTask::class)->handle($this->first, $version, 'Stale', '', ''))->toThrow(LogicException::class, 'Task changed')
        ->and($this->first->fresh()->title)->toBe('Revised objective');
});

it('freezes the entire feature brief and order after a nested task starts even if it fails', function () {
    $nested = app(CreateTask::class)->handle('orbit', 'Later group', kind: TaskKind::Group, parent: $this->group);
    $leaf = app(CreateTask::class)->handle('orbit', 'Later child', parent: $nested);
    $run = app(StartTaskRun::class)->handle($this->first, 'start', 'worker', 'reviewer');
    app(FailTaskRun::class)->handle($run, 0, TaskStatus::Running, 'Blocked');

    foreach ([$this->group, $nested, $leaf, $this->second] as $task) {
        expect(fn () => app(UpdateTask::class)->handle($task, $task->contentVersion(), 'Changed', '', ''))->toThrow(LogicException::class);
    }
    expect(fn () => app(CreateTask::class)->handle('orbit', 'Late', parent: $nested))->toThrow(LogicException::class)
        ->and(fn () => app(ReorderTaskChildren::class)->handle($nested, [$leaf->id], [$leaf->id]))->toThrow(LogicException::class)
        ->and(app(TaskCatalog::class)->detail('orbit', $nested->id)['editable'])->toBeFalse()
        ->and(fn () => app(StartTaskRun::class)->handle($this->second, 'start', 'worker-2', 'reviewer'))->toThrow(LogicException::class);

    $other = app(CreateTask::class)->handle('orbit', 'Independent');
    expect(app(UpdateTask::class)->handle($other, $other->contentVersion(), 'Still editable', '', '')->title)->toBe('Still editable');
});

it('does not reorder a group using a snapshot taken before a new child was appended', function () {
    app(CreateTask::class)->handle('orbit', 'Fourth', parent: $this->group);
    expect(fn () => app(ReorderTaskChildren::class)->handle($this->group, array_reverse($this->order), $this->order))->toThrow(InvalidArgumentException::class)
        ->and($this->group->children()->count())->toBe(4);
});
