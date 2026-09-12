<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitResolutionReceiptValidator
{
    public function __construct(
        private OrbitPlanResolutionReceiptValidator $plans,
        private OrbitPullRequestResolutionReceiptValidator $pullRequests,
    ) {}

    public function matchesInput(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
    ): bool {
        return $this->exactlyOne(
            $this->plans->matchesInput($delivery, $phase, $dispatch),
            $this->pullRequests->matchesInput($delivery, $phase, $dispatch),
        );
    }

    /** @param array<string, mixed> $payload */
    public function matchesPayload(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $payload,
    ): bool {
        return $this->exactlyOne(
            $this->plans->matchesPayload($delivery, $phase, $dispatch, $payload),
            $this->pullRequests->matchesPayload($delivery, $phase, $dispatch, $payload),
        );
    }

    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        return $this->exactlyOne(
            $this->plans->matches($delivery, $phase, $dispatch, $receipt),
            $this->pullRequests->matches($delivery, $phase, $dispatch, $receipt),
        );
    }

    private function exactlyOne(bool $left, bool $right): bool
    {
        return $left !== $right;
    }
}
