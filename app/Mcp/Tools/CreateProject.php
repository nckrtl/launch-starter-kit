<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class CreateProject extends Tool
{
    protected string $description = 'Create a project in the canonical shared-knowledge registry.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Lowercase project slug')->required(),
            'name' => $schema->string()->required(),
            'status' => $schema->string()->enum(['active', 'paused', 'archived'])->default('active'),
        ];
    }

    public function handle(Request $request, SharedKnowledgeProjectRepository $projects): ResponseFactory
    {
        $request->validate([
            'id' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', 'string', 'in:active,paused,archived'],
        ]);

        return Response::structured(['project' => $projects->create($request->string('id')->toString(), [
            'name' => $request->string('name')->toString(),
            'status' => $request->string('status', 'active')->toString(),
        ])]);
    }
}
