<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class UpdateTask
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph) {}

    public function handle(Task $task, string $expectedVersion, string $title, string $description, string $acceptanceCriteria): Task
    {
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Task title must contain between 1 and 255 characters.');
        }

        return $this->mutation->handle($task->project_id, function () use ($task, $expectedVersion, $title, $description, $acceptanceCriteria): Task {
            $task = Task::query()->findOrFail($task->id);
            $this->graph->assertUnattempted($this->graph->root($task));

            if (! hash_equals($task->contentVersion(), $expectedVersion)) {
                throw new LogicException('Task changed. Reload it before editing again.');
            }

            $task->fill(['title' => $title, 'description' => $description, 'acceptance_criteria' => $acceptanceCriteria])->save();

            return $task;
        });
    }
}
