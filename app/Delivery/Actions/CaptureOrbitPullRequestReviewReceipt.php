<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPullRequestReviewReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewWaitPolicy;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CaptureOrbitPullRequestReviewReceipt
{
    public function __construct(
        private OrbitPullRequestReviewReceiptValidator $receipts,
        private OrbitPullRequestReviewWaitPolicy $waits,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(
        PhaseRun $phaseRun,
        AgentDispatch $dispatch,
        OrbitProjectConfig $expectedConfig,
        array $payload,
    ): Receipt {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $receipt = DB::transaction(function () use (
            $phaseRun,
            $dispatch,
            $expectedConfig,
            $payload,
            $hash,
        ): Receipt {
            $delivery = Delivery::query()->whereKey($phaseRun->delivery_id)->lockForUpdate()->first();
            $project = $delivery?->projectOrchestration()->lockForUpdate()->first();
            $phases = PhaseRun::query()
                ->where('delivery_id', $phaseRun->delivery_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = AgentDispatch::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $storedReceipts = Receipt::query()
                ->whereIn('phase_run_id', $phases->modelKeys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedPhase = $phases->firstWhere('id', $phaseRun->id);
            $lockedDispatch = $dispatches->firstWhere('id', $dispatch->id);
            $latestReview = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $lockedPhase?->id);
            $reviewReceipts = $storedReceipts
                ->where('phase_run_id', $lockedPhase?->id)
                ->where('kind', 'orbit_pr_review');

            if ($delivery !== null && $project !== null) {
                $delivery->setRelation('projectOrchestration', $project);
            }

            $active = $delivery !== null && $lockedPhase !== null && $lockedDispatch !== null
                && ($delivery->status === DeliveryStatus::WaitingForAgent
                    || ($delivery->status === DeliveryStatus::Preparing
                        && $lockedDispatch->status === AgentDispatchStatus::Starting
                        && $lockedDispatch->error_code === 'herdr_prompt_attempted'))
                && $lockedPhase->status === PhaseRunStatus::Running
                && (($lockedDispatch->status === AgentDispatchStatus::Starting
                    && $lockedDispatch->error_code === 'herdr_prompt_attempted')
                    || in_array($lockedDispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true));
            $recovering = $delivery !== null && $lockedPhase !== null && $lockedDispatch !== null
                && $this->canRecoverLateReceipt(
                    $delivery,
                    $lockedPhase,
                    $lockedDispatch,
                    $phaseDispatches->count(),
                    $reviewReceipts->isNotEmpty(),
                );

            if ($delivery === null || $project === null || $lockedPhase === null || $lockedDispatch === null
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $expectedConfig->toArray()
                || $latestReview?->id !== $lockedPhase->id
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $lockedPhase->delivery_id !== $delivery->id
                || $lockedPhase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || ! in_array($lockedPhase->attempt, [1, 2], true)
                || $phaseDispatches->count() !== 1
                || $lockedDispatch->phase_run_id !== $lockedPhase->id
                || $lockedDispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
                || (! $active && ! $recovering)
                || ! $this->receipts->matchesPayload($delivery, $lockedPhase, $lockedDispatch, $payload)) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The pull request review receipt no longer matches the active dispatch.',
                );
            }

            $existing = $reviewReceipts->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new OrbitPullRequestReviewReceiptFailed(
                        'A different pull request review receipt was already captured for this phase.',
                    );
                }

                return $existing;
            }

            $receipt = Receipt::query()->create([
                'phase_run_id' => $lockedPhase->id,
                'kind' => 'orbit_pr_review',
                'schema_version' => 1,
                'payload' => $payload,
                'payload_hash' => $hash,
                'candidate_sha' => $payload['candidate_sha'],
                'validation_status' => ReceiptValidationStatus::Valid,
                'validation_errors' => null,
                'captured_at' => now(),
                'validated_at' => now(),
            ]);

            if ($recovering) {
                $lockedPhase->status = PhaseRunStatus::Running;
                $lockedPhase->failure_code = null;
                $lockedPhase->failure_message = null;
                $lockedPhase->failure_details = null;
                $lockedPhase->finished_at = null;
                $lockedPhase->save();

                $delivery->status = DeliveryStatus::WaitingForAgent;
                $delivery->failure_details = null;
                $delivery->save();
            }

            return $receipt;
        });

        AdvanceDelivery::dispatch($phaseRun->delivery_id)->afterCommit();

        return $receipt;
    }

    public function canRecoverLateReceipt(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        int $phaseDispatchCount,
        bool $hasReviewReceipt,
    ): bool {
        $details = $phase->failure_details;

        if (! is_array($details)
            || $delivery->status !== DeliveryStatus::Blocked
            || $delivery->failure_details !== $details
            || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || ! in_array($phase->attempt, [1, 2], true)
            || $phase->status !== PhaseRunStatus::Failed
            || $phase->current_block !== null
            || $phase->output !== null
            || $phase->failure_code !== 'pr_review_receipt_missing'
            || $phase->failure_message !== 'The settled pull request reviewer did not submit its receipt within the grace period.'
            || $phase->started_at === null
            || $phase->finished_at === null
            || $phaseDispatchCount !== 1
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->dispatched_at === null
            || $dispatch->settled_at === null
            || $dispatch->state_change_seq === null
            || $hasReviewReceipt) {
            return false;
        }

        $observedAt = $details['observed_at'] ?? null;

        if (! is_string($observedAt)) {
            return false;
        }

        try {
            $observed = CarbonImmutable::parse($observedAt);
        } catch (Throwable) {
            return false;
        }

        if (! $observed->startOfSecond()->equalTo($phase->finished_at->startOfSecond())) {
            return false;
        }

        return $details === [
            'code' => 'pr_review_receipt_missing',
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
            'phase_attempt' => $phase->attempt,
            'dispatch_status' => AgentDispatchStatus::Settled->value,
            'herdr_session' => $dispatch->herdr_session,
            'herdr_workspace_id' => $dispatch->herdr_workspace_id,
            'herdr_tab_id' => $dispatch->herdr_tab_id,
            'herdr_pane_id' => $dispatch->herdr_pane_id,
            'herdr_terminal_id' => $dispatch->herdr_terminal_id,
            'herdr_agent_id' => $dispatch->herdr_agent_id,
            'herdr_agent_name' => $dispatch->herdr_agent_name,
            'dispatched_at' => $dispatch->dispatched_at->toISOString(),
            'settled_at' => $dispatch->settled_at->toISOString(),
            'deadline_at' => $this->waits->receiptDeadline($dispatch->settled_at)->toISOString(),
            'observed_at' => $observedAt,
            'state_change_seq' => $dispatch->state_change_seq,
            'observed_agent_status' => null,
            'observed_state_change_seq' => null,
            'observed_working_directory' => null,
        ];
    }
}
