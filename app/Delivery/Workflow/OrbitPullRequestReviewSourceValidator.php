<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitPullRequestReviewSourceValidator
{
    public function __construct(private OrbitImplementationReceiptValidator $receipts) {}

    public function sourceReceipt(Delivery $delivery, PhaseRun $review): ?Receipt
    {
        $input = $review->input;
        $receiptId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || ! is_array($pullRequest) || array_is_list($pullRequest)
            || array_diff(array_keys($input), [
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
            ]) !== []
            || count($input) !== 3) {
            return null;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $implementation = $receipt?->phaseRun;
        $dispatch = $implementation?->agentDispatches->first();
        $latest = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
            ->first();
        $expectedPrompt = $implementation?->attempt === 2
            ? 'orbit_implementation_correction'
            : 'orbit_implementation';

        if ($receipt === null || $implementation === null || $dispatch === null
            || $review->delivery_id !== $delivery->id
            || $review->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $review->attempt !== 1
            || $implementation->delivery_id !== $delivery->id
            || $implementation->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || ! in_array($implementation->attempt, [1, 2], true)
            || $latest?->id !== $implementation->id
            || $implementation->status !== PhaseRunStatus::Completed
            || $implementation->finished_at === null
            || $implementation->output !== [
                'receipt_id' => $receipt->id,
                'result' => 'ready',
                'pull_request_number' => $delivery->pull_request_number,
                'pull_request_url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]
            || $implementation->agentDispatches->count() !== 1
            || $implementation->receipts()->where('kind', 'orbit_implementation')->count() !== 1
            || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->idempotency_key !== IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                $implementation->attempt,
                OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            )->value
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
            || $dispatch->prompt_name !== $expectedPrompt
            || $dispatch->prompt_version !== 1
            || preg_match('/^[a-f0-9]{64}$/', $dispatch->prompt_hash) !== 1
            || $dispatch->prompt_hash === str_repeat('0', 64)
            || $dispatch->dispatched_at === null || $dispatch->settled_at === null
            || $receipt->payload !== $payload
            || ($payload['result'] ?? null) !== 'ready'
            || ($payload['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || $pullRequest !== [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]
            || ! $this->matchesReceipt($delivery, $implementation, $dispatch, $receipt)) {
            return null;
        }

        return $receipt;
    }

    private function matchesReceipt(
        Delivery $delivery,
        PhaseRun $implementation,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        if ($implementation->attempt !== 2) {
            return $this->receipts->matches($delivery, $implementation, $dispatch, $receipt);
        }

        $input = $implementation->input;
        $source = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $sourceCandidate = is_array($source) ? ($source['candidate_sha'] ?? null) : null;

        if (! is_string($sourceCandidate)) {
            return false;
        }

        $sourceDelivery = clone $delivery;
        $sourceDelivery->candidate_sha = $sourceCandidate;

        return $this->receipts->matches($sourceDelivery, $implementation, $dispatch, $receipt);
    }
}
