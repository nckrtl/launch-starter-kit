<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Delivery\Queries\ProjectOrchestrationQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetProjectConfig extends Tool
{
    protected string $description = 'Read the validated delivery-orchestration config for one project.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('Lowercase project slug')->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'configured' => $schema->boolean()->required(),
            'state' => $schema->string()->nullable()->required(),
            'config' => $schema->object()->nullable()->required(),
        ];
    }

    public function handle(Request $request, ProjectOrchestrationQuery $query): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        return Response::structured($query->config($request->string('id')->toString()));
    }
}
