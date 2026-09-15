<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Models\Task;
use App\Models\TaskRun;
use App\Projects\SharedKnowledgeProjectRepository;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use UnexpectedValueException;

final readonly class TaskCatalog
{
    public function __construct(private SharedKnowledgeProjectRepository $projects, private TaskGraph $graph, private TaskMutation $mutation) {}

    public function find(string $projectId, int|string $id): Task
    {
        $this->projects->find($projectId);

        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$id]);
        }

        return Task::query()->forProject($projectId)->findOrFail($id);
    }

    /** @return array<int, array<string, mixed>> */
    public function listing(string $projectId): array
    {
        $this->projects->find($projectId);

        return Task::query()->forProject($projectId)->whereNull('parent_id')->with('dependencies')->withCount('children')
            ->orderByDesc('id')->get()->map(fn (Task $task): array => $this->brief($task))->all();
    }

    /** @return array<string, mixed> */
    public function detail(string $projectId, int|string $id): array
    {
        return $this->mutation->handle($projectId, function () use ($projectId, $id): array {
            $task = $this->find($projectId, $id);
            $root = $this->graph->root($task);
            $editable = $root->status === TaskStatus::Pending && ! TaskRun::query()->where('root_task_id', $root->id)->exists();
            $children = $this->graph->orderedChildren($task);
            $parent = $task->parent()->first();
            $taskIds = [$task->id, ...array_map(fn (Task $child): int => $child->id, $children)];
            $startedAtByTaskId = TaskRun::query()->whereIn('task_id', $taskIds)
                ->selectRaw('task_id, MIN(started_at) AS first_started_at')->groupBy('task_id')
                ->pluck('first_started_at', 'task_id')->map(
                    function (mixed $startedAt): CarbonImmutable {
                        if (! is_string($startedAt)) {
                            throw new UnexpectedValueException('Task run start time has an invalid database value.');
                        }

                        return CarbonImmutable::parse($startedAt);
                    },
                );

            return [
                ...$this->brief($task, $startedAtByTaskId->get($task->id)),
                'editable' => $editable,
                'root' => ['id' => $root->id, 'title' => $root->title],
                'parent' => $parent === null ? null : ['id' => $parent->id, 'title' => $parent->title],
                'children' => array_map(fn (Task $child): array => $this->brief($child, $startedAtByTaskId->get($child->id)), $children),
                'ordered_ids' => array_map(fn (Task $child): int => $child->id, $children),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function brief(Task $task, ?CarbonImmutable $startedAt = null): array
    {
        $task->loadMissing('dependencies');
        if (! array_key_exists('children_count', $task->getAttributes())) {
            $task->loadCount('children');
        }

        $timing = null;
        if ($startedAt !== null) {
            $finishedAt = $task->completed_at;
            $timing = [
                'started_at' => $startedAt->toIso8601String(),
                'finished_at' => $finishedAt?->toIso8601String(),
                'elapsed_seconds' => max(0, (int) $startedAt->diffInSeconds($finishedAt ?? now())),
            ];
        }

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'parent_id' => $task->parent_id,
            'kind' => $task->kind->value,
            'title' => $task->title,
            'description' => $task->description,
            'acceptance_criteria' => $task->acceptance_criteria,
            'status' => $task->status->value,
            'content_version' => $task->contentVersion(),
            'dependency_ids' => $task->dependencies->pluck('id')->all(),
            'children_count' => $task->getAttribute('children_count'),
            'timing' => $timing,
        ];
    }
}
