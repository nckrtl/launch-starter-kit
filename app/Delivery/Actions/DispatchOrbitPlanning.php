<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPlanningDispatchFailed;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Herdr\RequestFailed;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final readonly class DispatchOrbitPlanning
{
    private const int START_RECOVERY_ATTEMPTS = 9;

    private const int START_RECOVERY_DELAY_MICROSECONDS = 250_000;

    public function __construct(
        private ProjectConfigRegistry $configs,
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private OrbitIssueTransitioner $transitioner,
        private HerdrRuntime $herdr,
        private HerdrWorkspaceRuntime $herdrWorkspace,
        private CaptureHerdrEvent $events,
        private OrbitFeatureWorkflow $workflow,
    ) {}

    public function handle(int $deliveryId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        $reconciled = $this->reconcileInterruptedPrompt($delivery);

        if ($reconciled !== null) {
            return $reconciled;
        }

        $this->assertLiveDelivery($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitPlanningDispatchFailed('The delivery does not use Orbit project configuration.');
        }

        if ($config->herdrSession !== config('herdr.session')) {
            throw new OrbitPlanningDispatchFailed('The Orbit project does not use Commander\'s active Herdr session.');
        }

        $preparation = $this->preparations->handle($delivery);
        [$dispatch, $prompt] = $this->prepareDispatch($delivery, $config);

        if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $dispatch = $this->recheckBeforeMutation($delivery->id, $dispatch->id, $config);

            if (in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
                return $dispatch;
            }

            $current = $this->verifyCurrentPlanningState($config, $preparation);

            try {
                $transitioned = $this->transitioner->transitionToInProgress(
                    $current,
                    $preparation->snapshot->contractHash,
                );
            } catch (OrbitIssueTransitionFailed $exception) {
                $code = $exception->ambiguous ? 'linear_transition_ambiguous' : 'linear_transition_failed';
                $status = $exception->ambiguous ? AgentDispatchStatus::Ambiguous : AgentDispatchStatus::Failed;
                $this->markBlocked($delivery, $dispatch, $status, $code, $exception);

                throw new OrbitPlanningDispatchFailed(
                    $exception->ambiguous
                        ? 'The Linear transition outcome is unresolved; Commander will not replay it automatically.'
                        : 'Linear rejected the planning transition before any Herdr state was created.',
                    0,
                    $exception,
                );
            }

            if (! $this->claim($dispatch)) {
                throw new OrbitPlanningDispatchFailed('Another process already claimed the Orbit planning dispatch.');
            }

            return $this->startAndPrompt($delivery, $dispatch, $prompt, $config, $preparation, $transitioned);
        } finally {
            $reservation->release();
        }
    }

    public function recoverAmbiguousStart(int $deliveryId, int $expectedPhaseId): AgentDispatch
    {
        $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

        if ($delivery === null) {
            throw new OrbitPlanningDispatchFailed('The Orbit delivery does not exist.');
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig || $config->herdrSession !== config('herdr.session')) {
            throw new OrbitPlanningDispatchFailed(
                'The blocked delivery does not use Commander\'s active Orbit configuration.',
            );
        }

        $preparation = $this->preparations->handle($delivery);
        [$phase, $dispatch, $prompt] = $this->prepareAmbiguousStartRecovery(
            $delivery->id,
            $expectedPhaseId,
            $config,
        );
        $reservation = $this->repository->reserveDelivery($config, $preparation->snapshot->issueKey);

        try {
            $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($delivery->id);
            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $phase->id,
                $dispatch->id,
                $config,
            );
            $current = $this->verifyCurrentPlanningState($config, $preparation);
            $this->assertRecoverableCurrentIssue($preparation, $current);
            $pane = new HerdrAgentIdentifiers(
                workspaceId: (string) $dispatch->herdr_workspace_id,
                tabId: (string) $dispatch->herdr_tab_id,
                paneId: (string) $dispatch->herdr_pane_id,
                terminalId: (string) $dispatch->herdr_terminal_id,
                agentId: null,
                agentName: (string) $dispatch->herdr_agent_name,
            );
            $started = $this->recoverPreAgentStart($delivery, $dispatch, $pane, $config);
            $this->persistRecoveredStart(
                $delivery->id,
                $phase->id,
                $dispatch->id,
                $config,
                $started,
            );

            return $this->verifyAndPromptStarted(
                $delivery,
                $dispatch->refresh(),
                $started,
                $prompt,
                $config,
                $preparation,
                $current,
            );
        } finally {
            $reservation->release();
        }
    }

    public function bindAmbiguousStartRecovery(int $deliveryId): ?int
    {
        try {
            $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

            if ($delivery === null) {
                return null;
            }

            $config = $this->configs->hydrate($delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig || $config->herdrSession !== config('herdr.session')) {
                return null;
            }

            $phase = $delivery->phaseRuns()
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->latest('attempt')
                ->first();

            if ($phase === null) {
                return null;
            }

            [$bound] = $this->prepareAmbiguousStartRecovery($delivery->id, $phase->id, $config);

            return $bound->id;
        } catch (Exception) {
            return null;
        }
    }

    private function reconcileInterruptedPrompt(Delivery $delivery): ?AgentDispatch
    {
        if ($delivery->status !== DeliveryStatus::Blocked
            || $delivery->failure_details !== [
                'code' => 'planning_dispatch_interrupted',
                'dispatch_id' => $delivery->failure_details['dispatch_id'] ?? null,
                'stage' => 'herdr_prompt_attempted',
            ]
            || ! is_int($delivery->failure_details['dispatch_id'] ?? null)) {
            return null;
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig
            || $config->herdrSession !== config('herdr.session')) {
            throw new OrbitPlanningDispatchFailed(
                'The interrupted planning dispatch does not use Commander\'s active Orbit configuration.',
            );
        }

        $dispatch = AgentDispatch::query()
            ->with('phaseRun')
            ->findOrFail($delivery->failure_details['dispatch_id']);

        $this->assertInterruptedPromptLedger($delivery, $dispatch, $config);

        try {
            $agent = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
        } catch (Exception $exception) {
            throw new OrbitPlanningDispatchFailed(
                'Commander could not observe the retained Orbit planning agent.',
                0,
                $exception,
            );
        }

        if (! $this->sameInterruptedAgent($delivery, $dispatch, $agent)) {
            throw new OrbitPlanningDispatchFailed(
                'The retained Orbit planning agent is not safe to reconcile.',
            );
        }

        $dispatch = $this->restoreInterruptedPrompt($delivery->id, $dispatch->id, $config, $agent);

        if (in_array($agent->agentStatus, ['idle', 'done'], true)) {
            $event = $this->events->reconcile($dispatch, $agent);
            $dispatch = $dispatch->refresh();

            if ($event?->agent_dispatch_id !== $dispatch->id
                || $dispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPlanningDispatchFailed(
                    'The terminal Orbit planning observation did not settle the recovered dispatch.',
                );
            }
        }

        return $dispatch;
    }

    private function assertInterruptedPromptLedger(
        Delivery $delivery,
        AgentDispatch $dispatch,
        OrbitProjectConfig $config,
    ): void {
        $phase = $dispatch->phaseRun;
        $latestPhaseId = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->value('id');

        if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || $delivery->failure_details !== [
                'code' => 'planning_dispatch_interrupted',
                'dispatch_id' => $dispatch->id,
                'stage' => 'herdr_prompt_attempted',
            ]
            || $latestPhaseId !== $phase->id
            || $dispatch->phase_run_id !== $phase->id
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $phase->attempt !== 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->finished_at !== null
            || $phase->agentDispatches()->count() !== 1
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Starting
            || $dispatch->error_code !== 'herdr_prompt_attempted'
            || $dispatch->error_message !== null
            || $dispatch->herdr_session !== $config->herdrSession
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
            || $dispatch->state_change_seq === null
            || $dispatch->dispatched_at === null
            || $dispatch->settled_at !== null) {
            throw new OrbitPlanningDispatchFailed(
                'The interrupted Orbit planning prompt ledger is not safe to reconcile.',
            );
        }
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
            && $agent->stateChangeSeq > $dispatch->state_change_seq
            && in_array($agent->agentStatus, ['working', 'idle', 'done'], true);
    }

    private function restoreInterruptedPrompt(
        int $deliveryId,
        int $dispatchId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $agent,
    ): AgentDispatch {
        return DB::transaction(function () use ($deliveryId, $dispatchId, $config, $agent): AgentDispatch {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->setRelation(
                'projectOrchestration',
                $delivery->projectOrchestration()->lockForUpdate()->firstOrFail(),
            );
            $phase = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->latest('attempt')
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $dispatch->setRelation('phaseRun', $phase);

            $this->assertInterruptedPromptLedger($delivery, $dispatch, $config);

            if (! $this->sameInterruptedAgent($delivery, $dispatch, $agent)) {
                throw new OrbitPlanningDispatchFailed(
                    'The retained Orbit planning agent changed before reconciliation.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Waiting,
                'herdr_agent_id' => $agent->agentId ?? $dispatch->herdr_agent_id,
                'state_change_seq' => $agent->agentStatus === 'working'
                    ? $agent->stateChangeSeq
                    : $dispatch->state_change_seq,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::WaitingForAgent,
                'failure_details' => null,
            ])->save();

            return $dispatch;
        });
    }

    private function assertLiveDelivery(Delivery $delivery): void
    {
        $project = $delivery->projectOrchestration;
        $existing = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 1)
            ->first()?->agentDispatches()
            ->first();

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $project->state !== ProjectOrchestrationState::Enabled
            || ($delivery->status !== DeliveryStatus::Preparing
                && ! ($delivery->status === DeliveryStatus::WaitingForAgent
                    && $existing !== null
                    && in_array($existing->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)))) {
            throw new OrbitPlanningDispatchFailed('The delivery is not eligible for live Orbit planning dispatch.');
        }
    }

    /** @return array{AgentDispatch, string} */
    private function prepareDispatch(Delivery $delivery, OrbitProjectConfig $config): array
    {
        return DB::transaction(function () use ($delivery, $config): array {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $project = $locked->projectOrchestration()->lockForUpdate()->firstOrFail();

            if ($locked->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $locked->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $locked->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
                || ! in_array($locked->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()) {
                throw new OrbitPlanningDispatchFailed('The live Orbit delivery changed before dispatch was recorded.');
            }

            $phaseRun = PhaseRun::query()
                ->where('delivery_id', $locked->id)
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->where('attempt', 1)
                ->lockForUpdate()
                ->firstOrFail();

            if ($phaseRun->status === PhaseRunStatus::Pending) {
                $phaseRun->status = PhaseRunStatus::Running;
                $phaseRun->started_at = now();
                $phaseRun->save();
            }

            if ($phaseRun->status !== PhaseRunStatus::Running) {
                throw new OrbitPlanningDispatchFailed('The planning phase run is not available for dispatch.');
            }

            $dispatch = AgentDispatch::query()->firstOrCreate(
                [
                    'phase_run_id' => $phaseRun->id,
                    'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                ],
                [
                    'idempotency_key' => IdempotencyKey::forDispatch(
                        $locked->id,
                        $phaseRun->phase_name,
                        $phaseRun->attempt,
                        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
                    )->value,
                    'herdr_agent_name' => strtolower((string) $locked->external_issue_key).'-loop-builder',
                    'prompt_name' => 'orbit_planning',
                    'prompt_version' => OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION,
                    'prompt_hash' => str_repeat('0', 64),
                    'status' => AgentDispatchStatus::Pending,
                ],
            );
            $expectedKey = IdempotencyKey::forDispatch(
                $locked->id,
                $phaseRun->phase_name,
                $phaseRun->attempt,
                OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
            )->value;
            $expectedName = strtolower((string) $locked->external_issue_key).'-loop-builder';
            if ($phaseRun->agentDispatches()->count() !== 1
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->herdr_agent_name !== $expectedName
                || $dispatch->prompt_name !== 'orbit_planning'
                || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION
                || ($locked->status === DeliveryStatus::WaitingForAgent
                    && ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))
                || ($locked->status === DeliveryStatus::Preparing
                    && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))) {
                throw new OrbitPlanningDispatchFailed('The retained Orbit planning dispatch metadata is inconsistent.');
            }

            $prompt = $this->planningPrompt($locked, $phaseRun, $dispatch);
            $promptHash = hash('sha256', $prompt);

            if ($dispatch->status === AgentDispatchStatus::Pending) {
                $dispatch->prompt_hash = $promptHash;
                $dispatch->save();
            } elseif (! hash_equals($dispatch->prompt_hash, $promptHash)) {
                throw new OrbitPlanningDispatchFailed('The retained Orbit planning prompt metadata is inconsistent.');
            }

            return [$dispatch, $prompt];
        });
    }

    /** @return array{PhaseRun, AgentDispatch, string} */
    private function prepareAmbiguousStartRecovery(
        int $deliveryId,
        int $expectedPhaseId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $expectedPhaseId, $config): array {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $dispatch = $phase?->agentDispatches()->first();

            if ($phase === null || $dispatch === null) {
                throw new OrbitPlanningDispatchFailed(
                    'The ambiguous Orbit planning start ledger is incomplete.',
                );
            }

            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $expectedPhaseId,
                $dispatch->id,
                $config,
            );
            $prompt = $this->planningPrompt($delivery, $phase, $dispatch);

            if (! hash_equals($dispatch->prompt_hash, hash('sha256', $prompt))) {
                throw new OrbitPlanningDispatchFailed(
                    'The ambiguous Orbit planning prompt no longer matches its ledger.',
                );
            }

            return [$phase, $dispatch, $prompt];
        });
    }

    private function assertAmbiguousStartRecoveryLedger(
        Delivery $delivery,
        int $phaseId,
        ?int $dispatchId,
        OrbitProjectConfig $config,
    ): void {
        $project = $delivery->projectOrchestration()->firstOrFail();
        $phases = $delivery->phaseRuns()->orderBy('id')->get();
        $phase = $phases
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->sortByDesc('attempt')
            ->first();
        $dispatches = $phase?->agentDispatches()
            ->where('agent_role', OrbitFeatureWorkflow::PLANNING_AGENT_ROLE)
            ->get();
        $dispatch = $dispatches?->first();
        $failure = $delivery->failure_details;
        $expectedKey = IdempotencyKey::forDispatch(
            $delivery->id,
            OrbitFeatureWorkflow::INITIAL_PHASE,
            1,
            OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        )->value;
        $expectedPromptHash = $phase !== null && $dispatch !== null
            ? hash('sha256', $this->planningPrompt($delivery, $phase, $dispatch))
            : null;

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || ! is_array($failure)
            || count($failure) !== 3
            || ($failure['code'] ?? null) !== 'herdr_start_ambiguous'
            || ($failure['dispatch_id'] ?? null) !== $dispatchId
            || ($failure['message'] ?? null) !== $dispatch?->error_message
            || $project->state !== ProjectOrchestrationState::Enabled
            || $project->config !== $config->toArray()
            || $phases->count() !== 1
            || $phase === null
            || $phase->id !== $phaseId
            || $phase->attempt !== 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->finished_at !== null
            || $phase->receipts()->exists()
            || $phase->agentDispatches()->count() !== 1
            || $dispatches?->count() !== 1
            || $dispatch === null
            || $dispatch->id !== $dispatchId
            || $dispatch->idempotency_key !== $expectedKey
            || $dispatch->status !== AgentDispatchStatus::Ambiguous
            || $dispatch->error_code !== 'herdr_start_ambiguous'
            || ! is_string($dispatch->error_message)
            || ! str_ends_with($dispatch->error_message, ' (agent_pane_busy)')
            || $dispatch->herdr_session !== $config->herdrSession
            || $dispatch->herdr_workspace_id === null
            || $dispatch->herdr_tab_id === null
            || $dispatch->herdr_pane_id === null
            || $dispatch->herdr_terminal_id === null
            || $dispatch->herdr_agent_id !== null
            || $dispatch->state_change_seq !== null
            || $dispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
            || $dispatch->prompt_name !== 'orbit_planning'
            || $dispatch->prompt_version !== OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION
            || preg_match('/^[a-f0-9]{64}$/', $dispatch->prompt_hash) !== 1
            || $expectedPromptHash === null
            || ! hash_equals($dispatch->prompt_hash, $expectedPromptHash)
            || $dispatch->dispatched_at !== null
            || $dispatch->settled_at !== null) {
            throw new OrbitPlanningDispatchFailed(
                'The ambiguous Orbit planning start is not safe to recover.',
            );
        }
    }

    private function planningPrompt(
        Delivery $delivery,
        PhaseRun $phaseRun,
        AgentDispatch $dispatch,
    ): string {
        $retiredValue = $phaseRun->input['retired_stale_worktree'] ?? null;

        try {
            $retiredWorktree = is_array($retiredValue)
                ? RetiredOrbitStaleWorktree::fromArray($retiredValue)
                : null;
        } catch (InvalidArgumentException $exception) {
            throw new OrbitPlanningDispatchFailed(
                'The retained stale Orbit worktree evidence is invalid.',
                previous: $exception,
            );
        }

        if ($retiredValue !== null && $retiredWorktree === null) {
            throw new OrbitPlanningDispatchFailed(
                'The retained stale Orbit worktree evidence is invalid.',
            );
        }

        if ($retiredWorktree !== null
            && $retiredWorktree->issueKey !== $delivery->external_issue_key) {
            throw new OrbitPlanningDispatchFailed(
                'The retained stale Orbit worktree evidence is inconsistent.',
            );
        }

        return $this->workflow->planningPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $phaseRun->id,
            $dispatch->id,
            $this->receiptCommand($phaseRun, $dispatch),
            $retiredWorktree,
        );
    }

    private function recheckBeforeMutation(
        int $deliveryId,
        int $dispatchId,
        OrbitProjectConfig $config,
    ): AgentDispatch {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();

        if ($delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->projectOrchestration->config !== $config->toArray()) {
            throw new OrbitPlanningDispatchFailed('The live Orbit project changed before external planning mutation.');
        }

        if ($delivery->status === DeliveryStatus::WaitingForAgent
            && in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return $dispatch;
        }

        if ($delivery->status !== DeliveryStatus::Preparing
            || $dispatch->status !== AgentDispatchStatus::Pending) {
            if ($delivery->status === DeliveryStatus::Preparing
                && $dispatch->status === AgentDispatchStatus::Starting) {
                $this->blockInterruptedDelivery($delivery, $dispatch);

                throw new OrbitPlanningDispatchFailed(
                    'The prior Orbit planning dispatch was interrupted after startup began; manual recovery is required.',
                );
            }

            throw new OrbitPlanningDispatchFailed('The Orbit planning dispatch changed before external planning mutation.');
        }

        return $dispatch;
    }

    private function verifyCurrentPlanningState(
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
    ): OrbitIssueSnapshot {
        $this->repository->verifyPlanningHandoff(
            $config,
            $preparation->worktree,
            $preparation->candidate,
            $preparation->snapshot,
        );
        $current = $this->issues->fetch(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );

        if ($current->issueId !== $preparation->snapshot->issueId
            || $current->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $current->contractHash)) {
            throw new OrbitIssueContractChanged('The Orbit issue contract changed before live planning dispatch.');
        }

        return $current;
    }

    private function claim(AgentDispatch $dispatch): bool
    {
        return AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update([
                'status' => AgentDispatchStatus::Starting,
                'error_code' => 'planning_dispatch_starting',
                'error_message' => null,
            ]) === 1;
    }

    private function startAndPrompt(
        Delivery $delivery,
        AgentDispatch $dispatch,
        string $prompt,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $transitioned,
    ): AgentDispatch {
        try {
            $opened = $this->herdr->openWorktree(
                $config->repository,
                $preparation->worktree->path,
                $preparation->snapshot->issueKey,
            );
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_worktree_open_ambiguous', $exception);

            throw new OrbitPlanningDispatchFailed('The Herdr worktree-open outcome is unresolved.', 0, $exception);
        }

        try {
            $pane = $this->herdr->splitPane($opened->paneId, $preparation->worktree->path);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pane_split_ambiguous', $exception);

            throw new OrbitPlanningDispatchFailed('The Herdr pane-split outcome is unresolved.', 0, $exception);
        }

        if ($pane->workspaceId !== $opened->workspaceId || $pane->tabId !== $opened->tabId) {
            $exception = new OrbitPlanningDispatchFailed('Herdr returned a planning pane outside the opened workspace.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_pane_identity_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'herdr_session' => $config->herdrSession,
            'herdr_workspace_id' => $pane->workspaceId,
            'herdr_tab_id' => $pane->tabId,
            'herdr_pane_id' => $pane->paneId,
            'herdr_terminal_id' => $pane->terminalId,
            'error_code' => 'herdr_agent_starting',
            'error_message' => null,
        ])->save();

        $started = $this->startAgent($delivery, $dispatch, $pane, $this->planningLaunch());
        $this->persistStartedAgent($dispatch, $started);

        return $this->verifyAndPromptStarted(
            $delivery,
            $dispatch,
            $started,
            $prompt,
            $config,
            $preparation,
            $transitioned,
        );
    }

    private function verifyAndPromptStarted(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $started,
        string $prompt,
        OrbitProjectConfig $config,
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $transitioned,
    ): AgentDispatch {

        try {
            $this->repository->verifyPlanningHandoff(
                $config,
                $preparation->worktree,
                $preparation->candidate,
                $preparation->snapshot,
            );
            $finalIssue = $this->issues->fetch($transitioned->issueId, $transitioned->issueKey);
            $this->assertFinalIssue($transitioned, $finalIssue, $preparation->snapshot->contractHash);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'planning_final_verification_failed', $exception);

            throw new OrbitPlanningDispatchFailed('Final planning verification failed before prompting the retained agent.', 0, $exception);
        }

        $this->markPromptAttempted(
            $delivery->id,
            $dispatch->phase_run_id,
            $dispatch->id,
            $config,
            $started,
        );

        try {
            $prompted = $this->herdr->promptAgent($started->agentName, $prompt);
        } catch (Exception $exception) {
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_ambiguous', $exception);

            throw new OrbitPlanningDispatchFailed(
                'The Herdr prompt outcome is unresolved; Commander will not submit it again automatically.',
                0,
                $exception,
            );
        }

        if (! $this->sameAgent($prompted, $dispatch)) {
            $exception = new OrbitPlanningDispatchFailed('Herdr prompted an agent outside the recorded planning dispatch.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_prompt_identity_ambiguous', $exception);

            throw $exception;
        }

        DB::transaction(function () use ($delivery, $dispatch, $prompted): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();

            $promptWasAttempted = $lockedDispatch->status === AgentDispatchStatus::Starting
                && $lockedDispatch->error_code === 'herdr_prompt_attempted';

            if (! $promptWasAttempted && $lockedDispatch->status !== AgentDispatchStatus::Settled) {
                throw new OrbitPlanningDispatchFailed('The planning dispatch changed while its prompt was submitted.');
            }

            $lockedDispatch->herdr_agent_id = $prompted->agentId ?? $lockedDispatch->herdr_agent_id;
            $lockedDispatch->state_change_seq = $prompted->stateChangeSeq;
            $lockedDispatch->error_code = null;
            $lockedDispatch->error_message = null;

            if ($promptWasAttempted) {
                $lockedDispatch->status = AgentDispatchStatus::Waiting;
            }

            $lockedDispatch->save();

            if ($lockedDelivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
                && in_array($lockedDelivery->status, [DeliveryStatus::Preparing, DeliveryStatus::WaitingForAgent], true)) {
                $lockedDelivery->status = DeliveryStatus::WaitingForAgent;
                $lockedDelivery->failure_details = null;
                $lockedDelivery->save();
            }
        });

        return $dispatch->refresh();
    }

    private function recoverPreAgentStart(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $pane,
        OrbitProjectConfig $config,
    ): HerdrAgentIdentifiers {
        $failure = new RequestFailed((string) $dispatch->error_message, 'agent_pane_busy');
        $mayStart = true;

        for ($attempt = 0; $attempt < self::START_RECOVERY_ATTEMPTS; $attempt++) {
            try {
                $started = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);

                if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)
                    || $started->workingDirectory !== $delivery->worktree_path
                    || ! in_array($started->agentStatus, ['idle', 'done'], true)) {
                    throw new OrbitPlanningDispatchFailed(
                        'The recovered Herdr planner does not match the retained start intent.',
                    );
                }

                $this->assertRetainedPreAgentPane($delivery, $dispatch, $config, $started);

                return $started;
            } catch (RequestFailed $readFailure) {
                if ($readFailure->errorCode !== 'agent_not_found') {
                    throw new OrbitPlanningDispatchFailed(
                        'Commander could not determine whether the retained planner exists.',
                        0,
                        $readFailure,
                    );
                }
            }

            if ($mayStart) {
                $this->assertRetainedPreAgentPane($delivery, $dispatch, $config);

                try {
                    $started = $this->herdr->startAgent(
                        $pane->paneId,
                        (string) $dispatch->herdr_agent_name,
                        $this->planningLaunch(),
                    );

                    if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)
                        || $started->workingDirectory !== $delivery->worktree_path
                        || ! in_array($started->agentStatus, ['idle', 'done'], true)) {
                        throw new OrbitPlanningDispatchFailed(
                            'The recovered Herdr planner does not match the retained start intent.',
                        );
                    }

                    return $started;
                } catch (OrbitPlanningDispatchFailed $exception) {
                    throw $exception;
                } catch (Exception $startFailure) {
                    $failure = $startFailure;
                    $mayStart = $startFailure instanceof RequestFailed
                        && $startFailure->errorCode === 'agent_pane_busy';
                }
            }

            if ($attempt < self::START_RECOVERY_ATTEMPTS - 1) {
                Sleep::usleep(self::START_RECOVERY_DELAY_MICROSECONDS);
            }
        }

        throw new OrbitPlanningDispatchFailed(
            'The retained Herdr planner did not become recoverable within the bounded start window.',
            0,
            $failure,
        );
    }

    private function assertRetainedPreAgentPane(
        Delivery $delivery,
        AgentDispatch $dispatch,
        OrbitProjectConfig $config,
        ?HerdrAgentIdentifiers $allowedAgent = null,
    ): void {
        $snapshot = $this->herdrWorkspace->snapshot();
        $workspaces = array_values(array_filter(
            $snapshot->workspaces,
            static fn ($workspace): bool => $workspace->workspaceId === $dispatch->herdr_workspace_id,
        ));
        $panes = array_values(array_filter(
            $snapshot->panes,
            static fn ($pane): bool => $pane->paneId === $dispatch->herdr_pane_id,
        ));
        $agents = array_values(array_filter(
            $snapshot->agents,
            static fn ($agent): bool => $agent->paneId === $dispatch->herdr_pane_id
                || $agent->terminalId === $dispatch->herdr_terminal_id
                || $agent->name === $dispatch->herdr_agent_name,
        ));
        if (count($workspaces) !== 1 || count($panes) !== 1) {
            throw new OrbitPlanningDispatchFailed(
                'The retained Herdr planning pane is not safe to restart.',
            );
        }

        $workspace = $workspaces[0];
        $pane = $panes[0];

        if ($workspace->repositoryRoot !== $config->repository
            || $workspace->checkoutPath !== $delivery->worktree_path
            || $workspace->linkedWorktree !== true
            || $pane->workspaceId !== $dispatch->herdr_workspace_id
            || $pane->tabId !== $dispatch->herdr_tab_id
            || $pane->terminalId !== $dispatch->herdr_terminal_id
            || $pane->workingDirectory !== $delivery->worktree_path) {
            throw new OrbitPlanningDispatchFailed(
                'The retained Herdr planning pane is not safe to restart.',
            );
        }

        if ($allowedAgent !== null) {
            if (count($agents) !== 1) {
                throw new OrbitPlanningDispatchFailed(
                    'The retained Herdr planning agent provenance is not safe to adopt.',
                );
            }

            $agent = $agents[0];

            if ($agent->workspaceId !== $allowedAgent->workspaceId
                || $agent->tabId !== $allowedAgent->tabId
                || $agent->paneId !== $allowedAgent->paneId
                || $agent->terminalId !== $allowedAgent->terminalId
                || $agent->name !== $allowedAgent->agentName
                || $agent->status !== $allowedAgent->agentStatus
                || $agent->workingDirectory !== $allowedAgent->workingDirectory) {
                throw new OrbitPlanningDispatchFailed(
                    'The retained Herdr planning agent provenance is not safe to adopt.',
                );
            }

            return;
        }

        if ($agents !== []) {
            throw new OrbitPlanningDispatchFailed(
                'The retained Herdr planning pane is not safe to restart.',
            );
        }

        $process = $this->herdrWorkspace->inspectPaneProcess((string) $dispatch->herdr_pane_id);
        $foreground = $process->foregroundProcesses;

        if ($process->paneId !== $dispatch->herdr_pane_id
            || $process->shellProcessId === null
            || $process->foregroundProcessGroupId !== $process->shellProcessId
            || count($foreground) !== 1
            || $foreground[0]->processId !== $process->shellProcessId
            || ! in_array($foreground[0]->name, ['zsh', 'bash', 'sh', 'fish'], true)) {
            throw new OrbitPlanningDispatchFailed(
                'The retained Herdr planning pane has an unknown foreground process.',
            );
        }
    }

    private function persistRecoveredStart(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $started,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $dispatchId, $config, $started): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->assertAmbiguousStartRecoveryLedger(
                $delivery,
                $phaseId,
                $dispatchId,
                $config,
            );
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
            $pane = new HerdrAgentIdentifiers(
                workspaceId: (string) $dispatch->herdr_workspace_id,
                tabId: (string) $dispatch->herdr_tab_id,
                paneId: (string) $dispatch->herdr_pane_id,
                terminalId: (string) $dispatch->herdr_terminal_id,
                agentId: null,
                agentName: (string) $dispatch->herdr_agent_name,
            );

            if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)
                || $started->workingDirectory !== $delivery->worktree_path
                || ! in_array($started->agentStatus, ['idle', 'done'], true)) {
                throw new OrbitPlanningDispatchFailed(
                    'The recovered Herdr planner changed before it was retained.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Starting,
                'herdr_agent_id' => $started->agentId,
                'state_change_seq' => $started->stateChangeSeq,
                'dispatched_at' => now(),
                'error_code' => 'planning_final_verification',
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Preparing,
                'failure_details' => null,
            ])->save();
        });
    }

    private function startAgent(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $pane,
        HerdrAgentLaunch $launch,
    ): HerdrAgentIdentifiers {
        try {
            $started = $this->herdr->startAgent(
                $pane->paneId,
                (string) $dispatch->herdr_agent_name,
                $launch,
            );
        } catch (Exception $startFailure) {
            try {
                $started = $this->herdr->getAgent((string) $dispatch->herdr_agent_name);
            } catch (Exception) {
                $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_start_ambiguous', $startFailure);

                throw new OrbitPlanningDispatchFailed('The Herdr agent-start outcome is unresolved.', 0, $startFailure);
            }
        }

        if (! $this->samePaneAndName($started, $pane, (string) $dispatch->herdr_agent_name)) {
            $exception = new OrbitPlanningDispatchFailed('Herdr returned an agent outside the recorded planning pane.');
            $this->markBlocked($delivery, $dispatch, AgentDispatchStatus::Ambiguous, 'herdr_agent_identity_ambiguous', $exception);

            throw $exception;
        }

        return $started;
    }

    private function persistStartedAgent(AgentDispatch $dispatch, HerdrAgentIdentifiers $started): void
    {
        $dispatch->forceFill([
            'herdr_workspace_id' => $started->workspaceId,
            'herdr_tab_id' => $started->tabId,
            'herdr_pane_id' => $started->paneId,
            'herdr_terminal_id' => $started->terminalId,
            'herdr_agent_id' => $started->agentId,
            'herdr_agent_name' => $started->agentName,
            'state_change_seq' => $started->stateChangeSeq,
            'dispatched_at' => now(),
            'error_code' => 'planning_final_verification',
            'error_message' => null,
        ])->save();
    }

    private function assertFinalIssue(
        OrbitIssueSnapshot $transitioned,
        OrbitIssueSnapshot $finalIssue,
        string $expectedContractHash,
    ): void {
        $expectedState = $transitioned->payload['state'] ?? null;
        $actualState = $finalIssue->payload['state'] ?? null;

        if ($finalIssue->issueId !== $transitioned->issueId
            || $finalIssue->issueKey !== $transitioned->issueKey
            || ! hash_equals($expectedContractHash, $finalIssue->contractHash)
            || ! is_array($expectedState) || ! is_array($actualState)
            || ($actualState['id'] ?? null) !== ($expectedState['id'] ?? null)
            || ($actualState['name'] ?? null) !== 'In Progress'
            || ($actualState['type'] ?? null) !== 'started'
            || ($finalIssue->payload['delegate'] ?? null) !== ($transitioned->payload['delegate'] ?? null)
            || ! array_key_exists('assignee', $finalIssue->payload)
            || $finalIssue->payload['assignee'] !== ($transitioned->payload['assignee'] ?? null)) {
            throw new OrbitIssueContractChanged('The Orbit issue changed after the planner started and before prompting.');
        }
    }

    private function assertRecoverableCurrentIssue(
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $assignee = $issue->payload['assignee'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $team = $issue->payload['team'] ?? null;
        $states = is_array($team) ? ($team['states'] ?? null) : null;
        $nodes = is_array($states) ? ($states['nodes'] ?? null) : null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $inProgressStates = is_array($nodes)
            ? array_values(array_filter(
                $nodes,
                static fn (mixed $candidate): bool => is_array($candidate)
                    && ($candidate['name'] ?? null) === 'In Progress',
            ))
            : [];

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! hash_equals($preparation->snapshot->contractHash, $issue->contractHash)
            || ! is_string($viewerId)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $viewerId) !== 1
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Progress'
            || ($state['type'] ?? null) !== 'started'
            || count($inProgressStates) !== 1
            || ($state['id'] ?? null) !== ($inProgressStates[0]['id'] ?? null)
            || $assignee !== null
            || ! is_array($delegate)
            || ($delegate['id'] ?? null) !== $viewerId) {
            throw new OrbitIssueContractChanged(
                'The Orbit issue changed before ambiguous planning-start recovery.',
            );
        }
    }

    private function markPromptAttempted(
        int $deliveryId,
        int $phaseId,
        int $dispatchId,
        OrbitProjectConfig $config,
        HerdrAgentIdentifiers $agent,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $dispatchId, $config, $agent): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases
                ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->firstOrFail();
            $expectedKey = IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::INITIAL_PHASE,
                1,
                OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
            )->value;

            if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $delivery->status !== DeliveryStatus::Preparing
                || $delivery->failure_details !== null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $config->toArray()
                || $phases->count() !== 1
                || $phase === null
                || $phase->id !== $phaseId
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Running
                || $phase->finished_at !== null
                || $phase->receipts()->exists()
                || $phase->agentDispatches()->count() !== 1
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PLANNING_AGENT_ROLE
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->status !== AgentDispatchStatus::Starting
                || $dispatch->error_code !== 'planning_final_verification'
                || $dispatch->error_message !== null
                || $dispatch->dispatched_at === null
                || $dispatch->settled_at !== null
                || ! $this->sameAgent($agent, $dispatch)
                || $agent->workingDirectory !== $delivery->worktree_path
                || ! in_array($agent->agentStatus, ['idle', 'done'], true)) {
                throw new OrbitPlanningDispatchFailed(
                    'The planning dispatch changed before prompt submission.',
                );
            }

            $dispatch->forceFill([
                'herdr_agent_id' => $agent->agentId ?? $dispatch->herdr_agent_id,
                'state_change_seq' => $agent->stateChangeSeq,
                'error_code' => 'herdr_prompt_attempted',
                'error_message' => null,
            ])->save();
        });
    }

    private function samePaneAndName(
        HerdrAgentIdentifiers $agent,
        HerdrAgentIdentifiers $pane,
        string $name,
    ): bool {
        return $agent->workspaceId === $pane->workspaceId
            && $agent->tabId === $pane->tabId
            && $agent->paneId === $pane->paneId
            && $agent->terminalId === $pane->terminalId
            && $agent->agentName === $name;
    }

    private function sameAgent(HerdrAgentIdentifiers $agent, AgentDispatch $dispatch): bool
    {
        return $agent->workspaceId === $dispatch->herdr_workspace_id
            && $agent->tabId === $dispatch->herdr_tab_id
            && $agent->paneId === $dispatch->herdr_pane_id
            && $agent->terminalId === $dispatch->herdr_terminal_id
            && ($agent->agentId === null
                || $dispatch->herdr_agent_id === null
                || $agent->agentId === $dispatch->herdr_agent_id)
            && $agent->agentName === $dispatch->herdr_agent_name;
    }

    private function planningLaunch(): HerdrAgentLaunch
    {
        return new HerdrAgentLaunch('codex', [
            '-m',
            'gpt-5.6-sol',
            '-c',
            'model_reasoning_effort="high"',
            '-c',
            'features.multi_agent=true',
            '-c',
            'mcp_servers.context7.enabled=false',
            '-c',
            'mcp_servers.solo_nick.enabled=false',
            '-c',
            'mcp_servers.solo_mini.enabled=false',
            '--dangerously-bypass-approvals-and-sandbox',
        ], 120_000);
    }

    private function receiptCommand(PhaseRun $phaseRun, AgentDispatch $dispatch): string
    {
        return sprintf(
            '%s %s delivery:submit-orbit-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phaseRun->id,
            $dispatch->id,
        );
    }

    private function markBlocked(
        Delivery $delivery,
        AgentDispatch $dispatch,
        AgentDispatchStatus $status,
        string $code,
        Exception $exception,
    ): void {
        DB::transaction(function () use ($delivery, $dispatch, $status, $code, $exception): void {
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch = AgentDispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
            $lockedDispatch->forceFill([
                'status' => $status,
                'error_code' => $code,
                'error_message' => $exception->getMessage(),
            ])->save();
            $lockedDelivery->status = DeliveryStatus::Blocked;
            $lockedDelivery->failure_details = [
                'code' => $code,
                'dispatch_id' => $lockedDispatch->id,
                'message' => $exception->getMessage(),
            ];
            $lockedDelivery->save();
        });
    }

    private function blockInterruptedDelivery(Delivery $delivery, AgentDispatch $dispatch): void
    {
        DB::transaction(function () use ($delivery, $dispatch): void {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeliveryStatus::Preparing) {
                return;
            }

            $locked->status = DeliveryStatus::Blocked;
            $locked->failure_details = [
                'code' => 'planning_dispatch_interrupted',
                'dispatch_id' => $dispatch->id,
                'stage' => $dispatch->error_code,
            ];
            $locked->save();
        });
    }
}
