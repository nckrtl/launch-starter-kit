<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PublishedOrbitPullRequest;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitImplementationAdvancementFailed;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitImplementationReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitImplementation
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitActiveIssueProvider $issues,
        private OrbitPullRequestPublisher $pullRequests,
        private OrbitImplementationReceiptValidator $receipts,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    /** Return true when GitHub mergeability needs another bounded queue attempt. */
    public function handle(int $deliveryId, ?int $expectedPhaseId = null): bool
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
            throw new OrbitImplementationAdvancementFailed('The delivery is not an Orbit feature workflow.');
        }

        if ($this->alreadyHandled($delivery)) {
            return false;
        }

        $state = $this->implementationState($delivery, $expectedPhaseId);

        if ($state === null) {
            return false;
        }

        [$phase, $dispatch, $receipt] = $state;
        $result = $receipt->payload['result'] ?? null;
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitImplementationAdvancementFailed('The Orbit project is not enabled with valid configuration.');
        }

        if ($result === 'blocked') {
            $this->commitBlocked($delivery->id, $phase->id, $dispatch->id, $receipt->id, $config);

            return false;
        }

        if ($result !== 'ready') {
            throw new OrbitImplementationAdvancementFailed('The implementation result cannot be routed.');
        }

        $preparation = $this->preparations->startup($delivery);
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

            if ($this->alreadyHandled($delivery)) {
                return false;
            }

            $state = $this->implementationState($delivery, $expectedPhaseId);

            if ($state === null || $state[0]->id !== $phase->id
                || $state[1]->id !== $dispatch->id || $state[2]->id !== $receipt->id
                || ! hash_equals($state[2]->payload_hash, $receipt->payload_hash)
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitImplementationAdvancementFailed('The implementation ledger changed before advancement.');
            }

            $issue = $this->issues->fetchActive(
                $preparation->snapshot->issueId,
                $preparation->snapshot->issueKey,
            );
            $this->assertCurrentIssue(
                $delivery,
                $preparation->snapshot,
                $issue,
                $phase->attempt > 1,
            );
            $verified = $this->implementations->verifyImplementationOutcome(
                $config,
                $preparation->worktree,
                $preparation->snapshot,
                $this->sha($receipt->payload, 'reviewed_candidate_sha'),
                $this->sha($receipt->payload, 'candidate_sha'),
                $this->sha($receipt->payload, 'artifact_sha'),
                $this->string($receipt->payload, 'gate_receipt_path'),
                $this->string($receipt->payload, 'pull_request_body'),
            );
            $this->assertVerifiedOutcome($receipt, $verified);
            $pullRequest = $this->pullRequests->publish(
                $preparation->snapshot->issueKey,
                $this->issueTitle($issue),
                $verified->candidateSha,
                $this->string($receipt->payload, 'pull_request_body'),
            );

            return $this->commitPublished(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $config,
                $verified,
                $pullRequest,
            );
        } finally {
            $reservation->release();
        }
    }

    /** @return array{PhaseRun, AgentDispatch, Receipt}|null */
    private function implementationState(Delivery $delivery, ?int $expectedPhaseId = null): ?array
    {
        if ($delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return null;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
            ->latest('attempt')
            ->first();

        if ($phase === null || $phase->attempt < 1) {
            throw new OrbitImplementationAdvancementFailed('The implementation phase is not running.');
        }

        if ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId) {
            return null;
        }

        if ($phase->status !== PhaseRunStatus::Running) {
            throw new OrbitImplementationAdvancementFailed('The implementation phase is not running.');
        }

        $dispatches = $phase->agentDispatches()->get();
        $receipts = $phase->receipts()->where('kind', 'orbit_implementation')->get();

        if ($dispatches->count() !== 1) {
            throw new OrbitImplementationAdvancementFailed('The implementation phase must retain exactly one dispatch.');
        }

        $dispatch = $dispatches->firstOrFail();
        $receipt = $receipts->first();

        if ($dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE) {
            throw new OrbitImplementationAdvancementFailed('The implementation dispatch identity is inconsistent.');
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled || $receipt === null) {
            return null;
        }

        if ($receipts->count() !== 1
            || $delivery->candidate_sha !== $this->expectedActiveCandidate($phase, $receipt)
            || ! $this->matchesReceipt($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitImplementationAdvancementFailed(
                'The implementation receipt does not match the settled dispatch.',
            );
        }

        return [$phase, $dispatch, $receipt];
    }

    private function assertCurrentIssue(
        Delivery $delivery,
        PreparedIssueSnapshot $expected,
        OrbitIssueSnapshot $issue,
        bool $allowPullRequestReviewState,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $validState = is_array($state)
            && ($state['type'] ?? null) === 'started'
            && ($allowPullRequestReviewState
                ? in_array($state['name'] ?? null, ['In Progress', 'In Review'], true)
                : ($state['name'] ?? null) === 'In Progress');

        if ($issue->issueId !== $expected->issueId
            || $issue->issueKey !== $expected->issueKey
            || ! $this->snapshots->matchesExpectedContract(
                $issue,
                $expected->contractHash,
                $delivery->pull_request_url,
            )
            || ! $validState) {
            throw new OrbitIssueContractChanged('The Orbit issue changed before implementation advancement.');
        }
    }

    private function issueTitle(OrbitIssueSnapshot $issue): string
    {
        $title = $issue->payload['title'] ?? null;

        if (! is_string($title) || trim($title) === '') {
            throw new OrbitImplementationAdvancementFailed('The Orbit issue has no valid pull request title.');
        }

        return $title;
    }

    private function assertVerifiedOutcome(Receipt $receipt, VerifiedOrbitImplementationOutcome $verified): void
    {
        $payload = $receipt->payload;

        if ($verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || $verified->artifactSha !== ($payload['artifact_sha'] ?? null)
            || $verified->gateReceiptPath !== ($payload['gate_receipt_path'] ?? null)
            || $verified->pullRequestBodyHash !== ($payload['pull_request_body_sha256'] ?? null)
            || $verified->flow !== ($payload['flow'] ?? null)) {
            throw new OrbitImplementationAdvancementFailed(
                'The verified implementation outcome does not match its receipt.',
            );
        }
    }

    private function commitBlocked(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $dispatchId, $receiptId, $config): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();

            if ($this->alreadyHandled($delivery)) {
                return;
            }

            $project = ProjectOrchestration::query()
                ->whereKey($delivery->project_orchestration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $latestPhase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->first();

            if ($project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $phase->delivery_id !== $delivery->id
                || $latestPhase?->id !== $phase->id
                || $phase->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $receipt->phase_run_id !== $phase->id
                || ($receipt->payload['result'] ?? null) !== 'blocked'
                || $delivery->candidate_sha !== $this->expectedActiveCandidate($phase, $receipt)
                || ! $this->matchesReceipt($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitImplementationAdvancementFailed('The blocked implementation ledger changed.');
            }

            $phase->status = PhaseRunStatus::Completed;
            $phase->output = ['receipt_id' => $receipt->id, 'result' => 'blocked'];
            $phase->finished_at = now();
            $phase->save();
            $this->createNextIntent(
                $delivery,
                $receipt,
                OrbitFeatureWorkflow::RESOLUTION_PHASE,
                $this->nextResolutionAttempt($delivery),
                OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
                strtolower((string) $delivery->external_issue_key).'-loop-resolution-'.
                    $this->nextResolutionAttempt($delivery),
                'orbit_resolution',
                null,
            );
        });
    }

    private function commitPublished(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        OrbitProjectConfig $config,
        VerifiedOrbitImplementationOutcome $verified,
        PublishedOrbitPullRequest $pullRequest,
    ): bool {
        return DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $config,
            $verified,
            $pullRequest,
        ): bool {
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
            $latestPhase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->first();

            if ($project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $phase->delivery_id !== $delivery->id
                || $latestPhase?->id !== $phase->id
                || $phase->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $receipt->phase_run_id !== $phase->id
                || ($receipt->payload['result'] ?? null) !== 'ready'
                || $delivery->candidate_sha !== $this->expectedActiveCandidate($phase, $receipt)
                || ! $this->matchesReceipt($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitImplementationAdvancementFailed('The implementation ledger changed during advancement.');
            }

            $this->assertVerifiedOutcome($receipt, $verified);

            if ($pullRequest->candidateSha !== $verified->candidateSha
                || $pullRequest->bodyHash !== $verified->pullRequestBodyHash
                || $pullRequest->number < 1
                || $pullRequest->url !== "https://github.com/nckrtl/orbit/pull/{$pullRequest->number}"
                || ($phase->attempt > 1 && ($delivery->pull_request_number !== $pullRequest->number
                    || $delivery->pull_request_url !== $pullRequest->url))) {
                throw new OrbitImplementationAdvancementFailed(
                    'The published pull request does not match the implementation receipt.',
                );
            }

            $delivery->candidate_sha = $verified->candidateSha;
            $delivery->pull_request_number = $pullRequest->number;
            $delivery->pull_request_url = $pullRequest->url;

            if ($pullRequest->mergeable === null) {
                $phase->current_block = 'mergeability';
                $phase->save();
                $delivery->failure_details = [
                    'code' => 'implementation_mergeability_pending',
                    'phase_run_id' => $phase->id,
                    'receipt_id' => $receipt->id,
                    'pull_request_number' => $pullRequest->number,
                    'pull_request_url' => $pullRequest->url,
                ];
                $delivery->save();

                return true;
            }

            $phase->status = PhaseRunStatus::Completed;
            $phase->current_block = null;
            $phase->output = [
                'receipt_id' => $receipt->id,
                'result' => 'ready',
                'pull_request_number' => $pullRequest->number,
                'pull_request_url' => $pullRequest->url,
                'mergeable' => $pullRequest->mergeable,
            ];
            $phase->finished_at = now();
            $phase->save();

            if ($pullRequest->mergeable) {
                $reviewAttempt = $this->pullRequestReviewAttempt($phase);
                $this->createNextIntent(
                    $delivery,
                    $receipt,
                    OrbitFeatureWorkflow::PR_REVIEW_PHASE,
                    $reviewAttempt,
                    OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
                    strtolower((string) $delivery->external_issue_key).'-loop-pr-review-'.$reviewAttempt,
                    'orbit_pr_review',
                    $pullRequest,
                );
            } elseif ($phase->attempt === 1) {
                $this->createNextIntent(
                    $delivery,
                    $receipt,
                    OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                    2,
                    OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                    strtolower((string) $delivery->external_issue_key).'-loop-builder',
                    'orbit_implementation_correction',
                    $pullRequest,
                );
            } else {
                $this->createNextIntent(
                    $delivery,
                    $receipt,
                    OrbitFeatureWorkflow::RESOLUTION_PHASE,
                    $this->nextResolutionAttempt($delivery),
                    OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
                    strtolower((string) $delivery->external_issue_key).'-loop-resolution-'.
                        $this->nextResolutionAttempt($delivery),
                    'orbit_resolution',
                    $pullRequest,
                );
            }

            return false;
        });
    }

    private function createNextIntent(
        Delivery $delivery,
        Receipt $receipt,
        string $phaseName,
        int $attempt,
        string $role,
        string $agent,
        string $prompt,
        ?PublishedOrbitPullRequest $pullRequest,
    ): void {
        $input = [
            'implementation_receipt_id' => $receipt->id,
            'implementation_receipt' => $receipt->payload,
            'pull_request' => $pullRequest === null ? null : [
                'number' => $pullRequest->number,
                'url' => $pullRequest->url,
                'mergeable' => $pullRequest->mergeable,
            ],
        ];
        $next = PhaseRun::query()->firstOrCreate(
            ['delivery_id' => $delivery->id, 'phase_name' => $phaseName, 'attempt' => $attempt],
            ['status' => PhaseRunStatus::Pending, 'input' => $input],
        );
        $idempotencyKey = IdempotencyKey::forDispatch($delivery->id, $phaseName, $attempt, $role)->value;
        $nextDispatch = AgentDispatch::query()->firstOrCreate(
            ['phase_run_id' => $next->id, 'agent_role' => $role],
            [
                'idempotency_key' => $idempotencyKey,
                'herdr_agent_name' => $agent,
                'prompt_name' => $prompt,
                'prompt_version' => OrbitFeatureWorkflow::nextPromptVersion($prompt),
                'prompt_hash' => str_repeat('0', 64),
                'status' => AgentDispatchStatus::Pending,
            ],
        );

        if ($next->status !== PhaseRunStatus::Pending
            || $next->input !== $input
            || $next->agentDispatches()->count() !== 1
            || $nextDispatch->idempotency_key !== $idempotencyKey
            || $nextDispatch->herdr_agent_name !== $agent
            || $nextDispatch->prompt_name !== $prompt
            || ! OrbitFeatureWorkflow::supportsPromptVersion($nextDispatch->prompt_name, $nextDispatch->prompt_version)
            || $nextDispatch->prompt_hash !== str_repeat('0', 64)
            || $nextDispatch->status !== AgentDispatchStatus::Pending) {
            throw new OrbitImplementationAdvancementFailed('The retained post-implementation intent is inconsistent.');
        }

        $delivery->current_phase = $phaseName;
        $delivery->status = DeliveryStatus::Queued;
        $delivery->failure_details = null;
        $delivery->save();
    }

    private function alreadyHandled(Delivery $delivery): bool
    {
        if ($delivery->status !== DeliveryStatus::Queued
            || ! in_array($delivery->current_phase, [
                OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                OrbitFeatureWorkflow::PR_REVIEW_PHASE,
                OrbitFeatureWorkflow::RESOLUTION_PHASE,
            ], true)) {
            return false;
        }

        $next = $delivery->phaseRuns()
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->first();

        if ($next === null || ! is_array($next->input)
            || ! array_key_exists('implementation_receipt_id', $next->input)) {
            return false;
        }

        $nextInput = $next->input;
        $receiptId = $nextInput['implementation_receipt_id'] ?? null;
        $receipt = is_int($receiptId) ? Receipt::query()->find($receiptId) : null;
        $phase = $receipt === null ? null : PhaseRun::query()->find($receipt->phase_run_id);
        $project = $delivery->projectOrchestration()->first();

        if ($receipt === null || $phase === null
            || $project?->state !== ProjectOrchestrationState::Enabled
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $phase->attempt < 1
            || ($delivery->current_phase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                && $next->attempt !== $phase->attempt + 1)
            || ($delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                && $next->attempt !== $this->pullRequestReviewAttempt($phase))
            || $phase->status !== PhaseRunStatus::Completed || $phase->finished_at === null) {
            throw new OrbitImplementationAdvancementFailed('The retained implementation transition is inconsistent.');
        }

        $dispatches = $phase->agentDispatches()->get();
        $receipts = $phase->receipts()->where('kind', 'orbit_implementation')->get();
        $dispatch = $dispatches->first();
        $storedReceipt = $receipts->first();

        if ($dispatches->count() !== 1 || $receipts->count() !== 1
            || $dispatch === null || $storedReceipt?->id !== $receipt->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || ! $this->matchesReceipt($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitImplementationAdvancementFailed('The retained implementation transition is inconsistent.');
        }

        $result = $receipt->payload['result'] ?? null;
        $mergeable = is_array($phase->output) ? ($phase->output['mergeable'] ?? null) : null;
        $validRoute = ($delivery->current_phase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                && $result === 'ready'
                && $phase->attempt === 1
                && $mergeable === false)
            || ($delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                && $result === 'ready'
                && $mergeable === true)
            || ($delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
                && ($result === 'blocked'
                    || ($result === 'ready' && $phase->attempt > 1 && $mergeable === false)));

        if (! $validRoute) {
            throw new OrbitImplementationAdvancementFailed('The retained implementation route is inconsistent.');
        }

        $nextDispatches = $next->agentDispatches()->get();
        $nextDispatch = $nextDispatches->first();
        $expectedPullRequest = $result === 'blocked' ? null : [
            'number' => $delivery->pull_request_number,
            'url' => $delivery->pull_request_url,
            'mergeable' => $mergeable,
        ];
        $expectedInput = [
            'implementation_receipt_id' => $receipt->id,
            'implementation_receipt' => $receipt->payload,
            'pull_request' => $expectedPullRequest,
        ];
        $expectedRole = match ($delivery->current_phase) {
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            default => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
        };
        $expectedAgent = match ($delivery->current_phase) {
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => strtolower((string) $delivery->external_issue_key).'-loop-builder',
            OrbitFeatureWorkflow::PR_REVIEW_PHASE => strtolower((string) $delivery->external_issue_key).'-loop-pr-review-'.$next->attempt,
            default => strtolower((string) $delivery->external_issue_key).'-loop-resolution-'.$next->attempt,
        };
        $expectedPrompt = match ($delivery->current_phase) {
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => 'orbit_implementation_correction',
            OrbitFeatureWorkflow::PR_REVIEW_PHASE => 'orbit_pr_review',
            default => 'orbit_resolution',
        };
        $expectedOutput = $result === 'blocked'
            ? ['receipt_id' => $receipt->id, 'result' => 'blocked']
            : [
                'receipt_id' => $receipt->id,
                'result' => 'ready',
                'pull_request_number' => $delivery->pull_request_number,
                'pull_request_url' => $delivery->pull_request_url,
                'mergeable' => $mergeable,
            ];
        $expectedCandidate = $result === 'blocked'
            ? ($receipt->payload['reviewed_candidate_sha'] ?? null)
            : ($receipt->payload['candidate_sha'] ?? null);
        $hasExactPullRequest = $result === 'blocked' && $phase->attempt === 1
            ? $delivery->pull_request_number === null && $delivery->pull_request_url === null
            : is_int($delivery->pull_request_number)
                && $delivery->pull_request_number > 0
                && $delivery->pull_request_url === "https://github.com/nckrtl/orbit/pull/{$delivery->pull_request_number}";

        if ($delivery->candidate_sha !== $expectedCandidate
            || ! $hasExactPullRequest
            || $phase->output !== $expectedOutput
            || $next->status !== PhaseRunStatus::Pending
            || $next->input !== $expectedInput
            || $nextDispatches->count() !== 1 || $nextDispatch === null
            || $nextDispatch->agent_role !== $expectedRole
            || $nextDispatch->idempotency_key !== IdempotencyKey::forDispatch(
                $delivery->id,
                $next->phase_name,
                $next->attempt,
                $expectedRole,
            )->value
            || $nextDispatch->herdr_agent_name !== $expectedAgent
            || $nextDispatch->prompt_name !== $expectedPrompt
            || ! OrbitFeatureWorkflow::supportsPromptVersion($nextDispatch->prompt_name, $nextDispatch->prompt_version)
            || $nextDispatch->prompt_hash !== str_repeat('0', 64)
            || $nextDispatch->status !== AgentDispatchStatus::Pending) {
            throw new OrbitImplementationAdvancementFailed('The retained post-implementation intent is inconsistent.');
        }

        return true;
    }

    private function matchesReceipt(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        if ($phase->attempt === 1) {
            return $this->receipts->matches($delivery, $phase, $dispatch, $receipt);
        }

        $input = $phase->input;
        $source = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $sourceCandidate = is_array($source) ? ($source['candidate_sha'] ?? null) : null;

        if (! is_string($sourceCandidate)) {
            return false;
        }

        $sourceDelivery = clone $delivery;
        $sourceDelivery->candidate_sha = $sourceCandidate;

        return $this->receipts->matches($sourceDelivery, $phase, $dispatch, $receipt);
    }

    private function expectedActiveCandidate(PhaseRun $phase, Receipt $receipt): mixed
    {
        if ($phase->current_block === 'mergeability') {
            return $receipt->payload['candidate_sha'] ?? null;
        }

        if ($phase->attempt === 1) {
            return $receipt->payload['reviewed_candidate_sha'] ?? null;
        }

        $input = $phase->input;
        $source = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;

        return is_array($source) ? ($source['candidate_sha'] ?? null) : null;
    }

    private function pullRequestReviewAttempt(PhaseRun $implementation): int
    {
        $input = $implementation->input;
        $review = is_array($input) ? ($input['pr_review_receipt'] ?? null) : null;
        $reviewAttempt = is_array($review) ? ($review['attempt'] ?? null) : null;

        if (is_int($reviewAttempt) && $reviewAttempt >= 1) {
            return $reviewAttempt + 1;
        }

        $failedReviewAttempt = PhaseRun::query()
            ->where('delivery_id', $implementation->delivery_id)
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->where('status', PhaseRunStatus::Failed)
            ->where('failure_code', 'pr_review_mergeability_changed')
            ->latest('attempt')
            ->value('attempt');

        return is_int($failedReviewAttempt) ? $failedReviewAttempt + 1 : 1;
    }

    private function nextResolutionAttempt(Delivery $delivery): int
    {
        $latest = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
            ->max('attempt');

        return is_int($latest) ? $latest + 1 : 1;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitImplementationAdvancementFailed("The implementation receipt has an invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitImplementationAdvancementFailed("The implementation receipt has an invalid {$key}.");
        }

        return $value;
    }
}
