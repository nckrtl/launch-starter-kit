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

final readonly class OrbitPullRequestResolutionReceiptValidator
{
    public function __construct(
        private OrbitFeatureWorkflow $workflow,
        private OrbitImplementationReceiptValidator $implementations,
        private OrbitPullRequestReviewReceiptValidator $reviews,
        private OrbitPullRequestReviewSourceValidator $sources,
    ) {}

    public function matchesInput(Delivery $delivery, PhaseRun $resolution, AgentDispatch $dispatch): bool
    {
        $input = $this->associativeArray($resolution->input);

        if ($input === null) {
            return false;
        }

        $matchesSource = match (array_keys($input)) {
            [
                'pr_review_receipt_id',
                'pr_review_receipt',
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
                'published_review',
            ] => $this->matchesReviewInput($delivery, $input),
            [
                'implementation_receipt_id',
                'implementation_receipt',
                'pull_request',
            ] => $this->matchesImplementationInput($delivery, $input),
            default => false,
        };

        if (! $matchesSource) {
            return false;
        }

        $repository = $delivery->projectOrchestration->config['repository'] ?? null;

        if (! is_string($repository)) {
            return false;
        }

        $expectedPrompt = $this->workflow->pullRequestResolutionPrompt(
            (string) $delivery->external_issue_key,
            $repository,
            (string) $delivery->worktree_path,
            $delivery->id,
            $resolution->id,
            $dispatch->id,
            $this->receiptCommand($resolution, $dispatch),
            $input,
        );

        return $resolution->delivery_id === $delivery->id
            && $resolution->phase_name === OrbitFeatureWorkflow::RESOLUTION_PHASE
            && $resolution->attempt >= 1
            && $resolution->agentDispatches()->count() === 1
            && $dispatch->phase_run_id === $resolution->id
            && $dispatch->agent_role === OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::RESOLUTION_PHASE,
                $resolution->attempt,
                OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-resolution-'.$resolution->attempt
            && $dispatch->prompt_name === 'orbit_resolution'
            && $dispatch->prompt_version === OrbitFeatureWorkflow::RESOLUTION_PROMPT_VERSION
            && hash_equals($dispatch->prompt_hash, hash('sha256', $expectedPrompt));
    }

    /** @return array<string, mixed>|null */
    private function associativeArray(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        foreach ($value as $key => $_item) {
            if (! is_string($key)) {
                return null;
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private function matchesReviewInput(Delivery $delivery, array $input): bool
    {
        $reviewReceiptId = $input['pr_review_receipt_id'] ?? null;
        $reviewPayload = $input['pr_review_receipt'] ?? null;
        $implementationReceiptId = $input['implementation_receipt_id'] ?? null;
        $implementationPayload = $input['implementation_receipt'] ?? null;
        $pullRequest = $input['pull_request'] ?? null;
        $publishedReview = $input['published_review'] ?? null;

        $result = is_array($reviewPayload) ? ($reviewPayload['result'] ?? null) : null;

        if (! is_int($reviewReceiptId) || ! is_array($reviewPayload) || array_is_list($reviewPayload)
            || ! is_int($implementationReceiptId) || ! is_array($implementationPayload) || array_is_list($implementationPayload)
            || ! is_array($pullRequest) || array_is_list($pullRequest)
            || ! in_array($result, ['changes', 'blocked'], true)
            || ($result === 'changes' && (! is_array($publishedReview) || array_is_list($publishedReview)))
            || ($result === 'blocked' && $publishedReview !== null)) {
            return false;
        }

        $reviewReceipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($reviewReceiptId);
        $review = $reviewReceipt?->phaseRun;
        $reviewDispatches = $review?->agentDispatches;
        $reviewDispatch = $reviewDispatches?->first();
        $source = $review === null ? null : $this->sources->sourceReceipt($delivery, $review, true);

        if ($reviewReceipt === null || $review === null || $reviewDispatches === null || $reviewDispatch === null
            || $source === null || $source->id !== $implementationReceiptId
            || $review->delivery_id !== $delivery->id
            || $review->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $review->attempt < 1
            || $review->status !== PhaseRunStatus::Completed
            || $review->finished_at === null
            || $reviewDispatches->count() !== 1
            || $reviewDispatch->status !== AgentDispatchStatus::Settled
            || $reviewReceipt->payload !== $reviewPayload
            || $source->payload !== $implementationPayload
            || ($reviewPayload['result'] ?? null) !== $result
            || ($reviewPayload['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || ($implementationPayload['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || ! $this->reviews->matches($delivery, $review, $reviewDispatch, $reviewReceipt, true)
            || $review->output !== [
                'receipt_id' => $reviewReceipt->id,
                'result' => $result,
                'published_review' => $publishedReview,
            ]
            || $pullRequest !== [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => true,
            ]) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $input */
    private function matchesImplementationInput(Delivery $delivery, array $input): bool
    {
        $receiptId = $input['implementation_receipt_id'] ?? null;
        $payload = $input['implementation_receipt'] ?? null;
        $pullRequest = $input['pull_request'] ?? null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || ($pullRequest !== null && (! is_array($pullRequest) || array_is_list($pullRequest)))) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $phase = $receipt?->phaseRun;
        $dispatches = $phase?->agentDispatches;
        $dispatch = $dispatches?->first();
        $result = $payload['result'] ?? null;
        $atStart = $phase === null ? null : $this->implementationDeliveryAtStart($delivery, $phase);
        $expectedPullRequest = $result === 'ready' ? [
            'number' => $delivery->pull_request_number,
            'url' => $delivery->pull_request_url,
            'mergeable' => false,
        ] : null;
        $expectedOutput = $result === 'ready' ? [
            'receipt_id' => $receipt?->id,
            'result' => 'ready',
            'pull_request_number' => $delivery->pull_request_number,
            'pull_request_url' => $delivery->pull_request_url,
            'mergeable' => false,
        ] : [
            'receipt_id' => $receipt?->id,
            'result' => 'blocked',
        ];
        $expectedCandidate = $result === 'ready'
            ? ($payload['candidate_sha'] ?? null)
            : ($payload['reviewed_candidate_sha'] ?? null);

        return $receipt !== null && $phase !== null && $dispatches !== null && $dispatch !== null
            && $atStart !== null
            && in_array($result, ['ready', 'blocked'], true)
            && $phase->delivery_id === $delivery->id
            && $phase->phase_name === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            && $phase->attempt >= 1
            && $phase->status === PhaseRunStatus::Completed
            && $phase->finished_at !== null
            && $phase->output === $expectedOutput
            && $dispatches->count() === 1
            && $dispatch->status === AgentDispatchStatus::Settled
            && $receipt->payload === $payload
            && $delivery->candidate_sha === $expectedCandidate
            && $pullRequest === $expectedPullRequest
            && $this->implementations->matches($atStart, $phase, $dispatch, $receipt);
    }

    private function implementationDeliveryAtStart(Delivery $delivery, PhaseRun $implementation): ?Delivery
    {
        $input = $implementation->input;
        $source = is_array($input)
            ? ($input[$implementation->attempt === 1
                ? 'plan_review_receipt'
                : 'implementation_receipt'] ?? null)
            : null;
        $candidate = is_array($source) ? ($source['candidate_sha'] ?? null) : null;

        if (! is_string($candidate)) {
            return null;
        }

        $atStart = clone $delivery;
        $atStart->candidate_sha = $candidate;

        return $atStart;
    }

    /** @param array<string, mixed> $payload */
    public function matchesPayload(
        Delivery $delivery,
        PhaseRun $resolution,
        AgentDispatch $dispatch,
        array $payload,
    ): bool {
        $allowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'handoff_sha256',
            'resolution_path', 'resolution', 'resolution_sha256',
        ];
        $handoff = $payload['handoff'] ?? null;
        $result = $payload['result'] ?? null;
        $resolutionPath = $payload['resolution_path'] ?? null;
        $proposal = $payload['resolution'] ?? null;
        $resolutionHash = $payload['resolution_sha256'] ?? null;

        return array_keys($payload) === $allowed
            && ($payload['kind'] ?? null) === 'orbit_resolution'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === OrbitFeatureWorkflow::RESOLUTION_PHASE
            && ($payload['attempt'] ?? null) === $resolution->attempt
            && in_array($result, ['proposal', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($handoff)
            && trim($handoff) !== ''
            && ($payload['handoff_sha256'] ?? null) === hash('sha256', $handoff)
            && ($result === 'proposal'
                ? $this->matchesProposal($resolutionPath, $proposal, $resolutionHash)
                : $resolutionPath === null && $proposal === null && $resolutionHash === null)
            && $this->matchesRuntime($delivery, $dispatch)
            && $this->matchesInput($delivery, $resolution, $dispatch);
    }

    public function matches(Delivery $delivery, PhaseRun $phase, AgentDispatch $dispatch, Receipt $receipt): bool
    {
        return $receipt->phase_run_id === $phase->id
            && $receipt->kind === 'orbit_resolution'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && $receipt->validated_at !== null
            && hash_equals(
                $receipt->payload_hash,
                hash('sha256', json_encode($receipt->payload, JSON_THROW_ON_ERROR)),
            )
            && $receipt->candidate_sha === ($receipt->payload['candidate_sha'] ?? null)
            && $this->matchesPayload($delivery, $phase, $dispatch, $receipt->payload);
    }

    private function receiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-resolution-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
    }

    private function matchesProposal(mixed $path, mixed $proposal, mixed $hash): bool
    {
        if (! is_string($path) || ! str_starts_with($path, '.loop/')
            || ! is_array($proposal) || array_is_list($proposal)
            || array_diff(array_keys($proposal), [
                'schema',
                'resume_phase',
                'required_adrs',
                'human_decisions',
                'issue_changes',
                'plan_changes',
            ]) !== []
            || count($proposal) !== 6
            || ($proposal['schema'] ?? null) !== 1
            || ! in_array($proposal['resume_phase'] ?? null, ['planning', 'implementing'], true)
            || ! is_string($hash)
            || ! hash_equals($hash, hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)))) {
            return false;
        }

        foreach (['required_adrs', 'human_decisions', 'issue_changes', 'plan_changes'] as $field) {
            $requirements = $proposal[$field] ?? null;

            if (! is_array($requirements) || ! array_is_list($requirements)) {
                return false;
            }

            foreach ($requirements as $requirement) {
                if (! is_string($requirement) || trim($requirement) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function isIndependentFromPriorAgents(Delivery $delivery, AgentDispatch $resolver): bool
    {
        return ! AgentDispatch::query()
            ->whereHas('phaseRun', fn ($query) => $query->where('delivery_id', $delivery->id))
            ->where('id', '!=', $resolver->id)
            ->where(function ($query) use ($resolver): void {
                $query->where('herdr_pane_id', $resolver->herdr_pane_id)
                    ->orWhere('herdr_terminal_id', $resolver->herdr_terminal_id)
                    ->orWhere('herdr_agent_name', $resolver->herdr_agent_name)
                    ->when(
                        $resolver->herdr_agent_id !== null,
                        fn ($query) => $query->orWhere('herdr_agent_id', $resolver->herdr_agent_id),
                    );
            })
            ->exists();
    }

    private function matchesRuntime(Delivery $delivery, AgentDispatch $dispatch): bool
    {
        return $dispatch->herdr_session === ($delivery->projectOrchestration->config['herdrSession'] ?? null)
            && is_string($dispatch->herdr_workspace_id) && trim($dispatch->herdr_workspace_id) !== ''
            && is_string($dispatch->herdr_tab_id) && trim($dispatch->herdr_tab_id) !== ''
            && is_string($dispatch->herdr_pane_id) && trim($dispatch->herdr_pane_id) !== ''
            && is_string($dispatch->herdr_terminal_id) && trim($dispatch->herdr_terminal_id) !== ''
            && $dispatch->dispatched_at !== null
            && $this->isIndependentFromPriorAgents($delivery, $dispatch);
    }
}
