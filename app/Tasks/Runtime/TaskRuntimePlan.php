<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\TaskGraph;
use LogicException;

final readonly class TaskRuntimePlan
{
    public function __construct(private TaskGraph $graph) {}

    /** @return array{root: array<string, mixed>, children: list<array<string, mixed>>} */
    public function manifest(Task $root): array
    {
        if ($root->parent_id !== null || $root->kind !== TaskKind::Group) {
            throw new LogicException('The first runner requires a root group with direct executable children.');
        }

        $children = $this->graph->orderedChildren($root);
        if ($children === []) {
            throw new LogicException('The feature must contain at least one executable task.');
        }

        foreach ([$root, ...$children] as $task) {
            if (trim($task->description) === '' || trim($task->acceptance_criteria) === ''
                || ($task->id !== $root->id && ($task->kind !== TaskKind::Executable || $task->children()->exists()))) {
                throw new LogicException('Every assignment requires a complete brief; nested groups are not runnable in v1.');
            }
        }

        return ['root' => $this->brief($root), 'children' => array_map($this->brief(...), $children)];
    }

    public function hash(Task $root): string
    {
        return hash('sha256', json_encode($this->manifest($root), JSON_THROW_ON_ERROR));
    }

    public function effectiveHash(TaskWorkspace $workspace): string
    {
        return $this->continuation($workspace)->manifest_hash
            ?? $this->amendment($workspace)->manifest_hash
            ?? $workspace->manifest_hash;
    }

    public function finalRound(TaskWorkspace $workspace): int
    {
        return $this->continuation($workspace)->final_round ?? 0;
    }

    private function continuation(TaskWorkspace $workspace): ?TaskFinalContinuation
    {
        if (! $workspace->getConnection()->getSchemaBuilder()->hasTable('task_final_continuations')) {
            return null;
        }

        return TaskFinalContinuation::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->orderByDesc('final_round')->first();
    }

    private function amendment(TaskWorkspace $workspace): ?TaskManifestAmendment
    {
        if (! $workspace->getConnection()->getSchemaBuilder()->hasTable('task_manifest_amendments')) {
            return null;
        }

        return TaskManifestAmendment::on($workspace->getConnectionName())
            ->where('task_workspace_id', $workspace->id)->orderByDesc('sequence')->first();
    }

    /** @return array<string, mixed> */
    private function brief(Task $task): array
    {
        return ['id' => $task->id, 'project_id' => $task->project_id, 'parent_id' => $task->parent_id,
            'kind' => $task->kind->value, 'title' => $task->title, 'description' => $task->description,
            'acceptance_criteria' => $task->acceptance_criteria,
            'dependencies' => $task->dependencies()->orderBy('tasks.id')->pluck('tasks.id')->all()];
    }
}
