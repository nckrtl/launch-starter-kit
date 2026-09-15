<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Tasks\Actions\UpdateTask as UpdateTaskAction;
use App\Tasks\TaskCatalog;
use App\Tasks\TaskInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class UpdateTask extends TaskTool
{
    protected string $description = 'Replace a pending task brief. Supply all brief fields and expected_version from get-task. Missing description or acceptance criteria clears it. Stale edits and edits after any feature execution are rejected.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'task_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'description' => $schema->string(),
            'acceptance_criteria' => $schema->string(),
            'expected_version' => $schema->string()->required(),
        ];
    }

    public function handle(Request $request, TaskCatalog $catalog, UpdateTaskAction $update): Response|ResponseFactory
    {
        $request->validate([...TaskInput::update(), 'project_id' => ['required', 'string', 'max:80'], 'task_id' => ['required', 'integer', 'min:1']]);

        return $this->respond(function () use ($request, $catalog, $update): array {
            $projectId = $request->string('project_id')->toString();
            $task = $catalog->find($projectId, $request->integer('task_id'));
            $update->handle($task, $request->string('expected_version')->toString(), $request->string('title')->toString(),
                $request->string('description')->toString(), $request->string('acceptance_criteria')->toString());

            return ['task' => $catalog->detail($projectId, $task->id)];
        });
    }
}
