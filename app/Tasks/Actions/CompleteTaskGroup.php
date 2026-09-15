<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use LogicException;

final readonly class CompleteTaskGroup
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph) {}

    public function handle(Task $task): Task
    {
        return $this->mutation->handle($task->project_id, function () use ($task): Task {
            $task = Task::query()->findOrFail($task->id);

            if ($task->kind !== TaskKind::Group || ! $task->children()->exists()) {
                throw new LogicException('Only a group with subtasks can be completed.');
            }

            if ($task->status === TaskStatus::Completed) {
                return $task;
            }

            if ($task->status !== TaskStatus::Pending) {
                throw new LogicException('Only a pending group can be completed.');
            }

            $this->graph->assertReady($task);
            $task->status = TaskStatus::Completed;
            $task->completed_at = now()->toImmutable();
            $task->save();

            return $task;
        });
    }
}
