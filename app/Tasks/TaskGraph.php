<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Models\Task;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use LogicException;

final readonly class TaskGraph
{
    public function root(Task $task): Task
    {
        $visited = [];

        while ($task->parent_id !== null) {
            if (isset($visited[$task->id])) {
                throw new LogicException('Task hierarchy contains a cycle.');
            }

            $visited[$task->id] = true;
            $task = $task->parent()->firstOrFail();
        }

        return $task;
    }

    public function assertAcyclic(Task $task): void
    {
        $visiting = [];
        $visited = [];
        $this->visit($task, $visiting, $visited);
    }

    public function assertReady(Task $task): void
    {
        $this->assertSequential($this->root($task));

        foreach ($this->prerequisites($task) as $prerequisite) {
            if ($prerequisite->status !== TaskStatus::Completed) {
                throw new LogicException('Task prerequisites must be completed first.');
            }
        }
    }

    public function assertUnattempted(Task $task): void
    {
        if ($task->status !== TaskStatus::Pending || $task->runs()->exists()) {
            throw new LogicException('The task brief and order are frozen after execution starts.');
        }

        foreach ($task->children()->get() as $child) {
            $this->assertUnattempted($child);
        }
    }

    /** @return list<Task> */
    public function orderedChildren(Task $group): array
    {
        $children = $group->children()->with('dependencies')->withCount('children')->get()->keyBy('id');

        if ($children->isEmpty()) {
            return [];
        }

        if ($group->kind !== TaskKind::Group) {
            throw new LogicException('Only groups can have subtasks.');
        }

        $head = null;
        $successors = [];

        foreach ($children as $child) {
            if ($child->project_id !== $group->project_id || $child->dependencies->count() > 1) {
                throw new LogicException('Subtasks must form one sequential dependency chain.');
            }

            $previous = $child->dependencies->first();

            if ($previous === null) {
                if ($head !== null) {
                    throw new LogicException('Subtasks must have exactly one first task.');
                }

                $head = $child;
            } else {
                if (! $children->has($previous->id) || isset($successors[$previous->id])) {
                    throw new LogicException('Dependencies must link consecutive siblings without branching.');
                }

                $successors[$previous->id] = $child;
            }
        }

        $ordered = [];
        $visited = [];
        $current = $head;

        while ($current !== null && ! isset($visited[$current->id])) {
            $ordered[] = $current;
            $visited[$current->id] = true;
            $current = $successors[$current->id] ?? null;
        }

        if (count($ordered) !== $children->count() || $current !== null) {
            throw new LogicException('Subtasks contain a cycle or a disconnected chain.');
        }

        return $ordered;
    }

    public function assertSequential(Task $task): void
    {
        foreach ($this->orderedChildren($task) as $child) {
            $this->assertSequential($child);
        }
    }

    /** @return list<Task> */
    private function prerequisites(Task $task): array
    {
        $prerequisites = $task->children()->get()->all();
        $ancestor = $task;
        $ancestors = [];

        do {
            if (isset($ancestors[$ancestor->id])) {
                throw new LogicException('Task hierarchy contains a cycle.');
            }

            $ancestors[$ancestor->id] = true;
            array_push($prerequisites, ...$ancestor->dependencies()->get()->all());
            $ancestor = $ancestor->parent()->first();
        } while ($ancestor !== null);

        return array_values($prerequisites);
    }

    /**
     * @param  array<int, true>  $visiting
     * @param  array<int, true>  $visited
     */
    private function visit(Task $task, array &$visiting, array &$visited): void
    {
        if (isset($visiting[$task->id])) {
            throw new LogicException('Task dependencies would create a cycle.');
        }

        if (isset($visited[$task->id])) {
            return;
        }

        $visiting[$task->id] = true;

        foreach ($task->dependencies()->get() as $prerequisite) {
            $this->visit($prerequisite, $visiting, $visited);
        }

        unset($visiting[$task->id]);
        $visited[$task->id] = true;
    }
}
