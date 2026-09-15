<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use InvalidArgumentException;

final readonly class OrbitPlanResolutionReceiptValidator
{
    public function __construct(
        private OrbitFeatureWorkflow $workflow,
        private OrbitPlanningReceiptValidator $planningReceipts,
        private OrbitPlanReviewReceiptValidator $reviewReceipts,
    ) {}

    public function matchesSource(
        Delivery $delivery,
        PhaseRun $resolution,
        AgentDispatch $dispatch,
    ): bool {
        $input = $this->associativeArray($resolution->input);

        if ($input === null) {
            return false;
        }

        $matchesSource = match (array_keys($input)) {
            ['planning_receipt_id', 'planning_receipt'] => $this->matchesInitialPlanningSource(
                $delivery,
                $input,
            ),
            ['plan_review_receipt_id', 'plan_review_receipt'] => $this->matchesReviewSource(
                $delivery,
                $input,
            ),
            [
                'planning_receipt_id',
                'planning_receipt',
                'plan_review_receipt_id',
                'plan_review_receipt',
            ] => $this->matchesPlanningCorrectionSource($delivery, $input),
            default => false,
        };

        return $matchesSource
            && $resolution->delivery_id === $delivery->id
            && $resolution->phase_name === OrbitFeatureWorkflow::RESOLUTION_PHASE
            && $resolution->attempt === 1
            && $resolution->agentDispatches()->count() === 1
            && $dispatch->phase_run_id === $resolution->id
            && $dispatch->agent_role === OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::RESOLUTION_PHASE,
                $resolution->attempt,
                OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key)
                .'-loop-resolution-'.$resolution->attempt
            && $dispatch->prompt_name === 'orbit_resolution'
            && OrbitFeatureWorkflow::supportsPromptVersion('orbit_resolution', $dispatch->prompt_version);
    }

    public function matchesInput(
        Delivery $delivery,
        PhaseRun $resolution,
        AgentDispatch $dispatch,
    ): bool {
        $repository = $delivery->projectOrchestration->config['repository'] ?? null;
        $input = $this->associativeArray($resolution->input);

        if (! is_string($repository) || $input === null
            || ! $this->matchesSource($delivery, $resolution, $dispatch)) {
            return false;
        }

        $expectedPrompt = $this->workflow->planResolutionPrompt(
            (string) $delivery->external_issue_key,
            $repository,
            (string) $delivery->worktree_path,
            $delivery->id,
            $resolution->id,
            $dispatch->id,
            $this->receiptCommand($resolution, $dispatch),
            $input,
            $dispatch->prompt_version,
        );

        return hash_equals($dispatch->prompt_hash, hash('sha256', $expectedPrompt));
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

    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
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

    /** @param array<string, mixed> $input */
    private function matchesInitialPlanningSource(Delivery $delivery, array $input): bool
    {
        $receiptId = $input['planning_receipt_id'] ?? null;
        $payload = $input['planning_receipt'] ?? null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $planning = $receipt?->phaseRun;
        $dispatches = $planning?->agentDispatches;
        $dispatch = $dispatches?->first();
        $handoff = $payload['handoff'] ?? null;

        $newSource = $planning?->status === PhaseRunStatus::Completed
            && $planning->failure_code === null
            && $planning->failure_message === null
            && $planning->failure_details === null;
        $legacySource = $planning?->status === PhaseRunStatus::Failed
            && $planning->failure_code === 'planning_blocked'
            && $planning->failure_message === $handoff
            && $planning->failure_details === null;

        return $receipt !== null && $planning !== null && $dispatches !== null && $dispatch !== null
            && is_string($handoff)
            && $planning->delivery_id === $delivery->id
            && $planning->phase_name === OrbitFeatureWorkflow::INITIAL_PHASE
            && $planning->attempt === 1
            && ($newSource || $legacySource)
            && $planning->current_block === null
            && $planning->output === ['receipt_id' => $receipt->id, 'result' => 'blocked']
            && $planning->started_at !== null
            && $planning->finished_at !== null
            && $dispatches->count() === 1
            && $dispatch->agent_role === OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::INITIAL_PHASE,
                1,
                OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-builder'
            && $dispatch->prompt_name === 'orbit_planning'
            && $dispatch->prompt_version === OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION
            && $this->matchesInitialPlanningPrompt($delivery, $planning, $dispatch)
            && $dispatch->status === AgentDispatchStatus::Settled
            && $dispatch->dispatched_at !== null
            && $dispatch->settled_at !== null
            && $receipt->kind === 'orbit_planning'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && $receipt->payload === $payload
            && ($payload['result'] ?? null) === 'blocked'
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && $this->planningReceipts->matches($delivery, $planning, $dispatch, $receipt);
    }

    private function matchesInitialPlanningPrompt(
        Delivery $delivery,
        PhaseRun $planning,
        AgentDispatch $dispatch,
    ): bool {
        $input = $this->associativeArray($planning->input);
        $retiredValue = is_array($input) ? ($input['retired_stale_worktree'] ?? null) : null;

        try {
            $retired = is_array($retiredValue)
                ? RetiredOrbitStaleWorktree::fromArray($retiredValue)
                : null;
        } catch (InvalidArgumentException) {
            return false;
        }

        if (($retiredValue !== null && $retired === null)
            || ($retired !== null && $retired->issueKey !== $delivery->external_issue_key)) {
            return false;
        }

        $expected = $this->workflow->planningPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $planning->id,
            $dispatch->id,
            $this->planningReceiptCommand($planning, $dispatch),
            $retired,
        );

        return hash_equals($dispatch->prompt_hash, hash('sha256', $expected));
    }

    /** @param array<string, mixed> $input */
    private function matchesReviewSource(Delivery $delivery, array $input): bool
    {
        $receiptId = $input['plan_review_receipt_id'] ?? null;
        $payload = $input['plan_review_receipt'] ?? null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $review = $receipt?->phaseRun;
        $dispatches = $review?->agentDispatches;
        $dispatch = $dispatches?->first();
        $result = $payload['result'] ?? null;
        $routesToResolution = $result === 'blocked'
            || ($result === 'fix' && $review !== null && $review->attempt > 1);

        return $receipt !== null && $review !== null && $dispatches !== null && $dispatch !== null
            && $routesToResolution
            && $review->delivery_id === $delivery->id
            && $review->phase_name === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            && in_array($review->attempt, [1, 2], true)
            && $review->status === PhaseRunStatus::Completed
            && $review->current_block === null
            && $review->output === ['receipt_id' => $receipt->id, 'result' => $result]
            && $review->finished_at !== null
            && $dispatches->count() === 1
            && $dispatch->agent_role === OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
                $review->attempt,
                OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === $this->workflow->planReviewAgentName(
                (string) $delivery->external_issue_key,
                $review->attempt,
            )
            && $dispatch->prompt_name === 'orbit_plan_review'
            && $dispatch->prompt_version === OrbitFeatureWorkflow::PLAN_REVIEW_PROMPT_VERSION
            && $this->matchesPlanReviewPrompt($delivery, $review, $dispatch)
            && $dispatch->status === AgentDispatchStatus::Settled
            && $dispatch->settled_at !== null
            && $receipt->kind === 'orbit_plan_review'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && $receipt->payload === $payload
            && $receipt->validated_at !== null
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && $this->reviewReceipts->matches($delivery, $review, $dispatch, $receipt);
    }

    /** @param array<string, mixed> $input */
    private function matchesPlanningCorrectionSource(Delivery $delivery, array $input): bool
    {
        $receiptId = $input['planning_receipt_id'] ?? null;
        $payload = $input['planning_receipt'] ?? null;
        $reviewReceiptId = $input['plan_review_receipt_id'] ?? null;
        $reviewPayload = $input['plan_review_receipt'] ?? null;

        if (! is_int($receiptId) || ! is_array($payload) || array_is_list($payload)
            || ! is_int($reviewReceiptId) || ! is_array($reviewPayload) || array_is_list($reviewPayload)) {
            return false;
        }

        $receipt = Receipt::query()->with(['phaseRun.agentDispatches'])->find($receiptId);
        $planning = $receipt?->phaseRun;
        $dispatches = $planning?->agentDispatches;
        $dispatch = $dispatches?->first();

        return $receipt !== null && $planning !== null && $dispatches !== null && $dispatch !== null
            && $planning->delivery_id === $delivery->id
            && $planning->phase_name === OrbitFeatureWorkflow::INITIAL_PHASE
            && $planning->attempt === 2
            && $planning->status === PhaseRunStatus::Completed
            && $planning->current_block === null
            && $planning->input === [
                'plan_review_receipt_id' => $reviewReceiptId,
                'plan_review_receipt' => $reviewPayload,
            ]
            && $planning->output === ['receipt_id' => $receipt->id, 'result' => 'blocked']
            && $planning->finished_at !== null
            && $dispatches->count() === 1
            && $dispatch->agent_role === OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::INITIAL_PHASE,
                2,
                OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-builder'
            && $dispatch->prompt_name === 'orbit_planning_correction'
            && $dispatch->prompt_version === OrbitFeatureWorkflow::PLANNING_CORRECTION_PROMPT_VERSION
            && $this->matchesPlanningCorrectionPrompt($delivery, $planning, $dispatch)
            && $dispatch->status === AgentDispatchStatus::Settled
            && $dispatch->settled_at !== null
            && $receipt->kind === 'orbit_planning'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && $receipt->payload === $payload
            && $receipt->validated_at !== null
            && ($payload['result'] ?? null) === 'blocked'
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && ($reviewPayload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && $this->planningReceipts->matches($delivery, $planning, $dispatch, $receipt)
            && $this->reviewReceipts->matchesPlanningProvenance($delivery, $planning);
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
            || ($proposal['resume_phase'] ?? null) !== OrbitFeatureWorkflow::INITIAL_PHASE
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

    private function matchesPlanReviewPrompt(
        Delivery $delivery,
        PhaseRun $review,
        AgentDispatch $dispatch,
    ): bool {
        $input = $this->associativeArray($review->input);
        $planning = is_array($input)
            ? $this->associativeArray($input['planning_receipt'] ?? null)
            : null;

        if ($planning === null) {
            return false;
        }

        $expected = $this->workflow->planReviewPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $review->id,
            $dispatch->id,
            $this->planReviewReceiptCommand($review, $dispatch),
            $planning,
        );

        return hash_equals($dispatch->prompt_hash, hash('sha256', $expected));
    }

    private function matchesPlanningCorrectionPrompt(
        Delivery $delivery,
        PhaseRun $planning,
        AgentDispatch $dispatch,
    ): bool {
        $input = $this->associativeArray($planning->input);
        $review = is_array($input)
            ? $this->associativeArray($input['plan_review_receipt'] ?? null)
            : null;

        if ($review === null) {
            return false;
        }

        $expected = $this->workflow->planningCorrectionPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $planning->id,
            $dispatch->id,
            $this->planningReceiptCommand($planning, $dispatch),
            $review,
        );

        return hash_equals($dispatch->prompt_hash, hash('sha256', $expected));
    }

    private function planReviewReceiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-plan-review-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
    }

    private function planningReceiptCommand(PhaseRun $phase, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phase->id,
            $dispatch->id,
        );
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
}
