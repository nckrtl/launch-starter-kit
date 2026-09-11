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
    public function __construct(
        private OrbitPlanReviewReceiptValidator $reviewReceipts,
        private OrbitFeatureWorkflow $workflow,
    ) {}

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
        return match ($implementation->attempt) {
            1 => $this->matchesPlanReviewInput($delivery, $implementation),
            2 => $this->matchesCorrectionInput($delivery, $implementation),
            default => false,
        };
    }

    private function matchesPlanReviewInput(Delivery $delivery, PhaseRun $implementation): bool
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

    private function matchesCorrectionInput(Delivery $delivery, PhaseRun $correction): bool
    {
        $input = $correction->input;
        $receiptId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;
        $isPullRequestReviewCorrection = is_array($input)
            && array_key_exists('pr_review_receipt_id', $input);
        $allowed = $isPullRequestReviewCorrection
            ? [
                'pr_review_receipt_id',
                'pr_review_receipt',
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
                'published_review',
            ]
            : [
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
            ];
        $expectedMergeable = $isPullRequestReviewCorrection;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || ! is_array($pullRequest) || array_is_list($pullRequest)
            || array_diff(array_keys($input), $allowed) !== []
            || count($input) !== count($allowed)) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $source = $receipt?->phaseRun;
        $sourceDispatches = $source?->agentDispatches;
        $sourceDispatch = $sourceDispatches?->first();
        $sourceCandidate = $payload['candidate_sha'] ?? null;
        $sourceDelivery = clone $delivery;
        $sourceDelivery->candidate_sha = is_string($sourceCandidate) ? $sourceCandidate : null;

        return $receipt !== null && $source !== null && $sourceDispatches !== null && $sourceDispatch !== null
            && $correction->delivery_id === $delivery->id
            && $correction->phase_name === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            && $correction->attempt === 2
            && $source->delivery_id === $delivery->id
            && $source->phase_name === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            && $source->attempt === 1
            && $source->status === PhaseRunStatus::Completed
            && $source->finished_at !== null
            && $source->output === [
                'receipt_id' => $receipt->id,
                'result' => 'ready',
                'pull_request_number' => $delivery->pull_request_number,
                'pull_request_url' => $delivery->pull_request_url,
                'mergeable' => $expectedMergeable,
            ]
            && $sourceDispatches->count() === 1
            && $sourceDispatch->agent_role === OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            && $sourceDispatch->status === AgentDispatchStatus::Settled
            && $sourceDispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                1,
                OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            )->value
            && $sourceDispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-builder'
            && $sourceDispatch->prompt_name === 'orbit_implementation'
            && $sourceDispatch->prompt_version === OrbitFeatureWorkflow::IMPLEMENTATION_PROMPT_VERSION
            && preg_match('/^[a-f0-9]{64}$/', $sourceDispatch->prompt_hash) === 1
            && $sourceDispatch->prompt_hash !== str_repeat('0', 64)
            && $sourceDispatch->dispatched_at !== null
            && $sourceDispatch->settled_at !== null
            && $source->receipts()->where('kind', 'orbit_implementation')->count() === 1
            && $receipt->payload === $payload
            && ($payload['result'] ?? null) === 'ready'
            && $delivery->candidate_sha === $sourceCandidate
            && is_int($delivery->pull_request_number)
            && $delivery->pull_request_number > 0
            && $delivery->pull_request_url === "https://github.com/nckrtl/orbit/pull/{$delivery->pull_request_number}"
            && $pullRequest === [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => $expectedMergeable,
            ]
            && $this->matches($sourceDelivery, $source, $sourceDispatch, $receipt)
            && (! $isPullRequestReviewCorrection || $this->matchesPullRequestReviewCorrection(
                $delivery,
                $correction,
                $receipt,
            ));
    }

    private function matchesPullRequestReviewCorrection(
        Delivery $delivery,
        PhaseRun $correction,
        Receipt $implementationReceipt,
    ): bool {
        $input = $correction->input;
        $reviewReceiptId = is_array($input) ? ($input['pr_review_receipt_id'] ?? null) : null;
        $reviewPayload = is_array($input) ? ($input['pr_review_receipt'] ?? null) : null;
        $published = is_array($input) ? ($input['published_review'] ?? null) : null;

        if (! is_int($reviewReceiptId) || ! is_array($reviewPayload) || array_is_list($reviewPayload)
            || ! is_array($published) || array_is_list($published)) {
            return false;
        }

        $pullRequest = $this->associativeArray($input['pull_request'] ?? null);

        if ($pullRequest === null) {
            return false;
        }

        $reviewReceipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($reviewReceiptId);
        $review = $reviewReceipt?->phaseRun;
        $reviewDispatches = $review?->agentDispatches;
        $reviewDispatch = $reviewDispatches?->first();
        $implementationPayload = $implementationReceipt->payload;
        $expectedReviewPrompt = $review === null || $reviewDispatch === null
            ? null
            : $this->workflow->pullRequestReviewPrompt(
                (string) $delivery->external_issue_key,
                (string) $delivery->worktree_path,
                $delivery->id,
                $review->id,
                $reviewDispatch->id,
                sprintf(
                    '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg(base_path('artisan')),
                    $review->id,
                    $reviewDispatch->id,
                ),
                $implementationPayload,
                $pullRequest,
            );
        $reviewAllowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'artifact_sha',
            'pull_request_body_path', 'pull_request_body', 'pull_request_body_sha256',
        ];
        $reviewHandoff = $reviewPayload['handoff'] ?? null;

        return $reviewReceipt !== null && $review !== null
            && $reviewDispatches !== null && $reviewDispatch !== null
            && $review->delivery_id === $delivery->id
            && $review->phase_name === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && $review->attempt === 1
            && $review->status === PhaseRunStatus::Completed
            && $review->finished_at !== null
            && $review->current_block === null
            && $reviewDispatches->count() === 1
            && $reviewDispatch->agent_role === OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
            && $reviewDispatch->status === AgentDispatchStatus::Settled
            && $reviewDispatch->settled_at !== null
            && $reviewDispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::PR_REVIEW_PHASE,
                1,
                OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            )->value
            && $reviewDispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-pr-review-1'
            && $reviewDispatch->prompt_name === 'orbit_pr_review'
            && $reviewDispatch->prompt_version === 1
            && is_string($expectedReviewPrompt)
            && hash_equals($reviewDispatch->prompt_hash, hash('sha256', $expectedReviewPrompt))
            && $reviewDispatch->dispatched_at !== null
            && $review->receipts()->where('kind', 'orbit_pr_review')->count() === 1
            && $reviewReceipt->kind === 'orbit_pr_review'
            && $reviewReceipt->schema_version === 1
            && $reviewReceipt->validation_status === ReceiptValidationStatus::Valid
            && $reviewReceipt->validated_at !== null
            && hash_equals(
                $reviewReceipt->payload_hash,
                hash('sha256', json_encode($reviewReceipt->payload, JSON_THROW_ON_ERROR)),
            )
            && $reviewReceipt->payload === $reviewPayload
            && $reviewReceipt->candidate_sha === ($implementationPayload['candidate_sha'] ?? null)
            && array_diff(array_keys($reviewPayload), $reviewAllowed) === []
            && count($reviewPayload) === count($reviewAllowed)
            && ($reviewPayload['kind'] ?? null) === 'orbit_pr_review'
            && ($reviewPayload['schema_version'] ?? null) === 1
            && ($reviewPayload['delivery_id'] ?? null) === $delivery->id
            && ($reviewPayload['dispatch_id'] ?? null) === $reviewDispatch->id
            && ($reviewPayload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($reviewPayload['phase'] ?? null) === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && ($reviewPayload['attempt'] ?? null) === 1
            && ($reviewPayload['result'] ?? null) === 'changes'
            && ($reviewPayload['worktree'] ?? null) === $delivery->worktree_path
            && ($reviewPayload['candidate_sha'] ?? null) === ($implementationPayload['candidate_sha'] ?? null)
            && ($reviewPayload['artifact_sha'] ?? null) === ($implementationPayload['artifact_sha'] ?? null)
            && is_string($reviewPayload['handoff_path'] ?? null)
            && str_starts_with($reviewPayload['handoff_path'], '.loop/')
            && is_string($reviewHandoff) && trim($reviewHandoff) !== ''
            && ($reviewPayload['pull_request_body_path'] ?? null) === null
            && ($reviewPayload['pull_request_body'] ?? null) === null
            && ($reviewPayload['pull_request_body_sha256'] ?? null) === null
            && $review->input === [
                'implementation_receipt_id' => $implementationReceipt->id,
                'implementation_receipt' => $implementationPayload,
                'pull_request' => $pullRequest,
            ]
            && $published === [
                'id' => $published['id'] ?? null,
                'reviewer_login' => 'tom-nckrtl[bot]',
                'candidate_sha' => $implementationPayload['candidate_sha'] ?? null,
                'state' => 'CHANGES_REQUESTED',
                'review_body_sha256' => hash('sha256', $reviewHandoff),
                'pull_request_body_sha256' => $implementationPayload['pull_request_body_sha256'] ?? null,
            ]
            && is_int($published['id'] ?? null) && $published['id'] > 0
            && $review->output === [
                'receipt_id' => $reviewReceipt->id,
                'result' => 'changes',
                'published_review' => $published,
            ];
    }

    /** @return array<string, mixed>|null */
    private function associativeArray(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
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
        $review = is_array($input)
            ? ($input[$phase->attempt === 1 ? 'plan_review_receipt' : 'implementation_receipt'] ?? null)
            : null;

        return is_array($review) ? ($review['candidate_sha'] ?? null) : null;
    }
}
