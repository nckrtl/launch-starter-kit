<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewWaitPolicy;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ReconcileOrbitPullRequestReviewWait
{
    public function __construct(private OrbitPullRequestReviewWaitPolicy $policy) {}

    public function handle(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        ?HerdrAgentIdentifiers $observation,
    ): bool {
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $observation,
            $observedAt,
        ): bool {
            $state = $this->lockedState($deliveryId, $phaseRunId, $dispatchId);

            if ($state === null) {
                return false;
            }

            [$delivery, $phase, $dispatch, $receipts] = $state;

            if ($dispatch->status === AgentDispatchStatus::Settled) {
                return $this->blockMissingReceipt(
                    $delivery,
                    $phase,
                    $dispatch,
                    $receipts,
                    $observedAt,
                );
            }

            if ($dispatch->dispatched_at === null) {
                return $this->block(
                    $delivery,
                    $phase,
                    $dispatch,
                    'pr_review_identity_changed',
                    'The waiting pull request reviewer has no dispatch timestamp.',
                    $observedAt,
                    $observedAt,
                    $observation,
                );
            }

            $deadline = $this->policy->waitDeadline($dispatch->dispatched_at);

            if (! $this->policy->isOverdue($deadline, $observedAt)) {
                return false;
            }

            if ($observation === null || ! $this->matchesObservation($delivery, $dispatch, $observation)) {
                return $this->block(
                    $delivery,
                    $phase,
                    $dispatch,
                    'pr_review_identity_changed',
                    'Herdr no longer reports the exact retained pull request reviewer identity.',
                    $deadline,
                    $observedAt,
                    $observation,
                );
            }

            return $this->block(
                $delivery,
                $phase,
                $dispatch,
                'pr_review_wait_timeout',
                'The independent pull request review did not settle before its deadline.',
                $deadline,
                $observedAt,
                $observation,
            );
        });
    }

    public function waitIsOverdue(int $deliveryId, int $phaseRunId, int $dispatchId): bool
    {
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $observedAt,
        ): bool {
            $state = $this->lockedState($deliveryId, $phaseRunId, $dispatchId);

            if ($state === null) {
                return false;
            }

            $dispatch = $state[2];

            return $dispatch->status === AgentDispatchStatus::Waiting
                && $dispatch->dispatched_at !== null
                && $this->policy->isOverdue(
                    $this->policy->waitDeadline($dispatch->dispatched_at),
                    $observedAt,
                );
        });
    }

    public function isActiveReview(int $deliveryId, int $phaseRunId, int $dispatchId): bool
    {
        return DB::transaction(
            fn (): bool => $this->lockedState($deliveryId, $phaseRunId, $dispatchId) !== null,
        );
    }

    public function blockIdentityFailure(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        Throwable $exception,
    ): bool {
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $exception,
            $observedAt,
        ): bool {
            $state = $this->lockedState($deliveryId, $phaseRunId, $dispatchId);

            if ($state === null) {
                return false;
            }

            [$delivery, $phase, $dispatch] = $state;

            if ($dispatch->status !== AgentDispatchStatus::Waiting) {
                return false;
            }

            $deadline = $dispatch->dispatched_at === null
                ? $observedAt
                : $this->policy->waitDeadline($dispatch->dispatched_at);

            if (! $this->policy->isOverdue($deadline, $observedAt)) {
                return false;
            }

            return $this->block(
                $delivery,
                $phase,
                $dispatch,
                'pr_review_identity_changed',
                $exception->getMessage(),
                $deadline,
                $observedAt,
            );
        });
    }

    public function blockObservationFailure(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        ?Throwable $exception,
    ): bool {
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $exception,
            $observedAt,
        ): bool {
            $state = $this->lockedState($deliveryId, $phaseRunId, $dispatchId);

            if ($state === null) {
                return false;
            }

            [$delivery, $phase, $dispatch] = $state;

            if ($dispatch->status !== AgentDispatchStatus::Waiting
                || $dispatch->dispatched_at === null) {
                return false;
            }

            $deadline = $this->policy->waitDeadline($dispatch->dispatched_at);

            if (! $this->policy->isOverdue($deadline, $observedAt)) {
                return false;
            }

            return $this->block(
                $delivery,
                $phase,
                $dispatch,
                'pr_review_observation_failed',
                $exception?->getMessage() ?? 'Commander exhausted retries while observing the retained Herdr reviewer.',
                $deadline,
                $observedAt,
            );
        });
    }

    /**
     * @return array{Delivery, PhaseRun, AgentDispatch, Collection<int, Receipt>}|null
     */
    private function lockedState(int $deliveryId, int $phaseRunId, int $dispatchId): ?array
    {
        $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

        if ($delivery === null) {
            return null;
        }

        $project = $delivery->projectOrchestration()->lockForUpdate()->first();
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
        $phaseDispatches = $dispatches->where('phase_run_id', $phaseRunId);
        $latestReview = $phases
            ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
            ->sortByDesc('attempt')
            ->first();

        if ($project === null
            || $project->state !== ProjectOrchestrationState::Enabled
            || $delivery->project_orchestration_id !== $project->id
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->status !== DeliveryStatus::WaitingForAgent
            || $delivery->failure_details !== null
            || ! is_string($delivery->worktree_path)
            || $delivery->worktree_path === ''
            || $phase === null
            || $latestReview?->id !== $phase->id
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Running
            || $phase->failure_code !== null
            || $phase->failure_message !== null
            || $phase->failure_details !== null
            || $phase->started_at === null
            || $phase->finished_at !== null
            || $phaseDispatches->count() !== 1
            || $dispatch === null
            || $phaseDispatches->first()?->id !== $dispatch->id
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
            || ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true)) {
            return null;
        }

        return [
            $delivery,
            $phase,
            $dispatch,
            $receipts->where('phase_run_id', $phase->id)
                ->where('kind', 'orbit_pr_review')
                ->values(),
        ];
    }

    /** @param Collection<int, Receipt> $receipts */
    private function blockMissingReceipt(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Collection $receipts,
        CarbonImmutable $observedAt,
    ): bool {
        if ($receipts->isNotEmpty()) {
            return false;
        }

        if ($dispatch->settled_at === null) {
            return $this->block(
                $delivery,
                $phase,
                $dispatch,
                'pr_review_identity_changed',
                'The settled pull request reviewer has no settlement timestamp.',
                $observedAt,
                $observedAt,
            );
        }

        $deadline = $this->policy->receiptDeadline($dispatch->settled_at);

        if (! $this->policy->isOverdue($deadline, $observedAt)) {
            return false;
        }

        return $this->block(
            $delivery,
            $phase,
            $dispatch,
            'pr_review_receipt_missing',
            'The settled pull request reviewer did not submit its receipt within the grace period.',
            $deadline,
            $observedAt,
        );
    }

    private function matchesObservation(
        Delivery $delivery,
        AgentDispatch $dispatch,
        HerdrAgentIdentifiers $observation,
    ): bool {
        return $dispatch->herdr_session === config('herdr.session')
            && is_string($dispatch->herdr_workspace_id) && $dispatch->herdr_workspace_id !== ''
            && is_string($dispatch->herdr_tab_id) && $dispatch->herdr_tab_id !== ''
            && is_string($dispatch->herdr_pane_id) && $dispatch->herdr_pane_id !== ''
            && is_string($dispatch->herdr_terminal_id) && $dispatch->herdr_terminal_id !== ''
            && is_string($dispatch->herdr_agent_id) && $dispatch->herdr_agent_id !== ''
            && is_string($dispatch->herdr_agent_name) && $dispatch->herdr_agent_name !== ''
            && $dispatch->state_change_seq !== null
            && $observation->workspaceId === $dispatch->herdr_workspace_id
            && $observation->tabId === $dispatch->herdr_tab_id
            && $observation->paneId === $dispatch->herdr_pane_id
            && $observation->terminalId === $dispatch->herdr_terminal_id
            && $observation->agentId === $dispatch->herdr_agent_id
            && $observation->agentName === $dispatch->herdr_agent_name
            && $observation->workingDirectory !== null
            && $observation->workingDirectory === $delivery->worktree_path;
    }

    private function block(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        string $code,
        string $message,
        CarbonImmutable $deadline,
        CarbonImmutable $observedAt,
        ?HerdrAgentIdentifiers $observation = null,
    ): bool {
        $details = [
            'code' => $code,
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
            'phase_attempt' => $phase->attempt,
            'dispatch_status' => $dispatch->status->value,
            'herdr_session' => $dispatch->herdr_session,
            'herdr_workspace_id' => $dispatch->herdr_workspace_id,
            'herdr_tab_id' => $dispatch->herdr_tab_id,
            'herdr_pane_id' => $dispatch->herdr_pane_id,
            'herdr_terminal_id' => $dispatch->herdr_terminal_id,
            'herdr_agent_id' => $dispatch->herdr_agent_id,
            'herdr_agent_name' => $dispatch->herdr_agent_name,
            'dispatched_at' => $dispatch->dispatched_at?->toISOString(),
            'settled_at' => $dispatch->settled_at?->toISOString(),
            'deadline_at' => $deadline->toISOString(),
            'observed_at' => $observedAt->toISOString(),
            'state_change_seq' => $dispatch->state_change_seq,
            'observed_agent_status' => $observation?->agentStatus,
            'observed_state_change_seq' => $observation?->stateChangeSeq,
            'observed_working_directory' => $observation?->workingDirectory,
        ];

        $phase->status = PhaseRunStatus::Failed;
        $phase->failure_code = $code;
        $phase->failure_message = $message;
        $phase->failure_details = $details;
        $phase->finished_at ??= $observedAt;
        $phase->save();

        $delivery->status = DeliveryStatus::Blocked;
        $delivery->failure_details = $details;
        $delivery->save();

        return true;
    }
}
