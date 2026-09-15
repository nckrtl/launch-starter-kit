<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Tasks\TaskCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListTasks extends TaskTool
{
    protected string $description = 'List top-level tasks in one project, newest first. This list is not an execution order. Use get-task to read a group and its sequential subtasks.';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->required()];
    }

    public function handle(Request $request, TaskCatalog $catalog): Response|ResponseFactory
    {
        $request->validate(['project_id' => ['required', 'string', 'max:80']]);

        return $this->respond(fn (): array => ['tasks' => $catalog->listing($request->string('project_id')->toString())]);
    }
}
