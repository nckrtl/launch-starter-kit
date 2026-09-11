<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanningAdvancementFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanningReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitPlanning
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private OrbitPlanningReceiptValidator $planningReceipts,
    ) {}

    public function handle(int $deliveryId): bool
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if (! $this->isOrbitDelivery($delivery)) {
            throw new OrbitPlanningAdvancementFailed('The delivery is not an Orbit feature workflow.');
        }

        if ($this->alreadyHandled($delivery)) {
            return false;
        }

        $state = $this->planningState($delivery);

        if ($state === null) {
            return false;
        }

        [$phase, $dispatch, $receipt] = $state;
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitPlanningAdvancementFailed('The Orbit project is not enabled with valid configuration.');
        }

        $preparation = $this->preparations->handle($delivery);
        $payload = $receipt->payload;
        $candidateSha = $this->sha($payload, 'candidate_sha');
        $artifactSha = ($payload['result'] ?? null) === 'ready'
            ? $this->sha($payload, 'artifact_sha')
            : null;
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

            if ($this->alreadyHandled($delivery)) {
                return false;
            }

            if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitPlanningAdvancementFailed('The Orbit project changed before planning advancement.');
            }

            $state = $this->planningState($delivery);

            if ($state === null || $state[0]->id !== $phase->id
                || $state[1]->id !== $dispatch->id || $state[2]->id !== $receipt->id
                || ! hash_equals($state[2]->payload_hash, $receipt->payload_hash)) {
                throw new OrbitPlanningAdvancementFailed('The planning ledger changed before advancement.');
            }

            $currentIssue = $this->issues->fetch(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue($preparation->snapshot, $currentIssue);
            $verified = $this->repository->verifyPlanningOutcome(
                $config,
                $preparation->worktree,
                $preparation->snapshot,
                $candidateSha,
                $artifactSha,
            );
            $this->assertVerifiedOutcome($receipt, $verified);

            return $this->commitTransition(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
                $verified,
            );
        } finally {
            $reservation->release();
        }
    }

    private function isOrbitDelivery(Delivery $delivery): bool
    {
        return $delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION;
    }

    private function alreadyHandled(Delivery $delivery): bool
    {
        $isReview = $delivery->current_phase === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
            && $delivery->status === DeliveryStatus::Queued;
        $isBlocked = $delivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
            && $delivery->status === DeliveryStatus::Blocked
            && ($delivery->failure_details['code'] ?? null) === 'planning_blocked';

        if (! $isReview && ! $isBlocked) {
            return false;
        }

        $planning = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 1)
            ->first();

        if ($planning === null) {
            throw new OrbitPlanningAdvancementFailed('The retained planning transition has no planning phase.');
        }

        $planningDispatches = $planning->agentDispatches()->get();
        $planningReceipts = $planning->receipts()->where('kind', 'orbit_planning')->get();

        if ($planningDispatches->count() !== 1 || $planningReceipts->count() !== 1) {
            throw new OrbitPlanningAdvancementFailed('The retained planning transition has an inconsistent ledger.');
        }

        $planningDispatch = $planningDispatches->firstOrFail();
        $receipt = $planningReceipts->firstOrFail();
        $result = $receipt->payload['result'] ?? null;

        if ($planningDispatch->status !== AgentDispatchStatus::Settled
            || ! $this->planningReceipts->matches($delivery, $planning, $planningDispatch, $receipt)
            || $planning->finished_at === null
            || $planning->output !== ['receipt_id' => $receipt->id, 'result' => $result]
            || $delivery->candidate_sha !== $receipt->payload['candidate_sha']) {
            throw new OrbitPlanningAdvancementFailed('The retained planning transition is inconsistent.');
        }

        if ($isBlocked) {
            $handoff = $receipt->payload['handoff'] ?? null;
            $expectedFailure = [
                'code' => 'planning_blocked',
                'phase_run_id' => $planning->id,
                'dispatch_id' => $planningDispatch->id,
                'receipt_id' => $receipt->id,
                'handoff' => $handoff,
            ];

            if ($result !== 'blocked'
                || ! is_string($handoff)
                || $planning->status !== PhaseRunStatus::Failed
                || $planning->failure_code !== 'planning_blocked'
                || $planning->failure_message !== $handoff
                || $delivery->failure_details !== $expectedFailure
                || $delivery->phaseRuns()->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)->exists()) {
                throw new OrbitPlanningAdvancementFailed('The retained blocked planning transition is inconsistent.');
            }

            return true;
        }

        $review = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
            ->where('attempt', 1)
            ->first();

        if ($review === null) {
            throw new OrbitPlanningAdvancementFailed('The retained plan-review transition has no review phase.');
        }

        $reviewDispatches = $review->agentDispatches()->get();
        $reviewDispatch = $reviewDispatches->first();
        $expectedKey = IdempotencyKey::forDispatch(
            $delivery->id,
            $review->phase_name,
            $review->attempt,
            OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
        )->value;
        $expectedName = strtolower((string) $delivery->external_issue_key).'-loop-plan-review';

        if ($result !== 'ready'
            || $planning->status !== PhaseRunStatus::Completed
            || $review->status !== PhaseRunStatus::Pending
            || $review->input !== [
                'planning_receipt_id' => $receipt->id,
                'planning_receipt' => $receipt->payload,
            ]
            || $reviewDispatches->count() !== 1
            || $reviewDispatch?->agent_role !== OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE
            || $reviewDispatch->status !== AgentDispatchStatus::Pending
            || $reviewDispatch->idempotency_key !== $expectedKey
            || $reviewDispatch->herdr_agent_name !== $expectedName
            || $reviewDispatch->prompt_name !== 'orbit_plan_review'
            || $reviewDispatch->prompt_version !== 1
            || $reviewDispatch->prompt_hash !== str_repeat('0', 64)) {
            throw new OrbitPlanningAdvancementFailed('The retained plan-review transition is inconsistent.');
        }

        return true;
    }

    /** @return array{PhaseRun, AgentDispatch, Receipt}|null */
    private function planningState(Delivery $delivery): ?array
    {
        if ($delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return null;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 1)
            ->first();

        if ($phase === null || $phase->status !== PhaseRunStatus::Running) {
            throw new OrbitPlanningAdvancementFailed('The planning phase is not running.');
        }

        $dispatches = $phase->agentDispatches()->get();
        $receipts = $phase->receipts()->where('kind', 'orbit_planning')->get();

        if ($dispatches->count() !== 1) {
            throw new OrbitPlanningAdvancementFailed('The planning phase must retain exactly one dispatch.');
        }

        $dispatch = $dispatches->firstOrFail();
        $receipt = $receipts->first();

        if ($dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE) {
            throw new OrbitPlanningAdvancementFailed('The planning dispatch identity is inconsistent.');
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled || $receipt === null) {
            return null;
        }

        if ($receipts->count() !== 1 || ! $this->planningReceipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitPlanningAdvancementFailed('The planning receipt does not match the settled dispatch.');
        }

        return [$phase, $dispatch, $receipt];
    }

    private function assertCurrentIssue(PreparedIssueSnapshot $expected, OrbitIssueSnapshot $issue): void
    {
        $state = $issue->payload['state'] ?? null;

        if ($issue->issueId !== $expected->issueId
            || $issue->issueKey !== $expected->issueKey
            || ! hash_equals($expected->contractHash, $issue->contractHash)
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Progress'
            || ($state['type'] ?? null) !== 'started') {
            throw new OrbitIssueContractChanged('The Orbit issue changed before plan-review advancement.');
        }
    }

    private function assertVerifiedOutcome(Receipt $receipt, VerifiedOrbitPlanningOutcome $verified): void
    {
        $payload = $receipt->payload;
        $ready = ($payload['result'] ?? null) === 'ready';

        if ($verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || ($ready && ($verified->artifactSha !== ($payload['artifact_sha'] ?? null)
                || $verified->planContentsHash !== ($payload['plan_sha256'] ?? null)))
            || (! $ready && ($verified->artifactSha !== null || $verified->planContentsHash !== null))) {
            throw new OrbitPlanningAdvancementFailed('The verified planning outcome does not match its receipt.');
        }
    }

    private function commitTransition(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
        VerifiedOrbitPlanningOutcome $verified,
    ): bool {
        return DB::transaction(function () use ($deliveryId, $phaseId, $dispatchId, $receiptId, $config, $verified): bool {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();

            if ($this->alreadyHandled($delivery)) {
                return false;
            }

            $project = ProjectOrchestration::query()
                ->whereKey($delivery->project_orchestration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();

            if ($project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $receipt->phase_run_id !== $phase->id
                || $receipt->kind !== 'orbit_planning'
                || ! $this->planningReceipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitPlanningAdvancementFailed('The planning ledger changed during advancement.');
            }

            $this->assertVerifiedOutcome($receipt, $verified);

            $result = $receipt->payload['result'] ?? null;
            $phase->output = ['receipt_id' => $receipt->id, 'result' => $result];
            $phase->finished_at = now();

            if ($result === 'blocked') {
                $handoff = $receipt->payload['handoff'] ?? null;

                if (! is_string($handoff) || trim($handoff) === '') {
                    throw new OrbitPlanningAdvancementFailed('The blocked planning receipt has no actionable handoff.');
                }

                $phase->status = PhaseRunStatus::Failed;
                $phase->failure_code = 'planning_blocked';
                $phase->failure_message = $handoff;
                $phase->save();

                $delivery->status = DeliveryStatus::Blocked;
                $delivery->candidate_sha = $verified->candidateSha;
                $delivery->failure_details = [
                    'code' => 'planning_blocked',
                    'phase_run_id' => $phase->id,
                    'dispatch_id' => $dispatch->id,
                    'receipt_id' => $receipt->id,
                    'handoff' => $handoff,
                ];
                $delivery->save();

                return false;
            }

            if ($result !== 'ready' || $verified->artifactSha === null || $verified->planContentsHash === null) {
                throw new OrbitPlanningAdvancementFailed('The planning receipt result cannot advance to review.');
            }

            $phase->status = PhaseRunStatus::Completed;
            $phase->save();

            $review = PhaseRun::query()->firstOrCreate(
                [
                    'delivery_id' => $delivery->id,
                    'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
                    'attempt' => 1,
                ],
                [
                    'status' => PhaseRunStatus::Pending,
                    'input' => [
                        'planning_receipt_id' => $receipt->id,
                        'planning_receipt' => $receipt->payload,
                    ],
                ],
            );
            $idempotencyKey = IdempotencyKey::forDispatch(
                $delivery->id,
                $review->phase_name,
                $review->attempt,
                OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
            )->value;
            $reviewDispatch = AgentDispatch::query()->firstOrCreate(
                [
                    'phase_run_id' => $review->id,
                    'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
                ],
                [
                    'idempotency_key' => $idempotencyKey,
                    'herdr_agent_name' => strtolower((string) $delivery->external_issue_key).'-loop-plan-review',
                    'prompt_name' => 'orbit_plan_review',
                    'prompt_version' => 1,
                    'prompt_hash' => str_repeat('0', 64),
                    'status' => AgentDispatchStatus::Pending,
                ],
            );

            if ($review->status !== PhaseRunStatus::Pending
                || $review->input !== [
                    'planning_receipt_id' => $receipt->id,
                    'planning_receipt' => $receipt->payload,
                ]
                || $reviewDispatch->idempotency_key !== $idempotencyKey
                || $reviewDispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-plan-review'
                || $reviewDispatch->prompt_name !== 'orbit_plan_review'
                || $reviewDispatch->prompt_version !== 1
                || $reviewDispatch->prompt_hash !== str_repeat('0', 64)
                || $review->agentDispatches()->count() !== 1
                || $reviewDispatch->status !== AgentDispatchStatus::Pending) {
                throw new OrbitPlanningAdvancementFailed('The retained plan-review intent is inconsistent.');
            }

            $delivery->current_phase = OrbitFeatureWorkflow::PLAN_REVIEW_PHASE;
            $delivery->candidate_sha = $verified->candidateSha;
            $delivery->status = DeliveryStatus::Queued;
            $delivery->failure_details = null;
            $delivery->save();

            return true;
        });
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitPlanningAdvancementFailed("The planning receipt has an invalid {$key}.");
        }

        return $value;
    }
}
