<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class UpdateProject extends Tool
{
    protected string $description = 'Update the display name or lifecycle status of an existing project.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Lowercase project slug')->required(),
            'name' => $schema->string(),
            'status' => $schema->string()->enum(['active', 'paused', 'archived']),
        ];
    }

    public function handle(Request $request, SharedKnowledgeProjectRepository $projects): Response|ResponseFactory
    {
        $request->validate([
            'id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'in:active,paused,archived'],
        ]);

        /** @var array{name?: string, status?: string} $attributes */
        $attributes = [];

        if ($request->has('name')) {
            $attributes['name'] = $request->string('name')->toString();
        }

        if ($request->has('status')) {
            $attributes['status'] = $request->string('status')->toString();
        }

        if ($attributes === []) {
            return Response::error('Provide name or status to update.');
        }

        return Response::structured(['project' => $projects->update($request->string('id')->toString(), $attributes)]);
    }
}
