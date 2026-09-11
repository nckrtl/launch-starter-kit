<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestReviewPublisher;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PublishedOrbitPullRequestReview;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPullRequestReviewAdvancementFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdvanceOrbitPullRequestReview
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitActiveIssueProvider $issues,
        private OrbitPullRequestReviewPublisher $pullRequests,
        private OrbitPullRequestReviewReceiptValidator $receipts,
        private OrbitPullRequestReviewSourceValidator $sources,
    ) {}

    public function handle(int $deliveryId, ?int $expectedPhaseId = null): void
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
            throw new OrbitPullRequestReviewAdvancementFailed('The delivery is not an Orbit feature workflow.');
        }

        if ($delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE) {
            if ($this->alreadyHandled($delivery, $expectedPhaseId)) {
                return;
            }

            throw new OrbitPullRequestReviewAdvancementFailed(
                'The retained pull request review transition is inconsistent.',
            );
        }

        $state = $this->reviewState($delivery, $expectedPhaseId);

        if ($state === null) {
            return;
        }

        [$phase, $dispatch, $receipt, $source] = $state;
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The Orbit project is not enabled with valid configuration.',
            );
        }

        $preparation = $this->preparations->startup($delivery);
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
            $current = $this->reviewState($delivery, $expectedPhaseId);

            if ($current === null || $current[0]->id !== $phase->id
                || $current[1]->id !== $dispatch->id
                || $current[2]->id !== $receipt->id
                || $current[3]->id !== $source->id
                || ! hash_equals($current[2]->payload_hash, $receipt->payload_hash)
                || ! hash_equals($current[3]->payload_hash, $source->payload_hash)
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || $delivery->projectOrchestration->config !== $config->toArray()) {
                throw new OrbitPullRequestReviewAdvancementFailed(
                    'The pull request review ledger changed before advancement.',
                );
            }

            $verified = $this->verifySource($config, $preparation, $source);
            $this->assertCurrentIssue(
                $preparation,
                $this->issues->fetchActive(
                    $preparation->snapshot->issueId,
                    $preparation->snapshot->issueKey,
                ),
            );
            $result = $this->string($receipt->payload, 'result');
            $published = null;

            if ($result !== 'blocked') {
                $this->markPublicationPending(
                    $delivery->id,
                    $phase->id,
                    $dispatch->id,
                    $receipt->id,
                    $receipt->payload_hash,
                    $source->id,
                    $source->payload_hash,
                    $config,
                );
                $published = $this->publish($delivery, $source, $receipt, $verified, $result);
                $this->assertCurrentIssue(
                    $preparation,
                    $this->issues->fetchActive(
                        $preparation->snapshot->issueId,
                        $preparation->snapshot->issueKey,
                    ),
                );
            }

            $this->commit(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $receipt->id,
                $source->id,
                $config,
                $verified,
                $published,
            );
        } finally {
            $reservation->release();
        }
    }

    /** @return array{PhaseRun, AgentDispatch, Receipt, Receipt}|null */
    private function reviewState(Delivery $delivery, ?int $expectedPhaseId): ?array
    {
        if ($delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::WaitingForAgent) {
            return null;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->latest('attempt')
            ->first();

        if ($phase === null || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)) {
            return null;
        }

        $dispatches = $phase->agentDispatches()->get();
        $reviewReceipts = $phase->receipts()->where('kind', 'orbit_pr_review')->get();
        $dispatch = $dispatches->first();
        $receipt = $reviewReceipts->first();
        $source = $this->sources->sourceReceipt($delivery, $phase);

        if (! in_array($phase->attempt, [1, 2], true) || $phase->status !== PhaseRunStatus::Running
            || $dispatches->count() !== 1 || $reviewReceipts->count() > 1
            || $dispatch === null
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The pull request review phase is inconsistent.',
            );
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled || $receipt === null) {
            return null;
        }

        if ($source === null || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The pull request review receipt does not match the settled dispatch.',
            );
        }

        return [$phase, $dispatch, $receipt, $source];
    }

    private function verifySource(
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        Receipt $source,
    ): VerifiedOrbitImplementationOutcome {
        $payload = $source->payload;
        $verified = $this->implementations->verifyImplementationOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $this->sha($payload, 'reviewed_candidate_sha'),
            $this->sha($payload, 'candidate_sha'),
            $this->sha($payload, 'artifact_sha'),
            $this->string($payload, 'gate_receipt_path'),
            $this->string($payload, 'pull_request_body'),
        );

        if ($verified->candidateSha !== ($payload['candidate_sha'] ?? null)
            || $verified->artifactSha !== ($payload['artifact_sha'] ?? null)
            || $verified->gateReceiptPath !== ($payload['gate_receipt_path'] ?? null)
            || $verified->pullRequestBodyHash !== ($payload['pull_request_body_sha256'] ?? null)
            || $verified->flow !== ($payload['flow'] ?? null)) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The verified implementation no longer matches its immutable receipt.',
            );
        }

        return $verified;
    }

    private function assertCurrentIssue(
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Review'
            || ($state['type'] ?? null) !== 'started'
            || ! is_string($viewerId)
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || $issue->payload['assignee'] !== null) {
            throw new OrbitIssueContractChanged(
                'The Orbit issue changed before pull request review advancement.',
            );
        }
    }

    private function publish(
        Delivery $delivery,
        Receipt $source,
        Receipt $receipt,
        VerifiedOrbitImplementationOutcome $verified,
        string $result,
    ): PublishedOrbitPullRequestReview {
        if (! in_array($result, ['approved', 'changes'], true)) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The pull request review result cannot be published.',
            );
        }

        $body = $receipt->payload['pull_request_body'] ?? null;

        return $this->pullRequests->publishReview(
            $this->pullRequestNumber($delivery),
            (string) $delivery->external_issue_key,
            $verified->candidateSha,
            $this->string($source->payload, 'pull_request_body'),
            $result,
            $this->string($receipt->payload, 'handoff'),
            is_string($body) ? $body : null,
        );
    }

    private function markPublicationPending(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        string $receiptHash,
        int $sourceId,
        string $sourceHash,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $receiptHash,
            $sourceId,
            $sourceHash,
            $config,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = ProjectOrchestration::query()
                ->whereKey($delivery->project_orchestration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $receipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $phaseId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $receipt = $receipts->firstWhere('id', $receiptId);
            $source = $receipts->firstWhere('id', $sourceId);
            $latestReview = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $phaseId);
            $phaseReceipts = $receipts->where('phase_run_id', $phaseId);

            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null || $receipt === null || $source === null
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || ! $this->configs->hydrate($project->config) instanceof OrbitProjectConfig
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $latestReview?->id !== $phase->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || ! in_array($phase->attempt, [1, 2], true)
                || $phase->status !== PhaseRunStatus::Running
                || $phase->started_at === null
                || $phase->finished_at !== null
                || ! in_array($phase->current_block, [null, 'review_publication'], true)
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->count() !== 1
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->settled_at === null
                || $receipt->phase_run_id !== $phase->id
                || ! hash_equals($receipt->payload_hash, $receiptHash)
                || ! hash_equals($source->payload_hash, $sourceHash)
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)
                || $this->sources->sourceReceipt($delivery, $phase)?->id !== $source->id) {
                throw new OrbitPullRequestReviewAdvancementFailed(
                    'The pull request review ledger changed before publication.',
                );
            }

            $phase->current_block = 'review_publication';
            $phase->save();
        });
    }

    private function commit(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        int $receiptId,
        int $sourceId,
        OrbitProjectConfig $config,
        VerifiedOrbitImplementationOutcome $verified,
        ?PublishedOrbitPullRequestReview $published,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseId,
            $dispatchId,
            $receiptId,
            $sourceId,
            $config,
            $verified,
            $published,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();

            $project = ProjectOrchestration::query()
                ->whereKey($delivery->project_orchestration_id)
                ->lockForUpdate()
                ->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $receipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $phaseId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $receipt = $receipts->firstWhere('id', $receiptId);
            $source = $receipts->firstWhere('id', $sourceId);

            $delivery->setRelation('projectOrchestration', $project);

            if ($delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE) {
                if ($this->alreadyHandled($delivery, $phaseId, $published)) {
                    return;
                }

                throw new OrbitPullRequestReviewAdvancementFailed(
                    'The published review could not be reconciled with the retained transition.',
                );
            }

            if ($phase === null || $dispatch === null || $receipt === null || $source === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || ! in_array($phase->attempt, [1, 2], true) || $phase->status !== PhaseRunStatus::Running
                || $phase->current_block !== ($published === null ? null : 'review_publication')
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $source->payload['candidate_sha'] !== $verified->candidateSha
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)
                || $this->sources->sourceReceipt($delivery, $phase)?->id !== $source->id) {
                throw new OrbitPullRequestReviewAdvancementFailed(
                    'The pull request review ledger changed during advancement.',
                );
            }

            $result = $this->string($receipt->payload, 'result');
            $publishedData = $published === null ? null : [
                'id' => $published->id,
                'reviewer_login' => $published->reviewerLogin,
                'candidate_sha' => $published->candidateSha,
                'state' => $published->state,
                'review_body_sha256' => $published->reviewBodyHash,
                'pull_request_body_sha256' => $published->pullRequestBodyHash,
            ];
            $this->assertPublication($delivery, $source, $receipt, $result, $published);

            $phase->status = PhaseRunStatus::Completed;
            $phase->current_block = null;
            $phase->output = [
                'receipt_id' => $receipt->id,
                'result' => $result,
                'published_review' => $publishedData,
            ];
            $phase->finished_at = now();
            $phase->save();

            $input = [
                'pr_review_receipt_id' => $receipt->id,
                'pr_review_receipt' => $receipt->payload,
                'implementation_receipt_id' => $source->id,
                'implementation_receipt' => $source->payload,
                'pull_request' => [
                    'number' => $delivery->pull_request_number,
                    'url' => $delivery->pull_request_url,
                    'mergeable' => true,
                ],
                'published_review' => $publishedData,
            ];

            match (true) {
                $result === 'approved' => $this->createLandingIntent($delivery, $input),
                $result === 'changes' && $phase->attempt === 1 => $this->createCorrectionIntent(
                    $delivery,
                    $source,
                    $input,
                ),
                in_array($result, ['changes', 'blocked'], true) => $this->createResolutionIntent($delivery, $input),
                default => throw new OrbitPullRequestReviewAdvancementFailed(
                    'The pull request review result cannot be routed.',
                ),
            };
        });
    }

    private function assertPublication(
        Delivery $delivery,
        Receipt $source,
        Receipt $receipt,
        string $result,
        ?PublishedOrbitPullRequestReview $published,
    ): void {
        if ($result === 'blocked') {
            if ($published !== null) {
                throw new OrbitPullRequestReviewAdvancementFailed(
                    'A blocked pull request review must not be published.',
                );
            }

            return;
        }

        $reviewBody = $result === 'approved' ? 'Approved.' : $this->string($receipt->payload, 'handoff');
        $pullRequestBody = $result === 'approved'
            ? $this->string($receipt->payload, 'pull_request_body')
            : $this->string($source->payload, 'pull_request_body');
        $expectedState = $result === 'approved' ? 'APPROVED' : 'CHANGES_REQUESTED';

        if ($published === null || $published->id < 1
            || $published->pullRequestNumber !== $delivery->pull_request_number
            || $published->reviewerLogin !== 'tom-nckrtl[bot]'
            || $published->candidateSha !== $delivery->candidate_sha
            || $published->state !== $expectedState
            || ! hash_equals($published->reviewBodyHash, hash('sha256', $reviewBody))
            || ! hash_equals($published->pullRequestBodyHash, hash('sha256', $pullRequestBody))) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The published review does not match the immutable pull request review receipt.',
            );
        }
    }

    /** @param array<string, mixed> $input */
    private function createLandingIntent(Delivery $delivery, array $input): void
    {
        $landing = PhaseRun::query()->firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::LANDING_PHASE,
                'attempt' => 1,
            ],
            ['status' => PhaseRunStatus::Pending, 'input' => $input],
        );

        if ($landing->status !== PhaseRunStatus::Pending || $landing->input !== $input
            || $landing->agentDispatches()->exists()) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The retained landing intent is inconsistent.',
            );
        }

        $delivery->current_phase = OrbitFeatureWorkflow::LANDING_PHASE;
        $delivery->status = DeliveryStatus::ReadyToMerge;
        $delivery->failure_details = null;
        $delivery->save();
    }

    /** @param array<string, mixed> $input */
    private function createCorrectionIntent(Delivery $delivery, Receipt $source, array $input): void
    {
        $sourcePhase = PhaseRun::query()->findOrFail($source->phase_run_id);
        $attempt = $sourcePhase->attempt + 1;
        $phase = PhaseRun::query()->firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                'attempt' => $attempt,
            ],
            ['status' => PhaseRunStatus::Pending, 'input' => $input],
        );
        $dispatch = $this->createDispatch(
            $delivery,
            $phase,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            strtolower((string) $delivery->external_issue_key).'-loop-builder',
            'orbit_pr_review_correction',
        );
        $this->assertIntent(
            $phase,
            $dispatch,
            $input,
            OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            strtolower((string) $delivery->external_issue_key).'-loop-builder',
            'orbit_pr_review_correction',
            'correction',
        );
        $delivery->current_phase = OrbitFeatureWorkflow::IMPLEMENTATION_PHASE;
        $delivery->status = DeliveryStatus::Queued;
        $delivery->failure_details = null;
        $delivery->save();
    }

    /** @param array<string, mixed> $input */
    private function createResolutionIntent(Delivery $delivery, array $input): void
    {
        $phase = PhaseRun::query()->firstOrCreate(
            [
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
                'attempt' => 1,
            ],
            ['status' => PhaseRunStatus::Pending, 'input' => $input],
        );
        $dispatch = $this->createDispatch(
            $delivery,
            $phase,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            strtolower((string) $delivery->external_issue_key).'-loop-resolution-1',
            'orbit_resolution',
        );
        $this->assertIntent(
            $phase,
            $dispatch,
            $input,
            OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            strtolower((string) $delivery->external_issue_key).'-loop-resolution-1',
            'orbit_resolution',
            'resolution',
        );
        $delivery->current_phase = OrbitFeatureWorkflow::RESOLUTION_PHASE;
        $delivery->status = DeliveryStatus::Queued;
        $delivery->failure_details = null;
        $delivery->save();
    }

    private function createDispatch(
        Delivery $delivery,
        PhaseRun $phase,
        string $role,
        string $agent,
        string $prompt,
    ): AgentDispatch {
        return AgentDispatch::query()->firstOrCreate(
            ['phase_run_id' => $phase->id, 'agent_role' => $role],
            [
                'idempotency_key' => IdempotencyKey::forDispatch(
                    $delivery->id,
                    $phase->phase_name,
                    $phase->attempt,
                    $role,
                )->value,
                'herdr_agent_name' => $agent,
                'prompt_name' => $prompt,
                'prompt_version' => 1,
                'prompt_hash' => str_repeat('0', 64),
                'status' => AgentDispatchStatus::Pending,
            ],
        );
    }

    /** @param array<string, mixed> $input */
    private function assertIntent(
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $input,
        string $role,
        string $agent,
        string $prompt,
        string $kind,
    ): void {
        if ($phase->status !== PhaseRunStatus::Pending || $phase->input !== $input
            || $phase->agentDispatches()->count() !== 1
            || $dispatch->agent_role !== $role
            || $dispatch->idempotency_key !== IdempotencyKey::forDispatch(
                $phase->delivery_id,
                $phase->phase_name,
                $phase->attempt,
                $role,
            )->value
            || $dispatch->herdr_agent_name !== $agent
            || $dispatch->prompt_name !== $prompt
            || $dispatch->prompt_version !== 1
            || $dispatch->prompt_hash !== str_repeat('0', 64)
            || $dispatch->status !== AgentDispatchStatus::Pending) {
            throw new OrbitPullRequestReviewAdvancementFailed(
                "The retained {$kind} intent is inconsistent.",
            );
        }
    }

    private function alreadyHandled(
        Delivery $delivery,
        ?int $expectedPhaseId,
        ?PublishedOrbitPullRequestReview $published = null,
    ): bool {
        $delivery->loadMissing('projectOrchestration');

        try {
            $config = $this->configs->hydrate($delivery->projectOrchestration->config);
        } catch (\InvalidArgumentException|ValidationException) {
            return false;
        }

        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->latest('attempt')
            ->first();

        if ($phase === null || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)
            || ! $config instanceof OrbitProjectConfig
            || ! in_array($phase->attempt, [1, 2], true) || $phase->status !== PhaseRunStatus::Completed
            || $phase->finished_at === null || $phase->current_block !== null
            || $phase->failure_code !== null || $phase->failure_message !== null
            || $phase->failure_details !== null
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            return false;
        }

        $dispatches = $phase->agentDispatches()->get();
        $reviewReceipts = $phase->receipts()->get();
        $dispatch = $dispatches->first();
        $receipt = $reviewReceipts->first();
        $source = $this->sources->sourceReceipt($delivery, $phase, true);
        $sourcePhase = $source?->phaseRun()->first();

        if ($dispatches->count() !== 1 || $reviewReceipts->count() !== 1
            || $dispatch === null || $receipt === null || $source === null || $sourcePhase === null
            || $receipt->validated_at === null
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt, true)) {
            return false;
        }

        $result = $receipt->payload['result'] ?? null;

        if (! in_array($result, ['approved', 'changes', 'blocked'], true)) {
            return false;
        }

        $publishedData = is_array($phase->output) ? ($phase->output['published_review'] ?? null) : null;

        if (! $this->publicationDataMatches($delivery, $source, $receipt, $result, $publishedData)
            || ($published !== null && $publishedData !== $this->publicationData($published))
            || $phase->output !== [
                'receipt_id' => $receipt->id,
                'result' => $result,
                'published_review' => $publishedData,
            ]) {
            return false;
        }

        $input = [
            'pr_review_receipt_id' => $receipt->id,
            'pr_review_receipt' => $receipt->payload,
            'implementation_receipt_id' => $source->id,
            'implementation_receipt' => $source->payload,
            'pull_request' => [
                'number' => $delivery->pull_request_number,
                'url' => $delivery->pull_request_url,
                'mergeable' => true,
            ],
            'published_review' => $publishedData,
        ];
        $nextPhase = match (true) {
            $result === 'approved' => OrbitFeatureWorkflow::LANDING_PHASE,
            $result === 'changes' && $phase->attempt === 1 => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
            default => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        };
        $nextAttempt = $nextPhase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            ? $sourcePhase->attempt + 1
            : 1;
        $next = $delivery->phaseRuns()
            ->where('phase_name', $nextPhase)
            ->where('attempt', $nextAttempt)
            ->first();
        $expectedStatus = $result === 'approved' ? DeliveryStatus::ReadyToMerge : DeliveryStatus::Queued;

        if ($next === null || $next->status !== PhaseRunStatus::Pending || $next->input !== $input
            || $next->current_block !== null || $next->output !== null
            || $next->failure_code !== null || $next->failure_message !== null
            || $next->failure_details !== null || $next->started_at !== null
            || $next->finished_at !== null || $next->receipts()->exists()
            || $delivery->current_phase !== $nextPhase || $delivery->status !== $expectedStatus
            || $delivery->failure_details !== null) {
            return false;
        }

        if ($result === 'approved') {
            return $next->agentDispatches()->doesntExist();
        }

        $nextDispatches = $next->agentDispatches()->get();
        $nextDispatch = $nextDispatches->first();
        $role = $nextPhase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            ? OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            : OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE;
        $agent = $nextPhase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            ? strtolower((string) $delivery->external_issue_key).'-loop-builder'
            : strtolower((string) $delivery->external_issue_key).'-loop-resolution-1';
        $prompt = $nextPhase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            ? 'orbit_pr_review_correction'
            : 'orbit_resolution';

        return $nextDispatches->count() === 1 && $nextDispatch !== null
            && $nextDispatch->agent_role === $role
            && $nextDispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                $nextPhase,
                $nextAttempt,
                $role,
            )->value
            && $nextDispatch->herdr_agent_name === $agent
            && $nextDispatch->prompt_name === $prompt
            && $nextDispatch->prompt_version === 1
            && $nextDispatch->prompt_hash === str_repeat('0', 64)
            && $nextDispatch->status === AgentDispatchStatus::Pending
            && $nextDispatch->herdr_session === null
            && $nextDispatch->herdr_workspace_id === null
            && $nextDispatch->herdr_tab_id === null
            && $nextDispatch->herdr_pane_id === null
            && $nextDispatch->herdr_terminal_id === null
            && $nextDispatch->herdr_agent_id === null
            && $nextDispatch->state_change_seq === null
            && $nextDispatch->error_code === null
            && $nextDispatch->error_message === null
            && $nextDispatch->dispatched_at === null
            && $nextDispatch->settled_at === null;
    }

    /** @return array<string, int|string> */
    private function publicationData(PublishedOrbitPullRequestReview $published): array
    {
        return [
            'id' => $published->id,
            'reviewer_login' => $published->reviewerLogin,
            'candidate_sha' => $published->candidateSha,
            'state' => $published->state,
            'review_body_sha256' => $published->reviewBodyHash,
            'pull_request_body_sha256' => $published->pullRequestBodyHash,
        ];
    }

    private function publicationDataMatches(
        Delivery $delivery,
        Receipt $source,
        Receipt $receipt,
        string $result,
        mixed $published,
    ): bool {
        if ($result === 'blocked') {
            return $published === null;
        }

        if (! is_array($published) || array_is_list($published)) {
            return false;
        }

        $reviewBody = $result === 'approved' ? 'Approved.' : ($receipt->payload['handoff'] ?? null);
        $pullRequestBody = $result === 'approved'
            ? ($receipt->payload['pull_request_body'] ?? null)
            : ($source->payload['pull_request_body'] ?? null);

        return is_int($published['id'] ?? null) && $published['id'] > 0
            && ($published['reviewer_login'] ?? null) === 'tom-nckrtl[bot]'
            && ($published['candidate_sha'] ?? null) === $delivery->candidate_sha
            && ($published['state'] ?? null) === ($result === 'approved' ? 'APPROVED' : 'CHANGES_REQUESTED')
            && is_string($reviewBody)
            && ($published['review_body_sha256'] ?? null) === hash('sha256', $reviewBody)
            && is_string($pullRequestBody)
            && ($published['pull_request_body_sha256'] ?? null) === hash('sha256', $pullRequestBody)
            && array_diff(array_keys($published), [
                'id',
                'reviewer_login',
                'candidate_sha',
                'state',
                'review_body_sha256',
                'pull_request_body_sha256',
            ]) === []
            && count($published) === 6;
    }

    private function pullRequestNumber(Delivery $delivery): int
    {
        $number = $delivery->pull_request_number;

        if (! is_int($number) || $number < 1
            || $delivery->pull_request_url !== "https://github.com/nckrtl/orbit/pull/{$number}") {
            throw new OrbitPullRequestReviewAdvancementFailed(
                'The delivery has no authoritative Orbit pull request.',
            );
        }

        return $number;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitPullRequestReviewAdvancementFailed("The receipt has an invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitPullRequestReviewAdvancementFailed("The receipt has an invalid {$key}.");
        }

        return $value;
    }
}
