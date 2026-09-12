<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

final readonly class ShutdownOrbitHerdrWorkspace
{
    private const int STATE_SCHEMA = 1;

    private const int MINIMUM_PROTOCOL = 20;

    private const int AGENT_EXIT_ATTEMPTS = 11;

    private const int SHELL_READY_ATTEMPTS = 21;

    private const int POLL_MICROSECONDS = 100_000;

    public function __construct(private HerdrWorkspaceRuntime $herdr) {}

    /** Return false when owned agents are still exiting and the owning job should retry. */
    public function handle(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): bool {
        [$delivery, $phase, $identity] = $this->ledger($config, $deliveryId, $phaseId);
        $snapshot = $this->herdr->snapshot();
        $this->assertSnapshot($snapshot);
        $state = $this->state($phase);
        $workspace = $this->workspace($snapshot, $identity['workspace_id']);

        if ($state === null) {
            if ($workspace === null) {
                if (! $this->isCleanupLedger($delivery, $phase)) {
                    throw new OrbitLandingAdvancementFailed(
                        'The recorded Orbit Herdr workspace disappeared before Commander requested its shutdown.',
                    );
                }

                $this->assertRetainedWorkspaceAbsent($snapshot, $identity);
                $state = $this->initialAbsentState($snapshot, $identity, $delivery);
                $this->persistState($deliveryId, $phaseId, null, $state);

                return true;
            }

            $this->assertWorkspace($workspace, $config, $delivery);
            $this->assertOwnedAgents($snapshot, $identity, $delivery);
            $state = $this->initialState($snapshot, $identity, $delivery);
            $state = $this->persistState($deliveryId, $phaseId, null, $state);
        } else {
            $this->assertStateIdentity($state, $identity, $delivery);
            $this->assertProtectedIdentities($state, $snapshot);
        }

        if ($workspace === null) {
            if ($this->isAlreadyAbsentState($state)) {
                if (! $this->isCleanupLedger($delivery, $phase)) {
                    throw new OrbitLandingAdvancementFailed(
                        'A reconciled absent workspace is not valid for Orbit landing cleanup.',
                    );
                }

                $this->assertRetainedWorkspaceAbsent($snapshot, $identity);

                return true;
            }

            if (($state['workspace_close_attempted_at'] ?? null) === null) {
                throw new OrbitLandingAdvancementFailed(
                    'The recorded Orbit Herdr workspace disappeared without a retained close intent.',
                );
            }

            $this->assertClosedPostcondition($state, $snapshot);
            $this->persistClosed($deliveryId, $phaseId, $state);

            return true;
        }

        if (($state['workspace_close_attempted_at'] ?? null) !== null) {
            throw new OrbitLandingAdvancementFailed(
                'The prior Orbit Herdr workspace close outcome is unresolved and will not be replayed.',
            );
        }

        $this->assertWorkspace($workspace, $config, $delivery);
        $this->assertOwnedAgents($snapshot, $identity, $delivery);
        [$snapshot, $state] = $this->exitAgents(
            $config,
            $deliveryId,
            $phaseId,
            $delivery,
            $identity,
            $snapshot,
            $state,
        );

        if ($this->targetAgents($snapshot, $identity['workspace_id']) !== []) {
            return false;
        }

        $state = $this->protectCurrentIdentities($deliveryId, $phaseId, $state, $snapshot);
        $panes = $this->targetPanes($snapshot, $identity['workspace_id']);

        if ($panes === []) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit Herdr workspace has no pane whose idle shell can be verified.',
            );
        }

        foreach ($panes as $pane) {
            $this->awaitIdleShell($pane);
        }

        $beforeClose = $this->herdr->snapshot();
        $this->assertSnapshot($beforeClose);
        $this->assertProtectedIdentities($state, $beforeClose);
        $workspace = $this->workspace($beforeClose, $identity['workspace_id']);

        if ($workspace === null) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit Herdr workspace disappeared before Commander recorded its close intent.',
            );
        }

        $this->assertWorkspace($workspace, $config, $delivery);

        if ($this->targetAgents($beforeClose, $identity['workspace_id']) !== []) {
            return false;
        }

        $currentPanes = $this->targetPanes($beforeClose, $identity['workspace_id']);

        if ($this->paneIds($currentPanes) !== $this->paneIds($panes)) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit Herdr workspace panes changed after idle-shell verification.',
            );
        }

        $state = $this->protectCurrentIdentities($deliveryId, $phaseId, $state, $beforeClose);
        $attempted = $state;
        $attempted['workspace_close_attempted_at'] = now()->toISOString();
        $state = $this->persistState($deliveryId, $phaseId, $state, $attempted);
        $this->herdr->closeWorkspace($identity['workspace_id'], $beforeClose->protocol);
        $afterClose = $this->herdr->snapshot();
        $this->assertSnapshot($afterClose);
        $this->assertClosedPostcondition($state, $afterClose);
        $this->persistClosed($deliveryId, $phaseId, $state);

        return true;
    }

    /**
     * @return array{Delivery, PhaseRun, array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}}
     */
    private function ledger(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): array {
        $delivery = Delivery::query()->findOrFail($deliveryId);
        $phase = PhaseRun::query()->findOrFail($phaseId);
        $dispatches = AgentDispatch::query()
            ->whereHas('phaseRun', fn ($query) => $query->where('delivery_id', $deliveryId))
            ->orderBy('id')
            ->get();
        $sessions = [];
        $workspaces = [];
        $names = [];
        $paneIds = [];
        $terminalIds = [];

        foreach ($dispatches as $dispatch) {
            $retainedWaitingReviewer = $this->isRetainedWaitingReviewer(
                $delivery,
                $phase,
                $dispatch,
            );

            if (($dispatch->status !== AgentDispatchStatus::Settled && ! $retainedWaitingReviewer)
                || ! is_string($dispatch->herdr_session) || $dispatch->herdr_session === ''
                || ! is_string($dispatch->herdr_workspace_id) || $dispatch->herdr_workspace_id === ''
                || ! is_string($dispatch->herdr_pane_id) || $dispatch->herdr_pane_id === ''
                || ! is_string($dispatch->herdr_terminal_id) || $dispatch->herdr_terminal_id === ''
                || ! is_string($dispatch->herdr_agent_name) || $dispatch->herdr_agent_name === '') {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit delivery has an incomplete Herdr dispatch identity.',
                );
            }

            $sessions[] = $dispatch->herdr_session;
            $workspaces[] = $dispatch->herdr_workspace_id;
            $names[] = $dispatch->herdr_agent_name;
            $paneIds[] = $dispatch->herdr_pane_id;
            $terminalIds[] = $dispatch->herdr_terminal_id;
        }

        $sessions = array_values(array_unique($sessions));
        $workspaces = array_values(array_unique($workspaces));
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
        $paneIds = $this->sortedStrings($paneIds);
        $terminalIds = $this->sortedStrings($terminalIds);

        $landingLedger = $delivery->status === DeliveryStatus::Landed
            && $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
            && $phase->phase_name === OrbitFeatureWorkflow::LANDING_PHASE;
        $cleanupLedger = $delivery->status === DeliveryStatus::Cleaning
            && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
            && $phase->phase_name === OrbitFeatureWorkflow::CLEANUP_PHASE;

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || (! $landingLedger && ! $cleanupLedger)
            || $phase->delivery_id !== $delivery->id
            || $phase->attempt !== 1 || $phase->status !== PhaseRunStatus::Running
            || $phase->current_block !== 'workspace_shutdown'
            || ! is_array($phase->output)
            || ! is_string($delivery->worktree_path)
            || $delivery->worktree_path === $config->repository
            || $dispatches->isEmpty() || count($sessions) !== 1 || count($workspaces) !== 1
            || $sessions[0] !== $config->herdrSession
            || $config->herdrSession !== config('herdr.session')) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit delivery cannot identify one owned Herdr worktree workspace.',
            );
        }

        return [$delivery, $phase, [
            'session' => $sessions[0],
            'workspace_id' => $workspaces[0],
            'agent_names' => $names,
            'pane_ids' => $paneIds,
            'terminal_ids' => $terminalIds,
        ]];
    }

    private function isRetainedWaitingReviewer(
        Delivery $delivery,
        PhaseRun $cleanup,
        AgentDispatch $dispatch,
    ): bool {
        if ($dispatch->status !== AgentDispatchStatus::Waiting
            || ! $this->isCleanupLedger($delivery, $cleanup)) {
            return false;
        }

        $input = $cleanup->input;
        $source = is_array($input) ? ($input['source'] ?? null) : null;
        $sourceFailure = is_array($source) ? ($source['failure_details'] ?? null) : null;
        $deliveryFailure = is_array($source) ? ($source['delivery_failure_details'] ?? null) : null;
        $sourcePhase = PhaseRun::query()->find($dispatch->phase_run_id);
        $code = is_array($source) ? ($source['failure_code'] ?? null) : null;

        return is_array($source)
            && is_array($sourceFailure)
            && is_array($deliveryFailure)
            && in_array($code, ['pr_review_wait_timeout', 'pr_review_identity_changed'], true)
            && ($source['phase_run_id'] ?? null) === $dispatch->phase_run_id
            && ($source['phase_name'] ?? null) === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && ($source['current_phase'] ?? null) === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && ($sourceFailure['code'] ?? null) === $code
            && ($sourceFailure['phase_run_id'] ?? null) === $dispatch->phase_run_id
            && ($sourceFailure['dispatch_id'] ?? null) === $dispatch->id
            && $deliveryFailure === $sourceFailure
            && $delivery->failure_details === $deliveryFailure
            && $sourcePhase !== null
            && $sourcePhase->delivery_id === $delivery->id
            && $sourcePhase->phase_name === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && $sourcePhase->status === PhaseRunStatus::Failed
            && $sourcePhase->failure_code === $code
            && $sourcePhase->failure_details === $sourceFailure
            && $sourcePhase->finished_at !== null;
    }

    /**
     * @param  array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}  $identity
     * @return array<string, mixed>
     */
    private function initialAbsentState(
        HerdrSessionSnapshot $snapshot,
        array $identity,
        Delivery $delivery,
    ): array {
        return [
            'schema' => self::STATE_SCHEMA,
            'protocol' => $snapshot->protocol,
            'session' => $identity['session'],
            'workspace_id' => $identity['workspace_id'],
            'worktree_path' => $delivery->worktree_path,
            'owned_agent_names' => $identity['agent_names'],
            'protected_workspace_ids' => $this->workspaceIds($snapshot, $identity['workspace_id']),
            'protected_agent_terminal_ids' => $this->agentTerminalIds($snapshot, $identity['workspace_id']),
            'protected_pane_ids' => $this->snapshotPaneIds($snapshot, $identity['workspace_id']),
            'target_agent_terminal_ids' => $identity['terminal_ids'],
            'target_pane_ids' => $identity['pane_ids'],
            'exit_attempted' => [],
            'exit_submitted' => [],
            'workspace_close_attempted_at' => null,
            'closed' => [
                'session' => $identity['session'],
                'workspace_id' => $identity['workspace_id'],
                'worktree_path' => $delivery->worktree_path,
                'owned_agent_names' => $identity['agent_names'],
                'disposition' => 'already_absent',
                'verified_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @param  array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}  $identity
     * @return array<string, mixed>
     */
    private function initialState(
        HerdrSessionSnapshot $snapshot,
        array $identity,
        Delivery $delivery,
    ): array {
        $targetAgents = $this->targetAgents($snapshot, $identity['workspace_id']);
        $targetPanes = $this->targetPanes($snapshot, $identity['workspace_id']);

        return [
            'schema' => self::STATE_SCHEMA,
            'protocol' => $snapshot->protocol,
            'session' => $identity['session'],
            'workspace_id' => $identity['workspace_id'],
            'worktree_path' => $delivery->worktree_path,
            'owned_agent_names' => $identity['agent_names'],
            'protected_workspace_ids' => $this->workspaceIds($snapshot, $identity['workspace_id']),
            'protected_agent_terminal_ids' => $this->agentTerminalIds($snapshot, $identity['workspace_id']),
            'protected_pane_ids' => $this->snapshotPaneIds($snapshot, $identity['workspace_id']),
            'target_agent_terminal_ids' => $this->terminalIds($targetAgents),
            'target_pane_ids' => $this->paneIds($targetPanes),
            'exit_attempted' => [],
            'exit_submitted' => [],
            'workspace_close_attempted_at' => null,
            'closed' => null,
        ];
    }

    /**
     * @param  array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}  $identity
     * @param  array<string, mixed>  $state
     */
    private function assertStateIdentity(array $state, array $identity, Delivery $delivery): void
    {
        $closeAttemptedAt = $state['workspace_close_attempted_at'] ?? null;
        $closed = $state['closed'] ?? null;
        $this->requiredStateInteger($state, 'protocol');

        if (($state['schema'] ?? null) !== self::STATE_SCHEMA
            || ($state['session'] ?? null) !== $identity['session']
            || ($state['workspace_id'] ?? null) !== $identity['workspace_id']
            || ($state['worktree_path'] ?? null) !== $delivery->worktree_path
            || ($state['owned_agent_names'] ?? null) !== $identity['agent_names']
            || ! $this->hasStringList($state, 'protected_workspace_ids')
            || ! $this->hasStringList($state, 'protected_agent_terminal_ids')
            || ! $this->hasStringList($state, 'protected_pane_ids')
            || ! $this->hasStringList($state, 'target_agent_terminal_ids')
            || ! $this->hasStringList($state, 'target_pane_ids')
            || ($closeAttemptedAt !== null && (! is_string($closeAttemptedAt) || $closeAttemptedAt === ''))
            || ($closed !== null && ! $this->matchesClosedState($state, $closed))) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr workspace shutdown state is inconsistent.',
            );
        }

        $this->map($state, 'exit_attempted');
        $this->map($state, 'exit_submitted');
    }

    /**
     * @param  array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}  $identity
     * @param  array<string, mixed>  $state
     * @return array{HerdrSessionSnapshot, array<string, mixed>}
     */
    private function exitAgents(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
        Delivery $delivery,
        array $identity,
        HerdrSessionSnapshot $snapshot,
        array $state,
    ): array {
        for ($attempt = 0; $attempt < self::AGENT_EXIT_ATTEMPTS; $attempt++) {
            $agents = $this->targetAgents($snapshot, $identity['workspace_id']);

            if ($agents === []) {
                return [$snapshot, $state];
            }

            $this->assertOwnedAgents($snapshot, $identity, $delivery);

            foreach ($agents as $agent) {
                if (! is_string($agent->name)) {
                    throw new OrbitLandingAdvancementFailed(
                        'The Orbit Herdr workspace has an unnamed agent.',
                    );
                }

                $name = $agent->name;
                $command = $agent->kind === 'claude' ? '/exit' : '/quit';
                $exitAttempted = $this->map($state, 'exit_attempted');
                $exitSubmitted = $this->map($state, 'exit_submitted');
                $attempted = $exitAttempted[$name] ?? null;
                $submitted = $exitSubmitted[$name] ?? null;

                if ($attempted === null) {
                    $next = $state;
                    $exitAttempted[$name] = [
                        'command' => $command,
                        'attempted_at' => now()->toISOString(),
                    ];
                    ksort($exitAttempted, SORT_STRING);
                    $next['exit_attempted'] = $exitAttempted;
                    $state = $this->persistState($deliveryId, $phaseId, $state, $next);
                    $this->herdr->sendAgentKeys($name, [...str_split($command), 'enter']);

                    continue;
                }

                if (! is_array($attempted) || ($attempted['command'] ?? null) !== $command) {
                    throw new OrbitLandingAdvancementFailed(
                        'The retained Orbit Herdr agent exit intent is inconsistent.',
                    );
                }

                if ($submitted === null) {
                    $output = $this->herdr->readAgent($name);

                    if ($this->matchesAgentOutput($agent, $output)
                        && $this->showsExactExitCommand($output->text, $command)) {
                        $next = $state;
                        $exitSubmitted[$name] = [
                            'command' => $command,
                            'submitted_at' => now()->toISOString(),
                        ];
                        ksort($exitSubmitted, SORT_STRING);
                        $next['exit_submitted'] = $exitSubmitted;
                        $state = $this->persistState($deliveryId, $phaseId, $state, $next);
                        $this->herdr->sendAgentKeys($name, ['enter']);
                    }
                } elseif (! is_array($submitted) || ($submitted['command'] ?? null) !== $command) {
                    throw new OrbitLandingAdvancementFailed(
                        'The retained Orbit Herdr agent exit submission is inconsistent.',
                    );
                }
            }

            if ($attempt < self::AGENT_EXIT_ATTEMPTS - 1) {
                Sleep::usleep(self::POLL_MICROSECONDS);
                $snapshot = $this->herdr->snapshot();
                $this->assertSnapshot($snapshot);
                $this->assertProtectedIdentities($state, $snapshot);
                $workspace = $this->workspace($snapshot, $identity['workspace_id']);

                if ($workspace === null) {
                    throw new OrbitLandingAdvancementFailed(
                        'The Orbit Herdr workspace disappeared while its agents were exiting.',
                    );
                }

                $this->assertWorkspace($workspace, $config, $delivery);
            }
        }

        return [$snapshot, $state];
    }

    private function awaitIdleShell(HerdrSnapshotPane $pane): void
    {
        for ($attempt = 0; $attempt < self::SHELL_READY_ATTEMPTS; $attempt++) {
            $process = $this->herdr->inspectPaneProcess($pane->paneId);

            if ($this->isIdleShell($pane, $process)) {
                return;
            }

            if ($attempt < self::SHELL_READY_ATTEMPTS - 1) {
                Sleep::usleep(self::POLL_MICROSECONDS);
            }
        }

        throw new OrbitLandingAdvancementFailed(
            "The Orbit Herdr pane [{$pane->paneId}] is not an idle shell; its workspace was preserved.",
        );
    }

    private function isIdleShell(HerdrSnapshotPane $pane, HerdrPaneProcessInfo $process): bool
    {
        $foreground = $process->foregroundProcesses;

        return $process->paneId === $pane->paneId
            && $process->shellProcessId !== null
            && $process->foregroundProcessGroupId === $process->shellProcessId
            && count($foreground) === 1
            && $foreground[0]->processId === $process->shellProcessId
            && in_array($foreground[0]->name, ['zsh', 'bash', 'sh', 'fish'], true);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function protectCurrentIdentities(
        int $deliveryId,
        int $phaseId,
        array $state,
        HerdrSessionSnapshot $snapshot,
    ): array {
        $workspaceId = $this->requiredStateString($state, 'workspace_id');
        $next = $state;
        $next['protected_workspace_ids'] = $this->mergedStrings(
            $this->stringList($state, 'protected_workspace_ids'),
            $this->workspaceIds($snapshot, $workspaceId),
        );
        $next['protected_agent_terminal_ids'] = $this->mergedStrings(
            $this->stringList($state, 'protected_agent_terminal_ids'),
            $this->agentTerminalIds($snapshot, $workspaceId),
        );
        $next['protected_pane_ids'] = $this->mergedStrings(
            $this->stringList($state, 'protected_pane_ids'),
            $this->snapshotPaneIds($snapshot, $workspaceId),
        );
        $next['target_agent_terminal_ids'] = $this->mergedStrings(
            $this->stringList($state, 'target_agent_terminal_ids'),
            $this->terminalIds($this->targetAgents($snapshot, $workspaceId)),
        );
        $next['target_pane_ids'] = $this->mergedStrings(
            $this->stringList($state, 'target_pane_ids'),
            $this->paneIds($this->targetPanes($snapshot, $workspaceId)),
        );

        return $next === $state
            ? $state
            : $this->persistState($deliveryId, $phaseId, $state, $next);
    }

    /** @param array<string, mixed> $state */
    private function assertProtectedIdentities(array $state, HerdrSessionSnapshot $snapshot): void
    {
        $workspaceIds = array_map(fn (HerdrSnapshotWorkspace $workspace): string => $workspace->workspaceId, $snapshot->workspaces);
        $agentIds = array_map(fn (HerdrSnapshotAgent $agent): string => $agent->terminalId, $snapshot->agents);
        $paneIds = array_map(fn (HerdrSnapshotPane $pane): string => $pane->paneId, $snapshot->panes);

        if ($snapshot->protocol !== $this->requiredStateInteger($state, 'protocol')
            || array_diff($this->stringList($state, 'protected_workspace_ids'), $workspaceIds) !== []
            || array_diff($this->stringList($state, 'protected_agent_terminal_ids'), $agentIds) !== []
            || array_diff($this->stringList($state, 'protected_pane_ids'), $paneIds) !== []) {
            throw new OrbitLandingAdvancementFailed(
                'An unrelated Herdr workspace, agent, or pane disappeared during Orbit cleanup.',
            );
        }
    }

    /** @param array<string, mixed> $state */
    private function assertClosedPostcondition(array $state, HerdrSessionSnapshot $snapshot): void
    {
        $this->assertProtectedIdentities($state, $snapshot);
        $workspaceId = $this->requiredStateString($state, 'workspace_id');
        $agentIds = array_map(fn (HerdrSnapshotAgent $agent): string => $agent->terminalId, $snapshot->agents);
        $paneIds = array_map(fn (HerdrSnapshotPane $pane): string => $pane->paneId, $snapshot->panes);

        if ($this->workspace($snapshot, $workspaceId) !== null
            || array_intersect($this->stringList($state, 'target_agent_terminal_ids'), $agentIds) !== []
            || array_intersect($this->stringList($state, 'target_pane_ids'), $paneIds) !== []) {
            throw new OrbitLandingAdvancementFailed(
                'The Orbit Herdr workspace close postcondition was not satisfied.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function persistClosed(int $deliveryId, int $phaseId, array $state): array
    {
        if (is_array($state['closed'] ?? null)) {
            return $state;
        }

        $next = $state;
        $next['closed'] = [
            'session' => $this->requiredStateString($state, 'session'),
            'workspace_id' => $this->requiredStateString($state, 'workspace_id'),
            'worktree_path' => $this->requiredStateString($state, 'worktree_path'),
            'owned_agent_names' => $this->stringList($state, 'owned_agent_names'),
            'verified_at' => now()->toISOString(),
        ];

        return $this->persistState($deliveryId, $phaseId, $state, $next);
    }

    /** @param array<string, mixed> $state */
    private function matchesClosedState(array $state, mixed $closed): bool
    {
        $disposition = is_array($closed) ? ($closed['disposition'] ?? null) : null;

        return is_array($closed) && ! array_is_list($closed)
            && ($closed['session'] ?? null) === ($state['session'] ?? null)
            && ($closed['workspace_id'] ?? null) === ($state['workspace_id'] ?? null)
            && ($closed['worktree_path'] ?? null) === ($state['worktree_path'] ?? null)
            && ($closed['owned_agent_names'] ?? null) === ($state['owned_agent_names'] ?? null)
            && ($disposition === null || $disposition === 'already_absent')
            && is_string($closed['verified_at'] ?? null)
            && $closed['verified_at'] !== '';
    }

    /**
     * @param  array<string, mixed>|null  $expected
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private function persistState(int $deliveryId, int $phaseId, ?array $expected, array $next): array
    {
        DB::transaction(function () use ($deliveryId, $phaseId, $expected, $next): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();
            $output = $phase->output;
            $current = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;

            $landingLedger = $delivery->status === DeliveryStatus::Landed
                && $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
                && $phase->phase_name === OrbitFeatureWorkflow::LANDING_PHASE;
            $cleanupLedger = $delivery->status === DeliveryStatus::Cleaning
                && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
                && $phase->phase_name === OrbitFeatureWorkflow::CLEANUP_PHASE;

            if ((! $landingLedger && ! $cleanupLedger)
                || $phase->delivery_id !== $delivery->id
                || $phase->status !== PhaseRunStatus::Running
                || $phase->current_block !== 'workspace_shutdown'
                || ! is_array($output) || $current !== $expected) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit delivery ledger changed while recording Herdr workspace shutdown.',
                );
            }

            $phase->output = [...$output, 'workspace_shutdown' => $next];
            $phase->save();
        });

        return $next;
    }

    private function assertSnapshot(HerdrSessionSnapshot $snapshot): void
    {
        $workspaceIds = array_map(fn (HerdrSnapshotWorkspace $workspace): string => $workspace->workspaceId, $snapshot->workspaces);
        $paneIds = array_map(fn (HerdrSnapshotPane $pane): string => $pane->paneId, $snapshot->panes);
        $terminalIds = array_map(fn (HerdrSnapshotAgent $agent): string => $agent->terminalId, $snapshot->agents);

        if ($snapshot->protocol < self::MINIMUM_PROTOCOL
            || count($workspaceIds) !== count(array_unique($workspaceIds))
            || count($paneIds) !== count(array_unique($paneIds))
            || count($terminalIds) !== count(array_unique($terminalIds))) {
            throw new OrbitLandingAdvancementFailed(
                'Herdr returned an unsafe or unsupported session snapshot for Orbit cleanup.',
            );
        }
    }

    private function assertWorkspace(
        HerdrSnapshotWorkspace $workspace,
        OrbitProjectConfig $config,
        Delivery $delivery,
    ): void {
        if ($workspace->repositoryRoot !== $config->repository
            || $workspace->checkoutPath !== $delivery->worktree_path
            || $workspace->checkoutPath === $config->repository
            || $workspace->linkedWorktree !== true) {
            throw new OrbitLandingAdvancementFailed(
                'The recorded Herdr workspace no longer points to the Orbit issue worktree.',
            );
        }
    }

    /**
     * @param  array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>}  $identity
     */
    private function assertRetainedWorkspaceAbsent(
        HerdrSessionSnapshot $snapshot,
        array $identity,
    ): void {
        foreach ($snapshot->workspaces as $workspace) {
            if ($workspace->workspaceId === $identity['workspace_id']) {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit Herdr workspace is still present.',
                );
            }
        }

        foreach ($snapshot->agents as $agent) {
            if ($agent->workspaceId === $identity['workspace_id']
                || in_array($agent->paneId, $identity['pane_ids'], true)
                || in_array($agent->terminalId, $identity['terminal_ids'], true)
                || is_string($agent->name) && in_array($agent->name, $identity['agent_names'], true)) {
                throw new OrbitLandingAdvancementFailed(
                    'A retained Orbit Herdr agent identity is still present.',
                );
            }
        }

        foreach ($snapshot->panes as $pane) {
            if ($pane->workspaceId === $identity['workspace_id']
                || in_array($pane->paneId, $identity['pane_ids'], true)
                || in_array($pane->terminalId, $identity['terminal_ids'], true)) {
                throw new OrbitLandingAdvancementFailed(
                    'A retained Orbit Herdr pane identity is still present.',
                );
            }
        }
    }

    private function isCleanupLedger(Delivery $delivery, PhaseRun $phase): bool
    {
        return $delivery->status === DeliveryStatus::Cleaning
            && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
            && $phase->phase_name === OrbitFeatureWorkflow::CLEANUP_PHASE;
    }

    /** @param array<string, mixed> $state */
    private function isAlreadyAbsentState(array $state): bool
    {
        $closed = $state['closed'] ?? null;

        return is_array($closed) && ($closed['disposition'] ?? null) === 'already_absent';
    }

    /** @param array{session: string, workspace_id: string, agent_names: list<string>, pane_ids: list<string>, terminal_ids: list<string>} $identity */
    private function assertOwnedAgents(
        HerdrSessionSnapshot $snapshot,
        array $identity,
        Delivery $delivery,
    ): void {
        $agents = $this->targetAgents($snapshot, $identity['workspace_id']);
        $names = [];
        $panes = $this->paneIds($this->targetPanes($snapshot, $identity['workspace_id']));

        foreach ($agents as $agent) {
            if (! is_string($agent->name) || $agent->name === ''
                || ! in_array($agent->name, $identity['agent_names'], true)
                || ! in_array($agent->kind, ['codex', 'claude'], true)
                || ! in_array($agent->status, ['idle', 'done'], true)
                || $agent->workingDirectory !== $delivery->worktree_path
                || ! in_array($agent->paneId, $panes, true)
                || in_array($agent->name, $names, true)) {
                throw new OrbitLandingAdvancementFailed(
                    'The Orbit Herdr workspace has an unknown, active, or displaced agent.',
                );
            }

            $names[] = $agent->name;
        }
    }

    private function matchesAgentOutput(HerdrSnapshotAgent $agent, HerdrAgentOutput $output): bool
    {
        return $output->workspaceId === $agent->workspaceId
            && $output->tabId === $agent->tabId
            && $output->paneId === $agent->paneId;
    }

    private function showsExactExitCommand(string $output, string $command): bool
    {
        return preg_match(
            '/^\h*[›❯>]\h*'.preg_quote($command, '/').'\h*$/mu',
            $output,
        ) === 1;
    }

    private function workspace(HerdrSessionSnapshot $snapshot, string $workspaceId): ?HerdrSnapshotWorkspace
    {
        foreach ($snapshot->workspaces as $workspace) {
            if ($workspace->workspaceId === $workspaceId) {
                return $workspace;
            }
        }

        return null;
    }

    /** @return list<HerdrSnapshotAgent> */
    private function targetAgents(HerdrSessionSnapshot $snapshot, string $workspaceId): array
    {
        return array_values(array_filter(
            $snapshot->agents,
            fn (HerdrSnapshotAgent $agent): bool => $agent->workspaceId === $workspaceId,
        ));
    }

    /** @return list<HerdrSnapshotPane> */
    private function targetPanes(HerdrSessionSnapshot $snapshot, string $workspaceId): array
    {
        return array_values(array_filter(
            $snapshot->panes,
            fn (HerdrSnapshotPane $pane): bool => $pane->workspaceId === $workspaceId,
        ));
    }

    /** @return list<string> */
    private function workspaceIds(HerdrSessionSnapshot $snapshot, string $excluded): array
    {
        return $this->sortedStrings(array_values(array_map(
            fn (HerdrSnapshotWorkspace $workspace): string => $workspace->workspaceId,
            array_filter($snapshot->workspaces, fn (HerdrSnapshotWorkspace $workspace): bool => $workspace->workspaceId !== $excluded),
        )));
    }

    /** @return list<string> */
    private function agentTerminalIds(HerdrSessionSnapshot $snapshot, string $excludedWorkspace): array
    {
        return $this->sortedStrings(array_values(array_map(
            fn (HerdrSnapshotAgent $agent): string => $agent->terminalId,
            array_filter($snapshot->agents, fn (HerdrSnapshotAgent $agent): bool => $agent->workspaceId !== $excludedWorkspace),
        )));
    }

    /** @return list<string> */
    private function snapshotPaneIds(HerdrSessionSnapshot $snapshot, string $excludedWorkspace): array
    {
        return $this->sortedStrings(array_values(array_map(
            fn (HerdrSnapshotPane $pane): string => $pane->paneId,
            array_filter($snapshot->panes, fn (HerdrSnapshotPane $pane): bool => $pane->workspaceId !== $excludedWorkspace),
        )));
    }

    /** @param list<HerdrSnapshotAgent> $agents
     * @return list<string>
     */
    private function terminalIds(array $agents): array
    {
        return $this->sortedStrings(array_map(fn (HerdrSnapshotAgent $agent): string => $agent->terminalId, $agents));
    }

    /** @param list<HerdrSnapshotPane> $panes
     * @return list<string>
     */
    private function paneIds(array $panes): array
    {
        return $this->sortedStrings(array_map(fn (HerdrSnapshotPane $pane): string => $pane->paneId, $panes));
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private function mergedStrings(array $left, array $right): array
    {
        return $this->sortedStrings([...$left, ...$right]);
    }

    /** @param array<string, mixed> $values */
    private function hasStringList(array $values, string $key): bool
    {
        $value = $values[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr identity set is malformed.',
            );
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit Herdr identity set is malformed.',
                );
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function map(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr workspace shutdown map is malformed.',
            );
        }

        $result = [];

        foreach ($value as $itemKey => $item) {
            if (! is_string($itemKey)) {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit Herdr workspace shutdown map is malformed.',
                );
            }

            $result[$itemKey] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $values */
    private function requiredStateString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr workspace shutdown identity is malformed.',
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredStateInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) || $value < self::MINIMUM_PROTOCOL) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr workspace shutdown protocol is malformed.',
            );
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function state(PhaseRun $phase): ?array
    {
        $output = $phase->output;
        $state = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;

        if ($state === null) {
            return null;
        }

        if (! is_array($state) || array_is_list($state)) {
            throw new OrbitLandingAdvancementFailed(
                'The retained Orbit Herdr workspace shutdown state is malformed.',
            );
        }

        $result = [];

        foreach ($state as $key => $value) {
            if (! is_string($key)) {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit Herdr workspace shutdown state is malformed.',
                );
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
