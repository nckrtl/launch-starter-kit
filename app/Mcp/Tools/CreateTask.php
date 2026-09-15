<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Tasks\Actions\CreateTask as CreateTaskAction;
use App\Tasks\Enums\TaskKind;
use App\Tasks\TaskCatalog;
use App\Tasks\TaskInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class CreateTask extends TaskTool
{
    protected string $description = 'Create a prepared task in a project. Use kind group for a feature with subtasks. A subtask appends to its pending parent chain. Reuse creation_key only when retrying the same creation. This does not start execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'title' => $schema->string()->required(),
            'description' => $schema->string()->description('Single objective, context, scope, implementation notes.'),
            'acceptance_criteria' => $schema->string()->description('Observable conditions and tests for success.'),
            'kind' => $schema->string()->enum(['group', 'executable'])->required(),
            'parent_id' => $schema->integer(),
            'creation_key' => $schema->string()->description('Unique key within the project; keep it for retries.')->required(),
        ];
    }

    public function handle(Request $request, TaskCatalog $catalog, CreateTaskAction $create): Response|ResponseFactory
    {
        $request->validate([...TaskInput::create(), 'project_id' => ['required', 'string', 'max:80']]);

        return $this->respond(function () use ($request, $catalog, $create): array {
            $projectId = $request->string('project_id')->toString();
            $parent = $request->get('parent_id') === null ? null : $catalog->find($projectId, $request->integer('parent_id'));
            $task = $create->handle($projectId, $request->string('title')->toString(), $request->string('description')->toString(),
                TaskKind::from($request->string('kind')->toString()), $parent, $request->string('acceptance_criteria')->toString(), $request->string('creation_key')->toString());

            return ['task' => $catalog->detail($projectId, $task->id)];
        });
    }
}
