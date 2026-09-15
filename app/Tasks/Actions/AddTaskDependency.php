<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class AddTaskDependency
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph) {}

    public function handle(Task $task, Task $dependency): void
    {
        $this->mutation->handle($task->project_id, function () use ($task, $dependency): void {
            $task = Task::query()->findOrFail($task->id);
            $dependency = Task::query()->findOrFail($dependency->id);

            if ($task->project_id !== $dependency->project_id || $task->parent_id !== $dependency->parent_id || $task->is($dependency)) {
                throw new InvalidArgumentException('Dependencies must reference another task with the same parent and project.');
            }

            if ($task->dependencies()->whereKey($dependency->id)->exists()) {
                return;
            }

            if ($task->parent_id !== null) {
                throw new LogicException('Use ReorderTaskChildren to change a subtask chain.');
            }

            $this->graph->assertUnattempted($task);
            $task->dependencies()->attach($dependency->id);
            $this->graph->assertAcyclic($task);
        });
    }
}
