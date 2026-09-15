<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Tasks\Enums\TaskKind;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class ReorderTaskChildren
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph) {}

    /**
     * @param  list<int>  $orderedIds
     * @param  list<int>  $expectedIds
     */
    public function handle(Task $parent, array $orderedIds, array $expectedIds): void
    {
        $this->mutation->handle($parent->project_id, function () use ($parent, $orderedIds, $expectedIds): void {
            $parent = Task::query()->findOrFail($parent->id);

            if ($parent->kind !== TaskKind::Group) {
                throw new InvalidArgumentException('Only group subtasks can be reordered.');
            }

            $this->graph->assertUnattempted($this->graph->root($parent));
            $children = $this->graph->orderedChildren($parent);
            $currentIds = array_map(fn (Task $task): int => $task->id, $children);

            if (count($orderedIds) !== count($currentIds) || count(array_unique($orderedIds)) !== count($orderedIds)
                || array_diff($currentIds, $orderedIds) !== [] || array_diff($orderedIds, $currentIds) !== []) {
                throw new InvalidArgumentException('Include every current subtask exactly once, and no other tasks.');
            }

            if ($orderedIds === $currentIds) {
                return;
            }

            if ($expectedIds !== $currentIds) {
                throw new LogicException('Task order changed. Reload the group before reordering again.');
            }

            $byId = collect($children)->keyBy('id');
            $previous = null;

            foreach ($orderedIds as $id) {
                $child = $byId->get($id);
                assert($child instanceof Task);
                $child->dependencies()->sync($previous === null ? [] : [$previous]);
                $previous = $id;
            }
        });
    }
}
