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
final class GetTask extends TaskTool
{
    protected string $description = 'Read a task brief, acceptance criteria, editable flag, content_version, and direct subtasks in dependency-chain order. Read nested groups separately.';

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->string()->required(), 'task_id' => $schema->integer()->required()];
    }

    public function handle(Request $request, TaskCatalog $catalog): Response|ResponseFactory
    {
        $request->validate(['project_id' => ['required', 'string', 'max:80'], 'task_id' => ['required', 'integer', 'min:1']]);

        return $this->respond(fn (): array => ['task' => $catalog->detail($request->string('project_id')->toString(), $request->integer('task_id'))]);
    }
}
