<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestResolutionReceiptValidator;
use App\Delivery\Workflow\ValidatedReceipt;
use App\Delivery\Workflow\WorkflowRegistry;
use App\Jobs\AdvanceOrbitCleanup as AdvanceOrbitCleanupJob;
use App\Jobs\AdvanceOrbitImplementation as AdvanceOrbitImplementationJob;
use App\Jobs\AdvanceOrbitLanding as AdvanceOrbitLandingJob;
use App\Jobs\AdvanceOrbitPlanReview as AdvanceOrbitPlanReviewJob;
use App\Jobs\AdvanceOrbitPullRequestReview as AdvanceOrbitPullRequestReviewJob;
use App\Jobs\DispatchOrbitImplementation;
use App\Jobs\DispatchOrbitPlanning as DispatchOrbitPlanningJob;
use App\Jobs\DispatchOrbitPlanningCorrection;
use App\Jobs\DispatchOrbitPlanReview;
use App\Jobs\DispatchOrbitPullRequestResolution;
use App\Jobs\DispatchOrbitPullRequestReview;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AdvanceDeliveryAction
{
    public function __construct(
        private WorkflowRegistry $workflows,
        private HerdrRuntime $herdr,
        private ProjectConfigRegistry $configs,
        private AdvanceOrbitPlanning $advanceOrbitPlanning,
        private OrbitPullRequestResolutionReceiptValidator $resolutionReceipts,
    ) {}

    /** Return true when a continuation should be queued after the caller releases its lock. */
    public function handle(int $deliveryId): bool
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);

        if ($delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION) {
            if ($delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
                && $delivery->status === DeliveryStatus::Cleaning) {
                $cleanup = $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::CLEANUP_PHASE)
                    ->where('attempt', 1)
                    ->first();

                if ($cleanup !== null
                    && in_array($cleanup->status, [PhaseRunStatus::Pending, PhaseRunStatus::Running], true)) {
                    AdvanceOrbitCleanupJob::dispatch($deliveryId, $cleanup->id)->afterCommit();
                }

                return false;
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE) {
                $planning = $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
                    ->latest('attempt')
                    ->first();

                if ($planning?->attempt === 2) {
                    if (in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing], true)) {
                        DispatchOrbitPlanningCorrection::dispatch($deliveryId)->afterCommit();

                        return false;
                    }

                    return $this->advanceOrbitPlanning->handle($deliveryId);
                }

                if ($planning?->attempt === 1 && $delivery->status === DeliveryStatus::Preparing) {
                    DispatchOrbitPlanningJob::dispatch($deliveryId)->afterCommit();

                    return false;
                }

                if ($delivery->status === DeliveryStatus::Queued) {
                    return false;
                }

                return $this->advanceOrbitPlanning->handle($deliveryId);
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing], true)) {
                DispatchOrbitPlanReview::dispatch($deliveryId)->afterCommit();
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::PLAN_REVIEW_PHASE
                && $delivery->status === DeliveryStatus::WaitingForAgent) {
                AdvanceOrbitPlanReviewJob::dispatch($deliveryId)->afterCommit();
            }

            $implementation = $delivery->current_phase === OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                ? $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                    ->latest('attempt')
                    ->first()
                : null;
            if (in_array($implementation?->attempt, [1, 2], true)
                && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing], true)) {
                DispatchOrbitImplementation::dispatch($deliveryId)->afterCommit();
            }

            if (in_array($implementation?->attempt, [1, 2], true)
                && $delivery->status === DeliveryStatus::WaitingForAgent) {
                AdvanceOrbitImplementationJob::dispatch($deliveryId, $implementation->id)->afterCommit();
            }

            $pullRequestReview = $delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                ? $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                    ->latest('attempt')
                    ->first()
                : null;

            if (in_array($pullRequestReview?->attempt, [1, 2], true)
                && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing], true)) {
                DispatchOrbitPullRequestReview::dispatch($deliveryId, $pullRequestReview->id)->afterCommit();
            }

            if (in_array($pullRequestReview?->attempt, [1, 2], true)
                && $delivery->status === DeliveryStatus::WaitingForAgent) {
                AdvanceOrbitPullRequestReviewJob::dispatch($deliveryId, $pullRequestReview->id)->afterCommit();
            }

            $resolution = $delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
                ? $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                    ->latest('attempt')
                    ->first()
                : null;

            if ($resolution?->attempt === 1
                && in_array($delivery->status, [DeliveryStatus::Queued, DeliveryStatus::Preparing], true)) {
                DispatchOrbitPullRequestResolution::dispatch($deliveryId, $resolution->id)->afterCommit();
            }

            if ($resolution?->attempt === 1 && $delivery->status === DeliveryStatus::WaitingForAgent) {
                $this->advanceOrbitResolution($deliveryId, $resolution->id);
            }

            $landing = $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
                ? $delivery->phaseRuns()
                    ->where('phase_name', OrbitFeatureWorkflow::LANDING_PHASE)
                    ->where('attempt', 1)
                    ->first()
                : null;
            $landingIsActive = $landing !== null && (
                ($delivery->status === DeliveryStatus::ReadyToMerge
                    && $landing->status === PhaseRunStatus::Pending)
                || (in_array($delivery->status, [DeliveryStatus::Merging, DeliveryStatus::Landed], true)
                    && $landing->status === PhaseRunStatus::Running)
            );

            if ($landingIsActive) {
                AdvanceOrbitLandingJob::dispatch($deliveryId, $landing->id)->afterCommit();
            }

            return false;
        }

        if ($delivery->status->isTerminal()
            || $delivery->status === DeliveryStatus::Preparing
            || $delivery->status === DeliveryStatus::Paused
            || $delivery->status === DeliveryStatus::Blocked
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled) {
            return false;
        }

        $workflow = $this->workflows->for($delivery);
        $config = $this->configs->hydrate($delivery->projectOrchestration->config);

        if (! $config instanceof OrbitProjectConfig) {
            return false;
        }

        $phase = $workflow->phase($delivery->current_phase);

        $phaseRun = DB::transaction(function () use ($delivery, $phase): PhaseRun {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $latest = PhaseRun::query()
                ->where('delivery_id', $locked->id)
                ->where('phase_name', $phase->name)
                ->latest('attempt')
                ->first();
            $attempt = $latest === null
                ? 1
                : $latest->attempt + ($latest->status === PhaseRunStatus::Completed ? 1 : 0);

            $phaseRun = PhaseRun::query()->firstOrCreate(
                ['delivery_id' => $locked->id, 'phase_name' => $phase->name, 'attempt' => $attempt],
                ['status' => PhaseRunStatus::Running, 'started_at' => now()],
            );

            if ($phaseRun->status === PhaseRunStatus::Pending) {
                $phaseRun->status = PhaseRunStatus::Running;
                $phaseRun->started_at = now();
                $phaseRun->save();
            }

            AgentDispatch::query()->firstOrCreate(
                ['phase_run_id' => $phaseRun->id, 'agent_role' => $phase->agentRole],
                [
                    'idempotency_key' => IdempotencyKey::forDispatch($locked->id, $phase->name, $phaseRun->attempt, $phase->agentRole)->value,
                    'herdr_agent_name' => "commander-{$locked->id}-{$phase->name}-{$phaseRun->attempt}",
                    'prompt_name' => $phase->name,
                    'prompt_version' => 1,
                    'prompt_hash' => hash('sha256', $phase->prompt),
                    'status' => AgentDispatchStatus::Pending,
                ],
            );

            return $phaseRun;
        });

        $dispatch = $phaseRun->agentDispatches()->firstOrFail();
        $prompt = $this->renderPrompt($phase->prompt, $phaseRun, $dispatch);
        $promptHash = hash('sha256', $prompt);

        if (! hash_equals($dispatch->prompt_hash, $promptHash)) {
            $dispatch->prompt_hash = $promptHash;
            $dispatch->save();
        }

        if ($dispatch->status === AgentDispatchStatus::Ambiguous) {
            $this->reconcileAmbiguous($delivery, $dispatch, $prompt, $config);

            return false;
        }

        if ($dispatch->status === AgentDispatchStatus::Pending) {
            $this->startAgent($delivery, $dispatch, $prompt, $config);

            return false;
        }

        if ($dispatch->status !== AgentDispatchStatus::Settled) {
            return false;
        }

        $receipt = $phaseRun->receipts()
            ->where('validation_status', ReceiptValidationStatus::Valid)
            ->first();

        if ($receipt === null) {
            $delivery->status = DeliveryStatus::Blocked;
            $delivery->failure_details = ['code' => 'receipt_missing', 'phase_run_id' => $phaseRun->id];
            $delivery->save();

            return false;
        }

        if ($phaseRun->status === PhaseRunStatus::Completed) {
            return false;
        }

        $receipt->load('phaseRun');
        $transition = $workflow->nextPhase($delivery, new ValidatedReceipt($receipt));

        return DB::transaction(function () use ($delivery, $phaseRun, $receipt, $transition): bool {
            $locked = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedPhase = PhaseRun::query()->whereKey($phaseRun->id)->lockForUpdate()->firstOrFail();

            if ($lockedPhase->status === PhaseRunStatus::Completed) {
                return false;
            }

            $lockedPhase->status = PhaseRunStatus::Completed;
            $lockedPhase->output = ['receipt_id' => $receipt->id];
            $lockedPhase->finished_at = now();
            $lockedPhase->save();

            $locked->status = $transition->status;
            if ($transition->nextPhase !== null) {
                $locked->current_phase = $transition->nextPhase->name;
            }

            if ($transition->status === DeliveryStatus::Completed) {
                $locked->completed_at = now();
                $locked->completion_details = ['receipt_id' => $receipt->id];
            }

            $locked->save();

            return $transition->nextPhase !== null;
        });
    }

    private function advanceOrbitResolution(int $deliveryId, int $phaseRunId): void
    {
        DB::transaction(function () use ($deliveryId, $phaseRunId): void {
            $delivery = Delivery::query()
                ->with('projectOrchestration')
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseRunId)->lockForUpdate()->firstOrFail();
            $dispatches = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->lockForUpdate()
                ->get();
            $dispatch = $dispatches->first();
            $receipts = $phase->receipts()->lockForUpdate()->get();
            $receipt = $receipts->firstWhere('kind', 'orbit_resolution');

            if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::WaitingForAgent
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Running
                || $phase->finished_at !== null
                || $dispatches->count() !== 1
                || $dispatch === null
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE) {
                return;
            }

            if ($dispatch->status !== AgentDispatchStatus::Settled || $receipt === null) {
                return;
            }

            if ($receipts->count() !== 1
                || ! $this->resolutionReceipts->matches($delivery, $phase, $dispatch, $receipt)) {
                $phase->status = PhaseRunStatus::Failed;
                $phase->failure_code = 'resolution_receipt_invalid';
                $phase->failure_message = 'The settled resolver receipt failed immutable validation.';
                $phase->failure_details = [
                    'code' => 'resolution_receipt_invalid',
                    'phase_run_id' => $phase->id,
                    'dispatch_id' => $dispatch->id,
                    'receipt_id' => $receipt->id,
                ];
                $phase->finished_at = now();
                $phase->save();
                $delivery->status = DeliveryStatus::Blocked;
                $delivery->failure_details = $phase->failure_details;
                $delivery->save();

                return;
            }

            $result = $receipt->payload['result'];
            $code = $result === 'proposal' ? 'resolution_proposal_ready' : 'resolution_blocked';
            $phase->status = PhaseRunStatus::Completed;
            $phase->output = [
                'receipt_id' => $receipt->id,
                'result' => $result,
            ];
            $phase->finished_at = now();
            $phase->save();
            $delivery->status = DeliveryStatus::Blocked;
            $delivery->failure_details = [
                'code' => $code,
                'phase_run_id' => $phase->id,
                'dispatch_id' => $dispatch->id,
                'receipt_id' => $receipt->id,
            ];
            $delivery->save();
        });
    }

    private function startAgent(Delivery $delivery, AgentDispatch $dispatch, string $prompt, OrbitProjectConfig $config): void
    {
        $claimed = AgentDispatch::query()
            ->whereKey($dispatch->id)
            ->where('status', AgentDispatchStatus::Pending)
            ->update(['status' => AgentDispatchStatus::Starting]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $opened = $this->herdr->openWorktree($config->repository, (string) $delivery->worktree_path);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_worktree_open_ambiguous', $exception);

            throw $exception;
        }

        try {
            $pane = $this->herdr->splitPane($opened->paneId, (string) $delivery->worktree_path);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_pane_split_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'herdr_session' => $config->herdrSession,
            'herdr_workspace_id' => $pane->workspaceId,
            'herdr_tab_id' => $pane->tabId,
            'herdr_pane_id' => $pane->paneId,
            'herdr_terminal_id' => $pane->terminalId,
        ])->save();

        try {
            $started = $this->herdr->startAgent($pane->paneId, (string) $dispatch->herdr_agent_name);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_start_ambiguous', $exception);

            throw $exception;
        }

        $this->persistStartedAgent($dispatch, $started);
        $this->submitPrompt($delivery, $dispatch, $started->agentName, $prompt);
    }

    private function reconcileAmbiguous(Delivery $delivery, AgentDispatch $dispatch, string $prompt, OrbitProjectConfig $config): void
    {
        if ($dispatch->error_code === 'herdr_prompt_ambiguous') {
            return;
        }

        if ($dispatch->error_code === 'herdr_worktree_open_ambiguous') {
            $dispatch->forceFill(['status' => AgentDispatchStatus::Pending])->save();
            $this->startAgent($delivery, $dispatch, $prompt, $config);

            return;
        }

        if ($dispatch->error_code !== 'herdr_start_ambiguous' || $dispatch->herdr_agent_name === null) {
            return;
        }

        $started = $this->herdr->getAgent($dispatch->herdr_agent_name);

        if ($started->agentName !== $dispatch->herdr_agent_name) {
            return;
        }

        $this->persistStartedAgent($dispatch, $started);
        $this->submitPrompt($delivery, $dispatch, $started->agentName, $prompt);
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
            'error_code' => null,
            'error_message' => null,
        ])->save();
    }

    private function submitPrompt(Delivery $delivery, AgentDispatch $dispatch, string $agentName, string $prompt): void
    {
        try {
            $prompted = $this->herdr->promptAgent($agentName, $prompt);
        } catch (Throwable $exception) {
            $this->markAmbiguous($dispatch, 'herdr_prompt_ambiguous', $exception);

            throw $exception;
        }

        $dispatch->forceFill([
            'status' => AgentDispatchStatus::Waiting,
            'state_change_seq' => $prompted->stateChangeSeq,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $delivery->status = DeliveryStatus::WaitingForAgent;
        $delivery->save();
    }

    private function markAmbiguous(AgentDispatch $dispatch, string $code, Throwable $exception): void
    {
        $dispatch->forceFill([
            'status' => AgentDispatchStatus::Ambiguous,
            'error_code' => $code,
            'error_message' => $exception->getMessage(),
        ])->save();
    }

    private function renderPrompt(string $instructions, PhaseRun $phaseRun, AgentDispatch $dispatch): string
    {
        $command = sprintf(
            '%s %s delivery:submit-shadow-receipt %d %d',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            $phaseRun->id,
            $dispatch->id,
        );

        return implode("\n", [
            $instructions,
            '',
            "Delivery {$phaseRun->delivery_id}; dispatch {$dispatch->id}; phase {$phaseRun->phase_name}; attempt {$phaseRun->attempt}.",
            'Run this from the current worktree:',
            $command,
        ]);
    }
}
