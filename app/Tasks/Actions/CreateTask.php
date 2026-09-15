<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class CreateTask
{
    public function __construct(private SharedKnowledgeProjectRepository $projects, private TaskMutation $mutation, private TaskGraph $graph) {}

    public function handle(string $projectId, string $title, string $description = '', TaskKind $kind = TaskKind::Executable, ?Task $parent = null, string $acceptanceCriteria = '', ?string $creationKey = null): Task
    {
        $this->projects->find($projectId);
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Task title must contain between 1 and 255 characters.');
        }

        if ($creationKey !== null && (trim($creationKey) === '' || mb_strlen($creationKey) > 100)) {
            throw new InvalidArgumentException('A creation key must contain between 1 and 100 characters.');
        }

        return $this->mutation->handle($projectId, function () use ($projectId, $title, $description, $kind, $parent, $acceptanceCriteria, $creationKey): Task {
            $attributes = [
                'project_id' => $projectId,
                'parent_id' => $parent?->id,
                'kind' => $kind,
                'title' => $title,
                'description' => $description,
                'acceptance_criteria' => $acceptanceCriteria,
                'creation_key' => $creationKey,
            ];

            if ($creationKey !== null) {
                $existing = Task::query()->forProject($projectId)->where('creation_key', $creationKey)->first();

                if ($existing !== null) {
                    foreach ($attributes as $field => $value) {
                        if ($existing->getAttribute($field) !== $value) {
                            throw new LogicException('A creation key cannot be reused with different task content.');
                        }
                    }

                    return $existing;
                }
            }

            $tail = null;

            if ($parent !== null) {
                $parent = Task::query()->findOrFail($parent->id);

                if ($parent->project_id !== $projectId) {
                    throw new InvalidArgumentException('Parent and child must belong to the same project.');
                }

                if ($parent->kind !== TaskKind::Group || $parent->status !== TaskStatus::Pending) {
                    throw new LogicException('Subtasks require a pending group parent.');
                }

                $this->graph->assertUnattempted($this->graph->root($parent));
                $children = $this->graph->orderedChildren($parent);
                $tail = $children === [] ? null : $children[array_key_last($children)];
            }

            $task = Task::query()->create($attributes);

            if ($tail !== null) {
                $task->dependencies()->attach($tail->id);
            }

            return $task;
        });
    }
}
