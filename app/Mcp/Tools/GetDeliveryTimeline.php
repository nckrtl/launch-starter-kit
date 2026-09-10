<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Delivery\Queries\DeliveryTimeline;
use App\Models\Delivery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetDeliveryTimeline extends Tool
{
    protected string $description = 'Read the deterministic audit timeline for one delivery.';

    public function schema(JsonSchema $schema): array
    {
        return ['delivery_id' => $schema->integer()->min(1)->description('Delivery ID')->required()];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'delivery_id' => $schema->integer()->required(),
            'timeline' => $schema->array()->items($schema->object([
                'occurred_at' => $schema->string()->required(),
                'type' => $schema->string()->required(),
                'source' => $schema->string()->required(),
                'source_id' => $schema->integer()->required(),
                'details' => $schema->object()->required(),
            ]))->required(),
        ];
    }

    public function handle(Request $request, DeliveryTimeline $timeline): ResponseFactory|Response
    {
        $request->validate(['delivery_id' => ['required', 'integer', 'min:1']]);
        $deliveryId = $request->integer('delivery_id');
        $delivery = Delivery::query()->find($deliveryId);

        if ($delivery === null) {
            return Response::error("Delivery [{$deliveryId}] was not found.");
        }

        return Response::structured([
            'delivery_id' => $delivery->id,
            'timeline' => $timeline->for($delivery),
        ]);
    }
}
