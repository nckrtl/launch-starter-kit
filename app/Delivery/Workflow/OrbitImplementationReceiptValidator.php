<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitImplementationReceiptValidator
{
    public function __construct(private OrbitPlanReviewReceiptValidator $reviewReceipts) {}

    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        return $receipt->phase_run_id === $phase->id
            && $receipt->kind === 'orbit_implementation'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && hash_equals(
                $receipt->payload_hash,
                hash('sha256', json_encode($receipt->payload, JSON_THROW_ON_ERROR)),
            )
            && $receipt->candidate_sha === ($receipt->payload['candidate_sha'] ?? null)
            && $this->matchesInput($delivery, $phase)
            && $this->matchesPayload($delivery, $phase, $dispatch, $receipt->payload);
    }

    public function matchesInput(Delivery $delivery, PhaseRun $implementation): bool
    {
        $input = $implementation->input;
        $receiptId = is_array($input) ? ($input['plan_review_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['plan_review_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['plan_review_receipt_id', 'plan_review_receipt']) !== []
            || count($input) !== 2) {
            return false;
        }

        $reviewReceipt = Receipt::query()
            ->with(['phaseRun.agentDispatches'])
            ->whereKey($receiptId)
            ->where('kind', 'orbit_plan_review')
            ->where('validation_status', ReceiptValidationStatus::Valid)
            ->first();
        $review = $reviewReceipt?->phaseRun;
        $reviewDispatch = $review?->agentDispatches->first();

        $reviewedCandidate = $payload['candidate_sha'] ?? null;
        $reviewDelivery = clone $delivery;
        $reviewDelivery->candidate_sha = is_string($reviewedCandidate) ? $reviewedCandidate : null;

        return $reviewReceipt !== null && $review !== null && $reviewDispatch !== null
            && $implementation->delivery_id === $delivery->id
            && $implementation->phase_name === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            && $implementation->attempt === 1
            && $review->delivery_id === $delivery->id
            && $review->phase_name === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            && in_array($review->attempt, [1, 2], true)
            && $review->status === PhaseRunStatus::Completed
            && $review->output === ['receipt_id' => $reviewReceipt->id, 'result' => 'pass']
            && $review->agentDispatches->count() === 1
            && $reviewDispatch->agent_role === OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            && $reviewDispatch->status === AgentDispatchStatus::Settled
            && $reviewReceipt->payload === $payload
            && ($payload['result'] ?? null) === 'pass'
            && is_string($reviewedCandidate)
            && preg_match('/^[a-f0-9]{40}$/', $reviewedCandidate) === 1
            && $this->reviewReceipts->matches($reviewDelivery, $review, $reviewDispatch, $reviewReceipt);
    }

    /** @param array<string, mixed> $payload */
    public function matchesPayload(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $payload,
    ): bool {
        $allowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'reviewed_candidate_sha', 'candidate_sha', 'handoff_path', 'handoff',
            'artifact_sha', 'gate_receipt_path', 'pull_request_body_path', 'pull_request_body',
            'pull_request_body_sha256', 'flow',
        ];

        return array_diff(array_keys($payload), $allowed) === []
            && count($payload) === count($allowed)
            && ($payload['kind'] ?? null) === 'orbit_implementation'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === $phase->phase_name
            && ($payload['attempt'] ?? null) === $phase->attempt
            && in_array($payload['result'] ?? null, ['ready', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && ($payload['reviewed_candidate_sha'] ?? null) === $this->reviewedCandidate($phase)
            && is_string($payload['candidate_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['candidate_sha']) === 1
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($payload['handoff'] ?? null)
            && trim($payload['handoff']) !== ''
            && $this->matchesEvidence($payload);
    }

    /** @param array<string, mixed> $payload */
    private function matchesEvidence(array $payload): bool
    {
        if (($payload['result'] ?? null) === 'blocked') {
            return ($payload['artifact_sha'] ?? null) === null
                && ($payload['gate_receipt_path'] ?? null) === null
                && ($payload['pull_request_body_path'] ?? null) === null
                && ($payload['pull_request_body'] ?? null) === null
                && ($payload['pull_request_body_sha256'] ?? null) === null
                && ($payload['flow'] ?? null) === null;
        }

        return is_string($payload['artifact_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['artifact_sha']) === 1
            && is_string($payload['gate_receipt_path'] ?? null)
            && trim($payload['gate_receipt_path']) !== ''
            && is_string($payload['pull_request_body_path'] ?? null)
            && str_starts_with($payload['pull_request_body_path'], '.loop/')
            && is_string($payload['pull_request_body'] ?? null)
            && trim($payload['pull_request_body']) !== ''
            && is_string($payload['pull_request_body_sha256'] ?? null)
            && hash_equals($payload['pull_request_body_sha256'], hash('sha256', $payload['pull_request_body']))
            && ($payload['flow'] ?? null) === 'discovery';
    }

    private function reviewedCandidate(PhaseRun $phase): mixed
    {
        $input = $phase->input;
        $review = is_array($input) ? ($input['plan_review_receipt'] ?? null) : null;

        return is_array($review) ? ($review['candidate_sha'] ?? null) : null;
    }
}
