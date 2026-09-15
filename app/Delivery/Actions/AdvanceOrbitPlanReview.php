<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanReviewAdvancementFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanReviewReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitPlanReview
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private OrbitPlanReviewReceiptValidator $receipts,
    ) {}

    public function handle(int $deliveryId): void
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
            throw new OrbitPlanReviewAdvancementFailed('The delivery is not an Orbit feature workflow.');
        }

        if ($this->alreadyHandled($delivery)) {
            return;
        }

        $state = $this->reviewState($delivery);

        if ($state === null) {
            return;
        }

        [$phase, $dispatch, $receipt] = $state;
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitPlanReviewAdvancementFailed('The Orbit project is not enabled with valid configuration.');
        }

        $preparation = $this->preparations->startup($delivery);
        $planning = $this->planningPayload($phase);
        $result = $this->result($receipt);
        $candidateSha = $this->sha($receipt->payload, 'candidate_sha');
        $reviewArtifactSha = $result === 'blocked'
            ? null
            : $this->sha($receipt->payload, 'artifact_sha');
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

            if ($this->alreadyHandled($delivery)) {
                return;
            }

            $state = $this->reviewState($delivery);

            if ($state === null) {
                throw new OrbitPlanReviewAdvancementFailed('The plan-review ledger changed before advancement.');
            }

            if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()
                || $state[0]->id !== $phase->id
                || $state[1]->id !== $dispatch->id
                || $state[2]->id !== $receipt->id
                || ! hash_equals($state[2]->payload_hash, $receipt->payload_hash)) {
                throw new OrbitPlanReviewAdvancementFailed('The plan-review ledger changed before advancement.');
            }

            $issue = $this->issues->fetch(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue($preparation, $issue);
            $verified = $this->repository->verifyPlanningOutcome(
                $config,
                $preparation->worktree,
                $preparation->snapshot,
                $candidateSha,
                null,
            );
            $this->assertPlanningCandidate($delivery, $planning, $verified);
            $reviewArtifact = $reviewArtifactSha === null
                ? null
                : $this->repository->verifyPlanningArtifact(
                    $config,
                    new PreparedWorktree($preparation->worktree->path, $candidateSha),
                    $preparation->snapshot->issueKey,
                    $reviewArtifactSha,
                    strtoupper($result),
                );
            $this->assertReviewArtifact($receipt, $reviewArtifact);

            $this->commitTransition(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
                $verified,
                $reviewArtifact,
            );
        } finally {
            $reservation->release();
        }
    }

    /** @return array{PhaseRun, AgentDispatch, Receipt}|null */
    private function reviewState(Delivery $delivery): ?array
    {
        if ($delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return null;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
            ->latest('attempt')
            ->first();

        if ($phase === null || $phase->attempt < 1 || $phase->status !== PhaseRunStatus::Running) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review phase is not running.');
        }

        $dispatches = $phase->agentDispatches()->get();
        $receipts = $phase->receipts()->where('kind', 'orbit_plan_review')->get();

        if ($dispatches->count() !== 1) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review phase must retain exactly one dispatch.');
        }

        $dispatch = $dispatches->firstOrFail();
        $receipt = $receipts->first();

        if ($dispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review dispatch identity is inconsistent.');
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled || $receipt === null) {
            return null;
        }

        if ($receipts->count() !== 1
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review receipt does not match the settled dispatch.');
        }

        return [$phase, $dispatch, $receipt];
    }

    private function alreadyHandled(Delivery $delivery): bool
    {
        $routedPhases = [
            OrbitFeatureWorkflow::INITIAL_PHASE,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            OrbitFeatureWorkflow::RESOLUTION_PHASE,
        ];

        if ($delivery->status !== DeliveryStatus::Queued
            || ! in_array($delivery->current_phase, $routedPhases, true)) {
            return false;
        }

        $review = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
            ->latest('attempt')
            ->first();

        if ($review === null || $review->status !== PhaseRunStatus::Completed) {
            return false;
        }

        $dispatches = $review->agentDispatches()->get();
        $reviewReceipts = $review->receipts()->where('kind', 'orbit_plan_review')->get();
        $dispatch = $dispatches->first();
        $receipt = $reviewReceipts->first();

        if ($dispatches->count() !== 1 || $reviewReceipts->count() !== 1
            || $dispatch === null || $receipt === null
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || ! $this->receipts->matches($delivery, $review, $dispatch, $receipt)
            || $review->finished_at === null) {
            throw new OrbitPlanReviewAdvancementFailed('The retained plan-review transition is inconsistent.');
        }

        $result = $this->result($receipt);

        if ($review->output !== ['receipt_id' => $receipt->id, 'result' => $result]
            || $delivery->candidate_sha !== $receipt->payload['candidate_sha']) {
            throw new OrbitPlanReviewAdvancementFailed('The retained plan-review transition is inconsistent.');
        }

        $intent = $this->nextIntent($delivery, $review, $result);
        $next = $delivery->phaseRuns()
            ->where('phase_name', $intent['phase'])
            ->where('attempt', $intent['attempt'])
            ->first();
        $nextDispatches = $next?->agentDispatches()->get();
        $nextDispatch = $nextDispatches?->first();
        $expectedInput = [
            'plan_review_receipt_id' => $receipt->id,
            'plan_review_receipt' => $receipt->payload,
        ];
        $expectedKey = IdempotencyKey::forDispatch(
            $delivery->id,
            $intent['phase'],
            $intent['attempt'],
            $intent['role'],
        )->value;

        if ($delivery->current_phase !== $intent['phase']
            || $next === null
            || $next->status !== PhaseRunStatus::Pending
            || $next->input !== $expectedInput
            || $nextDispatches?->count() !== 1
            || $nextDispatch === null
            || $nextDispatch->agent_role !== $intent['role']
            || $nextDispatch->idempotency_key !== $expectedKey
            || $nextDispatch->herdr_agent_name !== $intent['agent']
            || $nextDispatch->prompt_name !== $intent['prompt']
            || ! OrbitFeatureWorkflow::supportsPromptVersion($nextDispatch->prompt_name, $nextDispatch->prompt_version)
            || $nextDispatch->prompt_hash !== str_repeat('0', 64)
            || $nextDispatch->status !== AgentDispatchStatus::Pending) {
            throw new OrbitPlanReviewAdvancementFailed('The retained post-review intent is inconsistent.');
        }

        return true;
    }

    private function assertCurrentIssue(OrbitDeliveryPreparation $preparation, OrbitIssueSnapshot $issue): void
    {
        $state = $issue->payload['state'] ?? null;

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Progress'
            || ($state['type'] ?? null) !== 'started') {
            throw new OrbitIssueContractChanged('The Orbit issue changed before plan-review result routing.');
        }
    }

    /** @param array<string, mixed> $planning */
    private function assertPlanningCandidate(
        Delivery $delivery,
        array $planning,
        VerifiedOrbitPlanningOutcome $verified,
    ): void {
        if ($verified->candidateSha !== $delivery->candidate_sha
            || $verified->candidateSha !== ($planning['candidate_sha'] ?? null)
            || $verified->artifactSha !== null
            || $verified->planContentsHash !== null) {
            throw new OrbitPlanReviewAdvancementFailed('The verified planning candidate no longer matches the review input.');
        }
    }

    private function assertReviewArtifact(Receipt $receipt, ?VerifiedOrbitPlanningArtifact $artifact): void
    {
        $result = $receipt->payload['result'] ?? null;

        if (($result === 'blocked' && $artifact !== null)
            || ($result !== 'blocked'
                && ($artifact === null
                    || $artifact->artifactSha !== ($receipt->payload['artifact_sha'] ?? null)
                    || $artifact->planContentsHash !== ($receipt->payload['plan_sha256'] ?? null)))) {
            throw new OrbitPlanReviewAdvancementFailed('The verified review artifact no longer matches its receipt.');
        }
    }

    private function commitTransition(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
        VerifiedOrbitPlanningOutcome $verified,
        ?VerifiedOrbitPlanningArtifact $reviewArtifact,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $config,
            $verified,
            $reviewArtifact,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = ProjectOrchestration::query()
                ->whereKey($delivery->project_orchestration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();

            if ($this->alreadyHandled($delivery)) {
                return;
            }

            if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitPlanReviewAdvancementFailed('The plan-review ledger changed during advancement.');
            }

            $planning = $this->planningPayload($phase);
            $this->assertPlanningCandidate($delivery, $planning, $verified);
            $this->assertReviewArtifact($receipt, $reviewArtifact);

            $result = $this->result($receipt);
            $intent = $this->nextIntent($delivery, $phase, $result);
            $phase->status = PhaseRunStatus::Completed;
            $phase->output = ['receipt_id' => $receipt->id, 'result' => $result];
            $phase->finished_at = now();
            $phase->save();

            $next = PhaseRun::query()->firstOrCreate(
                [
                    'delivery_id' => $delivery->id,
                    'phase_name' => $intent['phase'],
                    'attempt' => $intent['attempt'],
                ],
                [
                    'status' => PhaseRunStatus::Pending,
                    'input' => [
                        'plan_review_receipt_id' => $receipt->id,
                        'plan_review_receipt' => $receipt->payload,
                    ],
                ],
            );
            $idempotencyKey = IdempotencyKey::forDispatch(
                $delivery->id,
                $intent['phase'],
                $intent['attempt'],
                $intent['role'],
            )->value;
            $nextDispatch = AgentDispatch::query()->firstOrCreate(
                ['phase_run_id' => $next->id, 'agent_role' => $intent['role']],
                [
                    'idempotency_key' => $idempotencyKey,
                    'herdr_agent_name' => $intent['agent'],
                    'prompt_name' => $intent['prompt'],
                    'prompt_version' => OrbitFeatureWorkflow::nextPromptVersion($intent['prompt']),
                    'prompt_hash' => str_repeat('0', 64),
                    'status' => AgentDispatchStatus::Pending,
                ],
            );
            $expectedInput = [
                'plan_review_receipt_id' => $receipt->id,
                'plan_review_receipt' => $receipt->payload,
            ];

            if ($next->status !== PhaseRunStatus::Pending
                || $next->input !== $expectedInput
                || $next->agentDispatches()->count() !== 1
                || $nextDispatch->idempotency_key !== $idempotencyKey
                || $nextDispatch->herdr_agent_name !== $intent['agent']
                || $nextDispatch->prompt_name !== $intent['prompt']
                || ! OrbitFeatureWorkflow::supportsPromptVersion($nextDispatch->prompt_name, $nextDispatch->prompt_version)
                || $nextDispatch->prompt_hash !== str_repeat('0', 64)
                || $nextDispatch->status !== AgentDispatchStatus::Pending) {
                throw new OrbitPlanReviewAdvancementFailed('The retained post-review intent is inconsistent.');
            }

            $delivery->current_phase = $intent['phase'];
            $delivery->status = DeliveryStatus::Queued;
            $delivery->failure_details = null;
            $delivery->save();
        });
    }

    /** @return array{phase: string, attempt: int, role: string, agent: string, prompt: string} */
    private function nextIntent(Delivery $delivery, PhaseRun $review, string $result): array
    {
        $agentPrefix = strtolower((string) $delivery->external_issue_key).'-loop-';

        if ($result === 'pass') {
            return [
                'phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                'attempt' => 1,
                'role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                'agent' => $agentPrefix.'builder',
                'prompt' => 'orbit_implementation',
            ];
        }

        if ($result === 'fix' && $review->attempt === 1) {
            return [
                'phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
                'attempt' => 2,
                'role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                'agent' => $agentPrefix.'builder',
                'prompt' => 'orbit_planning_correction',
            ];
        }

        if (! in_array($result, ['fix', 'blocked'], true)) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review result cannot be routed.');
        }

        return [
            'phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
            'attempt' => 1,
            'role' => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            'agent' => $agentPrefix.'resolution-1',
            'prompt' => 'orbit_resolution',
        ];
    }

    /** @return array<string, mixed> */
    private function planningPayload(PhaseRun $phase): array
    {
        $input = $phase->input;
        $planning = is_array($input) ? ($input['planning_receipt'] ?? null) : null;

        if (! is_array($planning) || array_is_list($planning)) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review phase has malformed planning input.');
        }

        $normalized = [];

        foreach ($planning as $key => $value) {
            if (! is_string($key)) {
                throw new OrbitPlanReviewAdvancementFailed('The plan-review phase has malformed planning input.');
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function result(Receipt $receipt): string
    {
        $result = $receipt->payload['result'] ?? null;

        if (! is_string($result) || ! in_array($result, ['pass', 'fix', 'blocked'], true)) {
            throw new OrbitPlanReviewAdvancementFailed('The plan-review receipt has an invalid result.');
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitPlanReviewAdvancementFailed("The review input has an invalid {$key}.");
        }

        return $value;
    }
}
