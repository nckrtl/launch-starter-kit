<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

final class GetProject extends Tool
{
    protected string $description = 'Read one project manifest, including orchestration and routing metadata.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('Lowercase project slug')->required()];
    }

    public function handle(Request $request, SharedKnowledgeProjectRepository $projects): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        return Response::structured(['project' => $projects->find($request->string('id')->toString())]);
    }
}
