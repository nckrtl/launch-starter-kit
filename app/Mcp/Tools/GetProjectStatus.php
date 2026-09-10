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
final class GetProjectStatus extends Tool
{
    protected string $description = 'Read manifest and delivery-orchestration status for one project.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('Lowercase project slug')->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->object([
                'id' => $schema->string()->required(),
                'name' => $schema->string()->required(),
                'manifest_status' => $schema->string()->required(),
            ])->required(),
            'orchestration' => $schema->object([
                'configured' => $schema->boolean()->required(),
                'state' => $schema->string()->nullable()->required(),
                'config_type' => $schema->string()->nullable()->required(),
            ])->required(),
        ];
    }

    public function handle(Request $request, ProjectOrchestrationQuery $query): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        return Response::structured($query->status($request->string('id')->toString()));
    }
}
