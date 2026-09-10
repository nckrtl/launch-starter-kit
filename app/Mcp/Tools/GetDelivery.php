<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Delivery\Queries\DeliveryDetails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetDelivery extends Tool
{
    protected string $description = 'Read one authoritative delivery, its current phase run, and why it is waiting.';

    public function schema(JsonSchema $schema): array
    {
        return ['delivery_id' => $schema->integer()->min(1)->description('Delivery ID')->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'delivery' => $schema->object([
                'id' => $schema->integer()->required(),
                'project_id' => $schema->string()->required(),
                'issue_provider' => $schema->string()->required(),
                'issue_id' => $schema->string()->required(),
                'issue_key' => $schema->string()->nullable()->required(),
                'workflow_type' => $schema->string()->required(),
                'workflow_version' => $schema->integer()->required(),
                'status' => $schema->string()->required(),
                'current_phase' => $schema->string()->required(),
                'branch' => $schema->string()->nullable()->required(),
                'worktree' => $schema->string()->nullable()->required(),
                'candidate_sha' => $schema->string()->nullable()->required(),
                'pull_request_number' => $schema->integer()->nullable()->required(),
                'pull_request_url' => $schema->string()->nullable()->required(),
                'failure' => $schema->object()->nullable()->required(),
                'completion' => $schema->object()->nullable()->required(),
                'created_at' => $schema->string()->nullable()->required(),
                'updated_at' => $schema->string()->nullable()->required(),
                'completed_at' => $schema->string()->nullable()->required(),
                'failed_at' => $schema->string()->nullable()->required(),
            ])->required(),
            'current_phase_run' => $schema->object([
                'id' => $schema->integer()->required(),
                'phase' => $schema->string()->required(),
                'attempt' => $schema->integer()->required(),
                'status' => $schema->string()->required(),
                'dispatch_status' => $schema->string()->nullable()->required(),
                'agent_name' => $schema->string()->nullable()->required(),
            ])->nullable()->required(),
            'wait' => $schema->object([
                'reason' => $schema->string()->required(),
                'details' => $schema->object()->required(),
            ])->nullable()->required(),
        ];
    }

    public function handle(Request $request, DeliveryDetails $details): ResponseFactory|Response
    {
        $request->validate(['delivery_id' => ['required', 'integer', 'min:1']]);
        $deliveryId = $request->integer('delivery_id');

        try {
            return Response::structured($details->get($deliveryId));
        } catch (ModelNotFoundException) {
            return Response::error("Delivery [{$deliveryId}] was not found.");
        }
    }
}
