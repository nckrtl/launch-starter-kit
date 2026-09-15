<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Tasks\Actions\ReorderTaskChildren as ReorderTaskChildrenAction;
use App\Tasks\TaskCatalog;
use App\Tasks\TaskInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class ReorderTaskChildren extends TaskTool
{
    protected string $description = 'Atomically reorder all direct subtasks of a pending group. ordered_ids is the full desired order; expected_ids is ordered_ids from get-task. No branching, cross-group links, or changes after execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'task_id' => $schema->integer()->description('Parent group ID.')->required(),
            'ordered_ids' => $schema->array()->items($schema->integer())->required(),
            'expected_ids' => $schema->array()->items($schema->integer())->required(),
        ];
    }

    public function handle(Request $request, TaskCatalog $catalog, ReorderTaskChildrenAction $reorder): Response|ResponseFactory
    {
        $data = $request->validate([...TaskInput::reorder(), 'project_id' => ['required', 'string', 'max:80'], 'task_id' => ['required', 'integer', 'min:1']]);
        /** @var list<int|string> $ordered */
        $ordered = $data['ordered_ids'];
        /** @var list<int|string> $expected */
        $expected = $data['expected_ids'];

        return $this->respond(function () use ($request, $catalog, $reorder, $ordered, $expected): array {
            $projectId = $request->string('project_id')->toString();
            $task = $catalog->find($projectId, $request->integer('task_id'));
            $reorder->handle($task, array_map(intval(...), $ordered), array_map(intval(...), $expected));

            return ['task' => $catalog->detail($projectId, $task->id)];
        });
    }
}
