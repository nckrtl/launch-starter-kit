<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitResolutionDispatchFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanResolutionReceiptValidator;
use App\Delivery\Workflow\OrbitResolutionReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class DispatchOrbitPullRequestResolution
{
    private const string INTERRUPTED_BEFORE_PROMPT_MESSAGE =
        'The resolution ledger changed before external mutation.';

    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitImplementationRepository $implementations,
        private OrbitActiveIssueProvider $issues,
        private OrbitPullRequestInspector $pullRequests,
        private HerdrRuntime $herdr,
        private OrbitFeatureWorkflow $workflow,
        private OrbitResolutionReceiptValidator $receipts,
        private OrbitPlanResolutionReceiptValidator $planReceipts,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    public function handle(int $deliveryId, int $phaseRunId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitResolutionDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitResolutionDispatchFailed('The Orbit project does not use Commander\'s active Herdr session.');
        }

        $preparation = $this->preparations->startup($delivery);

        if ($this->isInterruptedBeforePrompt($delivery, $phaseRunId)) {
            return $this->recoverInterruptedBeforePrompt($delivery, $config, $preparation);
        }

        [$delivery, $phase, $dispatch, $prompt] = $this->prepare(
            $deliveryId,
            $phaseRunId,
            $config,
        );

        if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            [$delivery, $phase, $dispatch] = $this->recheckBeforeMutation(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $config,
            );

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $this->verifyExternalState($delivery, $config, $preparation, $phase);

            if (! AgentDispatch::query()
                ->whereKey($dispatch->id)
                ->where('status', AgentDispatchStatus::Pending)
                ->update([
                    'status' => AgentDispatchStatus::Starting,
                    'error_code' => 'resolution_dispatch_starting',
                    'error_message' => null,
                ])) {
                throw new OrbitResolutionDispatchFailed('Another process already claimed the Orbit resolution dispatch.');
            }

            $opened = $this->herdr->openWorktree(
                $config->repository,
                $preparation->worktree->path,
                $preparation->snapshot->issueKey,
            );
            $pane = $this->herdr->splitPane($opened->paneId, $preparation->worktree->path);

            if ($pane->workspaceId !== $opened->workspaceId || $pane->tabId !== $opened->tabId
                || ! $this->independentFromPriorAgents(
                    $delivery,
                    $dispatch->id,
                    $pane,
                    (string) $dispatch->herdr_agent_name,
                )) {
                throw new OrbitResolutionDispatchFailed(
                    'Herdr returned a resolution pane that is not independent from prior delivery agents.',
                );
            }

            $dispatch->refresh()->forceFill([
                'herdr_session' => $config->herdrSession,
                'herdr_workspace_id' => $pane->workspaceId,
                'herdr_tab_id' => $pane->tabId,
                'herdr_pane_id' => $pane->paneId,
                'herdr_terminal_id' => $pane->terminalId,
                'error_code' => 'herdr_agent_starting',
                'error_message' => null,
            ])->save();

            $started = $this->startAgent($delivery, $dispatch, $pane);
            $dispatch->refresh()->forceFill([
                'herdr_workspace_id' => $started->workspaceId,
                'herdr_tab_id' => $started->tabId,
                'herdr_pane_id' => $started->paneId,
                'herdr_terminal_id' => $started->terminalId,
                'herdr_agent_id' => $started->agentId,
                'herdr_agent_name' => $started->agentName,
                'state_change_seq' => $started->stateChangeSeq,
                'dispatched_at' => now(),
                'error_code' => 'resolution_final_verification',
                'error_message' => null,
            ])->save();

            $freshDelivery = Delivery::query()->with('projectOrchestration')->findOrFail($delivery->id);
            $this->verifyExternalState($freshDelivery, $config, $preparation, $phase);
            $this->markPromptAttempted($delivery->id, $phase->id, $dispatch->id, $config);
            $prompted = $this->herdr->promptAgent($started->agentName, $prompt);

            if (! $this->sameAgent($prompted, $dispatch->refresh())) {
                throw new OrbitResolutionDispatchFailed('Herdr prompted an agent outside the recorded resolution dispatch.');
            }

            DB::transaction(function () use ($delivery, $phase, $dispatch, $prompted): void {
                $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                $lockedPhase = PhaseRun::query()->whereKey($phase->id)->lockForUpdate()->firstOrFail();
                $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();

                if ($lockedDelivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                    || ! in_array($lockedDelivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                    || $lockedPhase->status !== PhaseRunStatus::Running
                    || $lockedDispatch->status !== AgentDispatchStatus::Starting
                    || $lockedDispatch->error_code !== 'herdr_prompt_attempted') {
                    throw new OrbitResolutionDispatchFailed('The resolution dispatch changed while its prompt was submitted.');
                }

                $lockedDispatch->status = AgentDispatchStatus::Waiting;
                $lockedDispatch->herdr_agent_id = $prompted->agentId ?? $lockedDispatch->herdr_agent_id;
                $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
                $lockedDispatch->error_code = null;
                $lockedDispatch->error_message = null;
                $lockedDispatch->save();

                $lockedDelivery->status = DeliveryStatus::WaitingForAgent;
                $lockedDelivery->failure_details = null;
                $lockedDelivery->save();
            });
        } catch (Throwable $exception) {
            $this->markBlocked($delivery->id, $dispatch->id, $exception);

            if ($exception instanceof OrbitResolutionDispatchFailed) {
                throw $exception;
            }

            throw new OrbitResolutionDispatchFailed('The Herdr resolution dispatch failed.', 0, $exception);
        } finally {
            $reservation->release();
        }

        return $dispatch->refresh();
    }

    /** @return array{Delivery, PhaseRun, AgentDispatch, string} */
    private function prepare(
        int $deliveryId,
        int $phaseRunId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $phaseRunId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $allDispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatches = $allDispatches->where('phase_run_id', $phase?->id);
            $dispatch = $dispatches->first();

            if ($phase === null || $dispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || ! in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt < 1
                || $dispatches->count() !== 1
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
                || $dispatch->prompt_name !== 'orbit_resolution'
                || $dispatch->prompt_version !== OrbitFeatureWorkflow::RESOLUTION_PROMPT_VERSION) {
                throw new OrbitResolutionDispatchFailed('The retained Orbit resolution intent is inconsistent.');
            }

            if ($delivery->status === DeliveryStatus::Queued) {
                if ($phase->status !== PhaseRunStatus::Pending || $dispatch->status !== AgentDispatchStatus::Pending) {
                    throw new OrbitResolutionDispatchFailed('The retained Orbit resolution state is inconsistent.');
                }

                $phase->status = PhaseRunStatus::Running;
                $phase->started_at = now();
                $phase->save();
                $delivery->status = DeliveryStatus::Preparing;
                $delivery->save();
            }

            $resolutionInput = is_array($phase->input) ? $phase->input : [];
            $prompt = $this->planReceipts->matchesSource($delivery, $phase, $dispatch)
                ? $this->workflow->planResolutionPrompt(
                    (string) $delivery->external_issue_key,
                    $config->repository,
                    (string) $delivery->worktree_path,
                    $delivery->id,
                    $phase->id,
                    $dispatch->id,
                    $this->receiptCommand($phase, $dispatch),
                    $resolutionInput,
                )
                : $this->workflow->pullRequestResolutionPrompt(
                    (string) $delivery->external_issue_key,
                    $config->repository,
                    (string) $delivery->worktree_path,
                    $delivery->id,
                    $phase->id,
                    $dispatch->id,
                    $this->receiptCommand($phase, $dispatch),
                    $resolutionInput,
                );

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = hash('sha256', $prompt);
                $dispatch->save();
            }

            $delivery->setRelation('projectOrchestration', $project);

            if (! $this->receipts->matchesInput($delivery, $phase, $dispatch)
                || ($delivery->status === DeliveryStatus::Preparing
                    && ($phase->status !== PhaseRunStatus::Running
                        || ! in_array($dispatch->status, [AgentDispatchStatus::Pending, AgentDispatchStatus::Starting], true)))
                || ($delivery->status === DeliveryStatus::WaitingForAgent
                    && ($phase->status !== PhaseRunStatus::Running
                        || ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)))) {
                throw new OrbitResolutionDispatchFailed('The retained Orbit resolution state is inconsistent.');
            }

            return [$delivery, $phase, $dispatch, $prompt];
        });
    }

    /** @return array{Delivery, PhaseRun, AgentDispatch} */
    private function recheckBeforeMutation(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $phaseRunId, $dispatchId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
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
            Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || ! in_array($delivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || ! in_array($dispatch->status, [
                    AgentDispatchStatus::Pending,
                    AgentDispatchStatus::Waiting,
                    AgentDispatchStatus::Settled,
                ], true)
                || ! $this->receipts->matchesInput($delivery, $phase, $dispatch)) {
                throw new OrbitResolutionDispatchFailed(
                    'The resolution ledger changed before external mutation.',
                );
            }

            return [$delivery, $phase, $dispatch];
        });
    }

    private function verifyExternalState(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        PhaseRun $phase,
    ): void {
        $input = $phase->input;
        $planReview = is_array($input) ? $this->map($input['plan_review_receipt'] ?? null) : null;
        $planning = is_array($input) ? $this->map($input['planning_receipt'] ?? null) : null;
        $implementation = is_array($input)
            ? $this->map($input['implementation_receipt'] ?? null)
            : null;
        $review = is_array($input) ? $this->map($input['pr_review_receipt'] ?? null) : null;
        $publishedReview = is_array($input) ? $this->map($input['published_review'] ?? null) : null;
        $pullRequestEvidence = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if ($implementation === null && ($planning !== null || $planReview !== null)) {
            $this->verifyPlanResolution(
                $delivery,
                $config,
                $preparation,
                $planning,
                $planReview,
            );
            $this->assertCurrentIssue($delivery, $preparation, false);

            return;
        }

        if (! is_array($implementation)) {
            throw new OrbitResolutionDispatchFailed('The resolution evidence is malformed.');
        }

        if ($review === null) {
            $this->verifyImplementationResolution(
                $delivery,
                $config,
                $preparation,
                $implementation,
                $pullRequestEvidence,
            );
            $this->assertCurrentIssue($delivery, $preparation, false);

            return;
        }

        if (! is_array($pullRequestEvidence)
            || array_is_list($pullRequestEvidence)
            || ($pullRequestEvidence['mergeable'] ?? null) !== true) {
            throw new OrbitResolutionDispatchFailed('The resolution pull request evidence is malformed.');
        }

        $this->verifyImplementationAndPullRequest(
            $delivery,
            $config,
            $preparation,
            $implementation,
            true,
        );

        $result = $review['result'] ?? null;

        if ($result === 'changes') {
            if (! is_array($publishedReview)) {
                throw new OrbitResolutionDispatchFailed('The published review evidence is malformed.');
            }

            $this->verifyPublishedReview($implementation, $review, $publishedReview);
        } elseif ($result !== 'blocked' || $publishedReview !== null) {
            throw new OrbitResolutionDispatchFailed('The resolution review evidence is malformed.');
        }

        $this->assertCurrentIssue($delivery, $preparation, true);
    }

    /**
     * @param  array<string, mixed>|null  $planning
     * @param  array<string, mixed>|null  $planReview
     */
    private function verifyPlanResolution(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        ?array $planning,
        ?array $planReview,
    ): void {
        $source = $planning ?? $planReview;

        if ($source === null) {
            throw new OrbitResolutionDispatchFailed('The planning resolution evidence is malformed.');
        }

        $candidate = $this->sha($source, 'candidate_sha');
        $verified = $this->repository->verifyPlanningOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $candidate,
            null,
        );

        if ($candidate !== $delivery->candidate_sha
            || ($planReview !== null && ($planReview['candidate_sha'] ?? null) !== $candidate)
            || $verified->candidateSha !== $candidate
            || $verified->artifactSha !== null
            || $verified->planContentsHash !== null) {
            throw new OrbitResolutionDispatchFailed(
                'The verified planning state no longer matches the resolution evidence.',
            );
        }

        if ($planReview === null || ($planReview['result'] ?? null) !== 'fix') {
            return;
        }

        $artifactSha = $this->sha($planReview, 'artifact_sha');
        $artifact = $this->repository->verifyPlanningArtifact(
            $config,
            new PreparedWorktree($preparation->worktree->path, $candidate),
            $preparation->snapshot->issueKey,
            $artifactSha,
            'FIX',
        );

        if ($artifact->artifactSha !== $artifactSha
            || $artifact->planContentsHash !== ($planReview['plan_sha256'] ?? null)) {
            throw new OrbitResolutionDispatchFailed(
                'The verified plan-review artifact no longer matches the resolution evidence.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $implementation
     */
    private function verifyImplementationResolution(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        array $implementation,
        mixed $pullRequestEvidence,
    ): void {
        $result = $implementation['result'] ?? null;

        if ($result === 'blocked') {
            if ($pullRequestEvidence !== null) {
                throw new OrbitResolutionDispatchFailed('The blocked implementation pull request evidence is malformed.');
            }

            return;
        }

        if ($result !== 'ready'
            || ! is_array($pullRequestEvidence)
            || array_is_list($pullRequestEvidence)
            || ($pullRequestEvidence['mergeable'] ?? null) !== false) {
            throw new OrbitResolutionDispatchFailed('The implementation resolution evidence is malformed.');
        }

        $this->verifyImplementationAndPullRequest(
            $delivery,
            $config,
            $preparation,
            $implementation,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $implementation
     */
    private function verifyImplementationAndPullRequest(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        array $implementation,
        bool $expectedMergeable,
    ): void {
        $body = $this->string($implementation, 'pull_request_body');
        $candidate = $this->sha($implementation, 'candidate_sha');
        $verified = $this->implementations->verifyImplementationOutcome(
            $config,
            $preparation->worktree,
            $preparation->snapshot,
            $this->sha($implementation, 'reviewed_candidate_sha'),
            $candidate,
            $this->sha($implementation, 'artifact_sha'),
            $this->string($implementation, 'gate_receipt_path'),
            $body,
        );

        if ($verified->candidateSha !== $candidate
            || $verified->artifactSha !== $implementation['artifact_sha']
            || $verified->gateReceiptPath !== $implementation['gate_receipt_path']
            || $verified->pullRequestBodyHash !== hash('sha256', $body)
            || $verified->flow !== $implementation['flow']) {
            throw new OrbitResolutionDispatchFailed(
                'The verified implementation no longer matches the resolution evidence.',
            );
        }

        $number = $delivery->pull_request_number;

        if (! is_int($number) || $number < 1) {
            throw new OrbitResolutionDispatchFailed('The resolution pull request number is invalid.');
        }

        $pullRequest = $this->pullRequests->inspect(
            $number,
            (string) $delivery->external_issue_key,
            $candidate,
            $body,
        );

        if ($pullRequest->number !== $number
            || $pullRequest->url !== $delivery->pull_request_url
            || $pullRequest->candidateSha !== $candidate
            || $pullRequest->bodyHash !== hash('sha256', $body)
            || $pullRequest->mergeable !== $expectedMergeable) {
            throw new OrbitResolutionDispatchFailed(
                'The published pull request no longer matches the resolution evidence.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $implementation
     * @param  array<string, mixed>  $review
     * @param  array<string, mixed>  $publishedReview
     */
    private function verifyPublishedReview(
        array $implementation,
        array $review,
        array $publishedReview,
    ): void {
        $body = $this->string($implementation, 'pull_request_body');
        $candidate = $this->sha($implementation, 'candidate_sha');

        if (! is_int($publishedReview['id'] ?? null) || $publishedReview['id'] < 1
            || ($publishedReview['reviewer_login'] ?? null) !== 'tom-nckrtl[bot]'
            || ($publishedReview['candidate_sha'] ?? null) !== $candidate
            || ($publishedReview['state'] ?? null) !== 'CHANGES_REQUESTED'
            || ($publishedReview['review_body_sha256'] ?? null) !== hash('sha256', $this->string($review, 'handoff'))
            || ($publishedReview['pull_request_body_sha256'] ?? null) !== hash('sha256', $body)) {
            throw new OrbitResolutionDispatchFailed(
                'The published review no longer matches the resolution evidence.',
            );
        }
    }

    private function assertCurrentIssue(
        Delivery $delivery,
        OrbitDeliveryPreparation $preparation,
        bool $inReview,
    ): void {
        $issue = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $assignee = $issue->payload['assignee'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');
        $validState = is_array($state)
            && ($state['type'] ?? null) === 'started'
            && ($inReview
                ? ($state['name'] ?? null) === 'In Review'
                : in_array($state['name'] ?? null, ['In Progress', 'In Review'], true));
        $validAssignee = $inReview
            ? $assignee === null
            : $assignee === null || (is_array($assignee) && ($assignee['id'] ?? null) === $nickId);

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! $this->snapshots->matchesExpectedContract(
                $issue,
                $preparation->snapshot->contractHash,
                $delivery->pull_request_url,
            )
            || ! is_string($viewerId) || ! is_string($nickId)
            || ! $validState
            || ! is_array($delegate)
            || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || ! $validAssignee) {
            throw new OrbitResolutionDispatchFailed(
                'The Orbit issue changed before resolution dispatch.',
            );
        }
    }

    private function markPromptAttempted(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        OrbitProjectConfig $config,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseRunId, $dispatchId, $config): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
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
            Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->status !== DeliveryStatus::Preparing
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->status !== AgentDispatchStatus::Starting
                || $dispatch->error_code !== 'resolution_final_verification'
                || ! $this->receipts->matchesInput($delivery, $phase, $dispatch)) {
                throw new OrbitResolutionDispatchFailed(
                    'The resolution ledger changed before final verification.',
                );
            }

            $dispatch->forceFill([
                'error_code' => 'herdr_prompt_attempted',
                'error_message' => null,
            ])->save();
        }, 5);
    }

    private function isInterruptedBeforePrompt(Delivery $delivery, int $phaseRunId): bool
    {
        $failure = $delivery->failure_details;

        return $delivery->status === DeliveryStatus::Blocked
            && is_array($failure)
            && $failure === [
                'code' => 'resolution_dispatch_failed',
                'phase_run_id' => $phaseRunId,
                'dispatch_id' => $failure['dispatch_id'] ?? null,
                'message' => self::INTERRUPTED_BEFORE_PROMPT_MESSAGE,
            ]
            && is_int($failure['dispatch_id'] ?? null);
    }

    private function recoverInterruptedBeforePrompt(
        Delivery $delivery,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
    ): AgentDispatch {
        [$phase, $dispatch, $prompt] = $this->interruptedBeforePromptState(
            $delivery->id,
            $config,
        );
        $reservation = $this->repository->reserveDelivery(
            $config,
            $preparation->snapshot->issueKey,
        );

        try {
            $current = Delivery::query()->with('projectOrchestration')->findOrFail($delivery->id);
            [$phase, $dispatch, $prompt] = $this->interruptedBeforePromptState(
                $current->id,
                $config,
            );
            $this->verifyExternalState($current, $config, $preparation, $phase);

            try {
                $agent = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (Throwable $exception) {
                throw new OrbitResolutionDispatchFailed(
                    'The retained Orbit resolver could not be verified.',
                    0,
                    $exception,
                );
            }

            if (! $this->sameInterruptedAgent($current, $dispatch, $agent)
                || ! in_array($agent->agentStatus, ['idle', 'done'], true)) {
                throw new OrbitResolutionDispatchFailed(
                    'The retained Orbit resolver is not safe to resume.',
                );
            }

            $this->markRecoveryPromptAttempted(
                $current->id,
                $phase->id,
                $dispatch->id,
                $config,
                $agent,
            );

            try {
                $prompted = $this->herdr->promptAgent($agent->agentName, $prompt);
            } catch (Throwable $exception) {
                $this->markRecoveryPromptAmbiguous(
                    $current->id,
                    $dispatch->id,
                    'resolution_prompt_ambiguous',
                    $exception->getMessage(),
                );

                throw new OrbitResolutionDispatchFailed(
                    'The Herdr resolver prompt outcome is unresolved; Commander will not submit it again automatically.',
                    0,
                    $exception,
                );
            }

            if (! $this->sameAgent($prompted, $dispatch->refresh())) {
                $message = 'Herdr prompted an agent outside the recorded resolution dispatch.';
                $this->markRecoveryPromptAmbiguous(
                    $current->id,
                    $dispatch->id,
                    'resolution_prompt_identity_ambiguous',
                    $message,
                );

                throw new OrbitResolutionDispatchFailed($message);
            }

            $this->completeRecoveredPrompt(
                $current->id,
                $phase->id,
                $dispatch->id,
                $config,
                $prompted,
            );
        } finally {
            $reservation->release();
        }

        return $dispatch->refresh();
    }

    /** @return array{PhaseRun, AgentDispatch, string} */
    private function interruptedBeforePromptState(
        int $deliveryId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
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
            $failure = $delivery->failure_details;
            $phaseId = is_array($failure) ? ($failure['phase_run_id'] ?? null) : null;
            $dispatchId = is_array($failure) ? ($failure['dispatch_id'] ?? null) : null;
            $phase = is_int($phaseId) ? $phases->firstWhere('id', $phaseId) : null;
            $dispatch = is_int($dispatchId) ? $dispatches->firstWhere('id', $dispatchId) : null;
            $phaseDispatches = $dispatches->where('phase_run_id', $phase?->id);
            $phaseReceipts = $receipts->where('phase_run_id', $phase?->id);
            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || ! $this->isInterruptedBeforePrompt($delivery, $phase->id)
                || $phases->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                    ->sortByDesc('attempt')->first()?->id !== $phase->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $phase->finished_at !== null
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->isNotEmpty()
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Ambiguous
                || $dispatch->error_code !== 'resolution_dispatch_failed'
                || $dispatch->error_message !== self::INTERRUPTED_BEFORE_PROMPT_MESSAGE
                || $dispatch->herdr_session !== $config->herdrSession
                || $dispatch->herdr_workspace_id === null
                || $dispatch->herdr_tab_id === null
                || $dispatch->herdr_pane_id === null
                || $dispatch->herdr_terminal_id === null
                || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key)
                    .'-loop-resolution-'.$phase->attempt
                || $dispatch->state_change_seq === null
                || $dispatch->state_change_seq < 0
                || $dispatch->dispatched_at === null
                || $dispatch->settled_at !== null
                || ! $this->receipts->matchesInput($delivery, $phase, $dispatch)) {
                throw new OrbitResolutionDispatchFailed(
                    'The interrupted Orbit resolution dispatch is not safe to recover.',
                );
            }

            $input = is_array($phase->input) ? $phase->input : [];
            $prompt = $this->planReceipts->matchesSource($delivery, $phase, $dispatch)
                ? $this->workflow->planResolutionPrompt(
                    (string) $delivery->external_issue_key,
                    $config->repository,
                    (string) $delivery->worktree_path,
                    $delivery->id,
                    $phase->id,
                    $dispatch->id,
                    $this->receiptCommand($phase, $dispatch),
                    $input,
                )
                : $this->workflow->pullRequestResolutionPrompt(
                    (string) $delivery->external_issue_key,
                    $config->repository,
                    (string) $delivery->worktree_path,
                    $delivery->id,
                    $phase->id,
                    $dispatch->id,
                    $this->receiptCommand($phase, $dispatch),
                    $input,
                );

            if (! hash_equals($dispatch->prompt_hash, hash('sha256', $prompt))) {
                throw new OrbitResolutionDispatchFailed(
                    'The interrupted Orbit resolution prompt changed before recovery.',
                );
            }

            return [$phase, $dispatch, $prompt];
        }, 5);
    }

    private function sameInterruptedAgent(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $agent,
    ): bool {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && ($dispatch->herdr_agent_id === null
                || ($agent->agentId !== null && $agent->agentId === $dispatch->herdr_agent_id))
            && $agent->agentName === $dispatch->herdr_agent_name
            && $agent->workingDirectory === $delivery->worktree_path
            && $agent->stateChangeSeq !== null
            && $dispatch->state_change_seq !== null
            && $agent->stateChangeSeq >= $dispatch->state_change_seq
            && $this->independentFromPriorAgents(
                $delivery,
                $dispatch->id,
                $agent,
                $agent->agentName,
            );
    }

    private function markRecoveryPromptAttempted(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $agent,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $config,
            $agent,
        ): void {
            [$phase, $dispatch] = $this->lockedInterruptedBeforePromptState(
                $deliveryId,
                $phaseRunId,
                $dispatchId,
                $config,
            );
            $delivery = Delivery::query()->whereKey($deliveryId)->firstOrFail();

            if (! $this->sameInterruptedAgent($delivery, $dispatch, $agent)) {
                throw new OrbitResolutionDispatchFailed(
                    'The retained Orbit resolver changed before prompt recovery.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Starting,
                'herdr_agent_id' => $agent->agentId ?? $dispatch->herdr_agent_id,
                'state_change_seq' => $agent->stateChangeSeq,
                'error_code' => 'herdr_prompt_attempted',
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Preparing,
                'failure_details' => null,
            ])->save();
        }, 5);
    }

    /** @return array{PhaseRun, AgentDispatch} */
    private function lockedInterruptedBeforePromptState(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        OrbitProjectConfig $config,
    ): array {
        $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
        $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
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
        $phase = $phases->firstWhere('id', $phaseRunId);
        $dispatch = $dispatches->firstWhere('id', $dispatchId);
        $delivery->setRelation('projectOrchestration', $project);

        if ($phase === null || $dispatch === null
            || $project->state !== ProjectOrchestrationState::Enabled
            || $project->config !== $config->toArray()
            || ! $this->isInterruptedBeforePrompt($delivery, $phaseRunId)
            || $phases->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->sortByDesc('attempt')->first()?->id !== $phase->id
            || $phase->status !== PhaseRunStatus::Running
            || $phase->finished_at !== null
            || $dispatches->where('phase_run_id', $phase->id)->count() !== 1
            || $receipts->where('phase_run_id', $phase->id)->isNotEmpty()
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->status !== AgentDispatchStatus::Ambiguous
            || $dispatch->error_code !== 'resolution_dispatch_failed'
            || $dispatch->error_message !== self::INTERRUPTED_BEFORE_PROMPT_MESSAGE
            || ! $this->receipts->matchesInput($delivery, $phase, $dispatch)) {
            throw new OrbitResolutionDispatchFailed(
                'The interrupted Orbit resolution ledger changed before recovery.',
            );
        }

        return [$phase, $dispatch];
    }

    private function completeRecoveredPrompt(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $prompted,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $config,
            $prompted,
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseRunId)->lockForUpdate()->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $delivery->setRelation('projectOrchestration', $project);

            if ($project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Preparing
                || $phase->delivery_id !== $delivery->id
                || $phase->status !== PhaseRunStatus::Running
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Starting
                || $dispatch->error_code !== 'herdr_prompt_attempted'
                || ! $this->sameAgent($prompted, $dispatch)) {
                throw new OrbitResolutionDispatchFailed(
                    'The recovered Orbit resolution dispatch changed while its prompt was submitted.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Waiting,
                'herdr_agent_id' => $prompted->agentId ?? $dispatch->herdr_agent_id,
                'state_change_seq' => $prompted->stateChangeSeq,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::WaitingForAgent,
                'failure_details' => null,
            ])->save();
        }, 5);
    }

    private function markRecoveryPromptAmbiguous(
        int $deliveryId,
        int $dispatchId,
        string $code,
        string $message,
    ): void {
        DB::transaction(function () use ($deliveryId, $dispatchId, $code, $message): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();

            if ($delivery === null || $dispatch === null
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Preparing
                || $dispatch->status !== AgentDispatchStatus::Starting
                || $dispatch->error_code !== 'herdr_prompt_attempted') {
                return;
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Ambiguous,
                'error_code' => $code,
                'error_message' => $message,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Blocked,
                'failure_details' => [
                    'code' => $code,
                    'phase_run_id' => $dispatch->phase_run_id,
                    'dispatch_id' => $dispatch->id,
                    'message' => $message,
                ],
            ])->save();
        }, 5);
    }

    private function startAgent(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $pane,
    ): HerdrAgentIdentifiers {
        try {
            $started = $this->herdr->startAgent(
                $pane->paneId,
                (string) $dispatch->herdr_agent_name,
                new HerdrAgentLaunch('codex', [
                    '-m',
                    'gpt-5.6-sol',
                    '-c',
                    'model_reasoning_effort="high"',
                    '-c',
                    'features.multi_agent=false',
                    '-c',
                    'mcp_servers.context7.enabled=false',
                    '-c',
                    'mcp_servers.solo_nick.enabled=false',
                    '-c',
                    'mcp_servers.solo_mini.enabled=false',
                    '--dangerously-bypass-approvals-and-sandbox',
                ], 120_000),
            );
        } catch (Throwable $startFailure) {
            try {
                $started = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (Throwable) {
                throw new OrbitResolutionDispatchFailed(
                    'The Herdr resolver start outcome is unresolved.',
                    0,
                    $startFailure,
                );
            }
        }

        if ($started->workspaceId !== $pane->workspaceId
            || $started->tabId !== $pane->tabId
            || $started->paneId !== $pane->paneId
            || $started->terminalId !== $pane->terminalId
            || $started->agentName !== $dispatch->herdr_agent_name
            || $started->stateChangeSeq === null
            || $started->stateChangeSeq < 0
            || ! $this->independentFromPriorAgents($delivery, $dispatch->id, $started, $started->agentName)) {
            throw new OrbitResolutionDispatchFailed('Herdr returned an agent outside the recorded resolution pane.');
        }

        return $started;
    }

    private function sameAgent(HerdrAgentIdentifiers $agent, AgentDispatch $dispatch): bool
    {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && ($dispatch->herdr_agent_id === null
                || ($agent->agentId !== null && $agent->agentId === $dispatch->herdr_agent_id))
            && $agent->agentName === $dispatch->herdr_agent_name
            && $agent->stateChangeSeq !== null
            && $dispatch->state_change_seq !== null
            && $agent->stateChangeSeq >= $dispatch->state_change_seq;
    }

    private function independentFromPriorAgents(
        Delivery $delivery,
        int $dispatchId,
        HerdrAgentIdentifiers $agent,
        string $agentName,
    ): bool {
        return ! AgentDispatch::query()
            ->whereHas('phaseRun', fn ($query) => $query->where('delivery_id', $delivery->id))
            ->where('id', '!=', $dispatchId)
            ->where(function ($query) use ($agent, $agentName): void {
                $query->where('herdr_pane_id', $agent->paneId)
                    ->orWhere('herdr_terminal_id', $agent->terminalId)
                    ->orWhere('herdr_agent_name', $agentName)
                    ->when($agent->agentId !== null, fn ($query) => $query->orWhere('herdr_agent_id', $agent->agentId));
            })
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitResolutionDispatchFailed("The resolution evidence has invalid {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function sha(array $payload, string $key): string
    {
        $value = $this->string($payload, $key);

        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitResolutionDispatchFailed("The resolution evidence has invalid {$key}.");
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function map(mixed $value): ?array
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

    private function markBlocked(int $deliveryId, int $dispatchId, Throwable $exception): void
    {
        DB::transaction(function () use ($deliveryId, $dispatchId, $exception): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();

            if ($delivery === null || $dispatch === null
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status === DeliveryStatus::WaitingForAgent) {
                return;
            }

            $dispatch->status = AgentDispatchStatus::Ambiguous;
            $dispatch->error_code = 'resolution_dispatch_failed';
            $dispatch->error_message = $exception->getMessage();
            $dispatch->save();
            $delivery->status = DeliveryStatus::Blocked;
            $delivery->failure_details = [
                'code' => 'resolution_dispatch_failed',
                'phase_run_id' => $dispatch->phase_run_id,
                'dispatch_id' => $dispatch->id,
                'message' => $exception->getMessage(),
            ];
            $delivery->save();
        });
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
}
