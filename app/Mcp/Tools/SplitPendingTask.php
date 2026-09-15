<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\TaskWorkspace;
use App\Tasks\Runtime\SplitPendingTask as SplitPendingTaskAction;
use App\Tasks\TaskCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

final class SplitPendingTask extends TaskTool
{
    protected string $description = 'Preview or apply an audited split of the untouched pending tail after the current active task. Preview first, then apply the exact proposal_hash. The existing tail ID becomes the first replacement; normal task edits remain frozen.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'workspace_id' => $schema->integer()->required(),
            'target_task_id' => $schema->integer()->required(),
            'active_run_id' => $schema->integer()->required(),
            'active_dispatch_id' => $schema->integer()->required(),
            'expected_manifest_hash' => $schema->string()->required(),
            'expected_target_version' => $schema->string()->required(),
            'amendment_key' => $schema->string()->required(),
            'reason' => $schema->string()->required(),
            'evidence' => $schema->string()->required(),
            'replacements' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'acceptance_criteria' => $schema->string()->required(),
            ]))->required(),
            'exclusive' => $schema->boolean()->required(),
            'proposal_hash' => $schema->string()->nullable(),
            'apply' => $schema->boolean()->required(),
        ];
    }

    public function handle(Request $request, TaskCatalog $catalog, SplitPendingTaskAction $split): Response|ResponseFactory
    {
        $data = $request->validate([
            'project_id' => ['required', 'string', 'max:80'], 'workspace_id' => ['required', 'integer', 'min:1'],
            'target_task_id' => ['required', 'integer', 'min:1'], 'active_run_id' => ['required', 'integer', 'min:1'],
            'active_dispatch_id' => ['required', 'integer', 'min:1'], 'expected_manifest_hash' => ['required', 'string', 'size:64'],
            'expected_target_version' => ['required', 'string', 'size:64'], 'amendment_key' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:10000'], 'evidence' => ['required', 'string', 'max:50000'],
            'replacements' => ['required', 'array', 'list', 'min:2', 'max:8'],
            'replacements.*' => ['required', 'array:title,description,acceptance_criteria'],
            'replacements.*.title' => ['required', 'string', 'max:255'],
            'replacements.*.description' => ['required', 'string', 'max:50000'],
            'replacements.*.acceptance_criteria' => ['required', 'string', 'max:50000'],
            'exclusive' => ['required', 'boolean'], 'proposal_hash' => ['nullable', 'string', 'size:64'], 'apply' => ['required', 'boolean'],
        ]);

        return $this->respond(function () use ($request, $catalog, $split, $data): array {
            $projectId = $request->string('project_id')->toString();
            $target = $catalog->find($projectId, $request->integer('target_task_id'));
            $workspace = TaskWorkspace::query()->where('project_id', $projectId)->findOrFail($request->integer('workspace_id'));
            /** @var list<array<string, mixed>> $replacements */
            $replacements = $data['replacements'];

            return $split->handle($workspace->id, $target->id, $request->integer('active_run_id'),
                $request->integer('active_dispatch_id'), $request->string('expected_manifest_hash')->toString(),
                $request->string('expected_target_version')->toString(), $request->string('amendment_key')->toString(),
                ['reason' => $data['reason'], 'evidence' => $data['evidence'], 'replacements' => $replacements],
                (bool) $data['exclusive'], is_string($data['proposal_hash'] ?? null) ? $data['proposal_hash'] : null,
                (bool) $data['apply']);
        });
    }
}
