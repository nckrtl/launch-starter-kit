<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Contracts\DeliveryWorkflow;
use App\Models\Delivery;
use InvalidArgumentException;

final readonly class WorkflowRegistry
{
    /** @param list<DeliveryWorkflow>|null $workflows */
    public function __construct(private ?array $workflows = null) {}

    public function for(Delivery $delivery): DeliveryWorkflow
    {
        $workflows = $this->workflows ?? [new ShadowWorkflow];

        foreach ($workflows as $workflow) {
            if ($workflow instanceof ShadowWorkflow
                && $delivery->workflow_type === ShadowWorkflow::TYPE
                && $delivery->workflow_version === ShadowWorkflow::VERSION) {
                return $workflow;
            }
        }

        throw new InvalidArgumentException("Unknown delivery workflow [{$delivery->workflow_type}:{$delivery->workflow_version}].");
    }
}
