<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Delivery\Queries\NextEligibleIssue;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetNextEligibleIssue extends Tool
{
    protected string $description = 'Read the next eligible issue in project order without claiming or starting it.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->string()->description('Lowercase project slug')->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required(),
            'capacity' => $schema->object([
                'active' => $schema->integer()->required(),
                'limit' => $schema->integer()->required(),
                'available' => $schema->boolean()->required(),
            ])->required(),
            'issue' => $schema->object([
                'id' => $schema->string()->required(),
                'key' => $schema->string()->required(),
                'title' => $schema->string()->required(),
                'url' => $schema->string()->required(),
                'labels' => $schema->array()->items($schema->string())->required(),
                'controller_owned' => $schema->boolean()->required(),
                'contract_sha256' => $schema->string()->required(),
            ])->nullable()->required(),
        ];
    }

    public function handle(Request $request, NextEligibleIssue $query): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        return Response::structured($query->get($request->string('id')->toString()));
    }
}
