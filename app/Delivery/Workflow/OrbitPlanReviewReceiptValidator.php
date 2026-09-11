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

final readonly class OrbitPlanReviewReceiptValidator
{
    public function __construct(private OrbitPlanningReceiptValidator $planningReceipts) {}

    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        return $receipt->phase_run_id === $phase->id
            && $receipt->kind === 'orbit_plan_review'
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

    public function matchesInput(Delivery $delivery, PhaseRun $review): bool
    {
        $input = $review->input;
        $receiptId = is_array($input) ? ($input['planning_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['planning_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['planning_receipt_id', 'planning_receipt']) !== []
            || count($input) !== 2) {
            return false;
        }

        $planningReceipt = Receipt::query()
            ->with(['phaseRun.agentDispatches'])
            ->whereKey($receiptId)
            ->where('kind', 'orbit_planning')
            ->where('validation_status', ReceiptValidationStatus::Valid)
            ->first();
        $planningPhase = $planningReceipt?->phaseRun;
        $planningDispatch = $planningPhase?->agentDispatches->first();

        return $planningReceipt !== null && $planningPhase !== null && $planningDispatch !== null
            && $planningPhase->delivery_id === $delivery->id
            && $planningPhase->phase_name === OrbitFeatureWorkflow::INITIAL_PHASE
            && $planningPhase->attempt === $review->attempt
            && $planningPhase->status === PhaseRunStatus::Completed
            && $planningPhase->output === ['receipt_id' => $planningReceipt->id, 'result' => 'ready']
            && $planningPhase->agentDispatches->count() === 1
            && $planningDispatch->agent_role === OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            && $planningDispatch->status === AgentDispatchStatus::Settled
            && $planningReceipt->payload === $payload
            && ($payload['result'] ?? null) === 'ready'
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && $this->matchesPlanningProvenance($delivery, $planningPhase)
            && $this->planningReceipts->matches(
                $delivery,
                $planningPhase,
                $planningDispatch,
                $planningReceipt,
            );
    }

    private function matchesPlanningProvenance(Delivery $delivery, PhaseRun $planning): bool
    {
        if ($planning->attempt === 1) {
            return true;
        }

        $input = $planning->input;
        $receiptId = is_array($input) ? ($input['plan_review_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['plan_review_receipt'] ?? null) : null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || array_diff(array_keys($input), ['plan_review_receipt_id', 'plan_review_receipt']) !== []
            || count($input) !== 2) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $review = $receipt?->phaseRun;
        $dispatch = $review?->agentDispatches->first();

        return $receipt !== null && $review !== null && $dispatch !== null
            && $review->delivery_id === $delivery->id
            && $review->phase_name === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            && $review->attempt === $planning->attempt - 1
            && $review->status === PhaseRunStatus::Completed
            && $review->output === ['receipt_id' => $receipt->id, 'result' => 'fix']
            && $review->agentDispatches->count() === 1
            && $dispatch->agent_role === OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            && $dispatch->status === AgentDispatchStatus::Settled
            && $receipt->payload === $payload
            && ($payload['result'] ?? null) === 'fix'
            && $this->matches($delivery, $review, $dispatch, $receipt);
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
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'artifact_sha', 'plan_sha256',
        ];

        return array_diff(array_keys($payload), $allowed) === []
            && count($payload) === count($allowed)
            && ($payload['kind'] ?? null) === 'orbit_plan_review'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === $phase->phase_name
            && ($payload['attempt'] ?? null) === $phase->attempt
            && in_array($payload['result'] ?? null, ['pass', 'fix', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($payload['handoff'] ?? null)
            && trim($payload['handoff']) !== ''
            && $this->matchesArtifact($payload);
    }

    /** @param array<string, mixed> $payload */
    private function matchesArtifact(array $payload): bool
    {
        if (($payload['result'] ?? null) === 'blocked') {
            return ($payload['artifact_sha'] ?? null) === null
                && ($payload['plan_sha256'] ?? null) === null;
        }

        return is_string($payload['artifact_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['artifact_sha']) === 1
            && is_string($payload['plan_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $payload['plan_sha256']) === 1;
    }
}
