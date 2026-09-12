<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitPlanningResolutionIssueProvider;
use App\Delivery\Contracts\OrbitPlanningResolutionTransitioner;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitPlanningResolutionCorrection;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPlanningResolutionCorrectionFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPlanningResolutionCatalog;
use App\Delivery\Workflow\OrbitResolutionAdoptionPolicy;
use App\Delivery\Workflow\OrbitResolutionReceiptValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ApplyOrbitPlanningResolution
{
    public function __construct(
        private OrbitPlanningResolutionCatalog $catalog,
        private OrbitPlanningResolutionIssueProvider $issues,
        private OrbitPlanningResolutionTransitioner $transitions,
        private OrbitResolutionReceiptValidator $receipts,
        private OrbitResolutionAdoptionPolicy $adoptions,
        private StartOrbitDeliveryCleanup $cleanup,
    ) {}

    public function handle(int $deliveryId, int $phaseRunId): void
    {
        $state = $this->reserve($deliveryId, $phaseRunId);

        if ($state === null) {
            return;
        }

        if ($state['stage'] === 'cleanup') {
            $this->cleanup->handle($deliveryId);

            return;
        }

        $correction = $state['correction'];
        $issue = $this->issues->fetchForPlanningResolution(
            $correction->issueId,
            $correction->issueKey,
        );
        $this->revalidate(
            $deliveryId,
            $phaseRunId,
            $state['dispatch_id'],
            $state['receipt_id'],
            $correction,
        );
        $corrected = $this->transitions->applyPlanningResolution($issue, $correction);
        $this->commit(
            $deliveryId,
            $phaseRunId,
            $state['dispatch_id'],
            $state['receipt_id'],
            $correction,
            $corrected,
        );
        $this->cleanup->handle($deliveryId);
    }

    /**
     * @return array{
     *   stage: 'correction',
     *   correction: OrbitPlanningResolutionCorrection,
     *   dispatch_id: int,
     *   receipt_id: int
     * }|array{stage: 'cleanup'}|null
     */
    private function reserve(int $deliveryId, int $phaseRunId): ?array
    {
        return DB::transaction(function () use ($deliveryId, $phaseRunId): ?array {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);
            $failure = $delivery->failure_details;

            if ($phase !== null && is_array($failure)
                && ($failure['code'] ?? null) === 'planning_resolution_cleanup_ready') {
                $this->assertCommittedCorrection($delivery, $project, $phase, $dispatches, $receipts);

                return ['stage' => 'cleanup'];
            }

            $state = $this->pendingCorrection(
                $delivery,
                $project,
                $phase,
                $dispatches,
                $receipts,
            );

            if ($state === null) {
                return null;
            }

            [$phase, $dispatch, $receipt, $correction] = $state;
            $phase->current_block = 'planning_resolution_correction';
            $phase->save();
            $failure = $delivery->failure_details;

            if (! is_array($failure)) {
                throw new OrbitPlanningResolutionCorrectionFailed(
                    'The planning-resolution failure evidence is missing.',
                );
            }

            $delivery->failure_details = [
                ...$failure,
                'code' => 'planning_resolution_reconciliation_required',
            ];
            $delivery->save();

            return [
                'stage' => 'correction',
                'correction' => $correction,
                'dispatch_id' => $dispatch->id,
                'receipt_id' => $receipt->id,
            ];
        });
    }

    private function revalidate(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        int $receiptId,
        OrbitPlanningResolutionCorrection $correction,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $receiptId,
            $correction,
        ): void {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);
            $state = $this->pendingCorrection(
                $delivery,
                $project,
                $phase,
                $dispatches,
                $receipts,
                true,
            );

            if ($state === null
                || $state[1]->id !== $dispatchId
                || $state[2]->id !== $receiptId
                || $state[3] != $correction) {
                throw new OrbitPlanningResolutionCorrectionFailed(
                    'The planning-resolution ledger changed before the Linear correction.',
                );
            }
        });
    }

    private function commit(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        int $receiptId,
        OrbitPlanningResolutionCorrection $correction,
        OrbitIssueSnapshot $issue,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $receiptId,
            $correction,
            $issue,
        ): void {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);

            if ($phase !== null && is_array($delivery->failure_details)
                && ($delivery->failure_details['code'] ?? null) === 'planning_resolution_cleanup_ready') {
                $this->assertCommittedCorrection($delivery, $project, $phase, $dispatches, $receipts);

                return;
            }

            $state = $this->pendingCorrection(
                $delivery,
                $project,
                $phase,
                $dispatches,
                $receipts,
                true,
            );

            if ($state === null
                || $state[1]->id !== $dispatchId
                || $state[2]->id !== $receiptId
                || $state[3] != $correction) {
                throw new OrbitPlanningResolutionCorrectionFailed(
                    'The planning-resolution ledger changed during Linear correction.',
                );
            }

            $phase = $state[0];

            $stateValue = $issue->payload['state'] ?? null;
            $delegate = $issue->payload['delegate'] ?? null;
            $viewerId = config('commander.hermes.tom_linear_viewer_id');
            $updatedAt = $issue->payload['updatedAt'] ?? null;

            if ($issue->issueId !== $correction->issueId
                || $issue->issueKey !== $correction->issueKey
                || ! hash_equals($correction->correctedContractHash, $issue->contractHash)
                || ($issue->payload['description'] ?? null) !== $correction->correctedDescription
                || ! is_array($stateValue)
                || ($stateValue['name'] ?? null) !== 'Todo'
                || ($stateValue['type'] ?? null) !== 'unstarted'
                || ! is_string($stateValue['id'] ?? null)
                || ! is_string($viewerId)
                || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
                || ($issue->payload['assignee'] ?? null) !== null
                || ! is_string($updatedAt)) {
                throw new OrbitPlanningResolutionCorrectionFailed(
                    'Linear did not return the exact corrected Todo issue contract.',
                );
            }

            $evidence = [
                'issue_id' => $issue->issueId,
                'issue_key' => $issue->issueKey,
                'resolution_receipt_sha256' => $correction->resolutionReceiptHash,
                'old_contract_sha256' => $correction->currentContractHash,
                'new_contract_sha256' => $issue->contractHash,
                'description_sha256' => $correction->correctedDescriptionHash,
                'linear_state_id' => $stateValue['id'],
                'linear_state' => 'Todo',
                'linear_state_type' => 'unstarted',
                'linear_updated_at' => $updatedAt,
            ];
            $phase->current_block = null;
            $output = $phase->output;

            if (! is_array($output)) {
                throw new OrbitPlanningResolutionCorrectionFailed(
                    'The planning-resolution output evidence is missing.',
                );
            }

            $phase->output = [...$output, 'planning_resolution_correction' => $evidence];
            $phase->save();
            $delivery->failure_details = [
                'code' => 'planning_resolution_cleanup_ready',
                'phase_run_id' => $phase->id,
                'dispatch_id' => $dispatchId,
                'receipt_id' => $receiptId,
                'correction' => $evidence,
            ];
            $delivery->save();
        });
    }

    /**
     * @param  Collection<int, AgentDispatch>  $dispatches
     * @param  Collection<int, Receipt>  $receipts
     * @return array{PhaseRun, AgentDispatch, Receipt, OrbitPlanningResolutionCorrection}|null
     */
    private function pendingCorrection(
        Delivery $delivery,
        ProjectOrchestration $project,
        ?PhaseRun $phase,
        Collection $dispatches,
        Collection $receipts,
        bool $reserved = false,
    ): ?array {
        $failure = $delivery->failure_details;
        $dispatchId = is_array($failure) ? ($failure['dispatch_id'] ?? null) : null;
        $receiptId = is_array($failure) ? ($failure['receipt_id'] ?? null) : null;
        $dispatch = is_int($dispatchId) ? $dispatches->firstWhere('id', $dispatchId) : null;
        $receipt = is_int($receiptId) ? $receipts->firstWhere('id', $receiptId) : null;

        if ($phase === null || $dispatch === null || $receipt === null) {
            return null;
        }

        $startup = $delivery->phaseRuns->sortBy('id')->first();
        $startupInput = $startup?->input;
        $snapshot = is_array($startupInput) ? ($startupInput['issue_snapshot'] ?? null) : null;
        $contractHash = is_array($snapshot) ? ($snapshot['contract_sha256'] ?? null) : null;
        $correction = is_string($contractHash)
            ? $this->catalog->find(
                (string) $delivery->external_issue_id,
                (string) $delivery->external_issue_key,
                $receipt->payload_hash,
                $contractHash,
            )
            : null;
        $expectedBlock = $reserved ? 'planning_resolution_correction' : null;
        $expectedCode = $reserved
            ? 'planning_resolution_reconciliation_required'
            : 'resolution_decision_required';
        $output = $phase->output;
        $proposal = $receipt->payload['resolution'] ?? null;
        $adoption = $this->adoptions->assess(
            $phase,
            $receipt,
            $delivery->phaseRuns
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->filter(static fn (PhaseRun $run): bool => is_array($run->output)
                    && ($run->output['adopted'] ?? null) === true)
                ->count(),
        );
        $latestResolution = $delivery->phaseRuns
            ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
            ->sortByDesc('attempt')
            ->first();
        $expectedFailure = is_array($output)
            ? [
                'code' => $expectedCode,
                'phase_run_id' => $phase->id,
                'dispatch_id' => $dispatch->id,
                'receipt_id' => $receipt->id,
                'publication' => $output['publication'] ?? null,
                'expected_resume_phase' => $output['expected_resume_phase'] ?? null,
                'requirements' => $output['requirements'] ?? null,
                'reason' => $output['reason'] ?? null,
            ]
            : null;

        if ($expectedFailure === null) {
            return null;
        }

        if ($reserved && is_string($failure['message'] ?? null)) {
            $expectedFailure['message'] = $failure['message'];
        }

        if ($correction === null
            || $project->state !== ProjectOrchestrationState::Enabled
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->status !== DeliveryStatus::Blocked
            || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $failure !== $expectedFailure
            || $latestResolution?->id !== $phase->id
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->current_block !== $expectedBlock
            || $phase->finished_at === null
            || ($output['receipt_id'] ?? null) !== $receipt->id
            || ($output['result'] ?? null) !== 'proposal'
            || ($output['adopted'] ?? null) !== false
            || ($output['automatic_adoption_eligible'] ?? null) !== false
            || ($output['expected_resume_phase'] ?? null) !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $dispatches->where('phase_run_id', $phase->id)->count() !== 1
            || $receipts->where('phase_run_id', $phase->id)->count() !== 1
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->settled_at === null
            || $receipt->phase_run_id !== $phase->id
            || $receipt->kind !== 'orbit_resolution'
            || $receipt->validation_status !== ReceiptValidationStatus::Valid
            || ! is_array($proposal)
            || ($proposal['resume_phase'] ?? null) !== OrbitFeatureWorkflow::INITIAL_PHASE
            || ($proposal['required_adrs'] ?? null) !== []
            || ($proposal['human_decisions'] ?? null) !== []
            || ! is_array($proposal['issue_changes'] ?? null) || $proposal['issue_changes'] === []
            || ! is_array($proposal['plan_changes'] ?? null) || $proposal['plan_changes'] === []
            || $adoption->adopt
            || $adoption->expectedResumePhase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || ($output['requirements'] ?? null) !== $adoption->requirements
            || ($output['reason'] ?? null) !== $adoption->reason
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            return null;
        }

        return [$phase, $dispatch, $receipt, $correction];
    }

    /**
     * @param  Collection<int, AgentDispatch>  $dispatches
     * @param  Collection<int, Receipt>  $receipts
     */
    private function assertCommittedCorrection(
        Delivery $delivery,
        ProjectOrchestration $project,
        PhaseRun $phase,
        Collection $dispatches,
        Collection $receipts,
    ): void {
        $failure = $delivery->failure_details;
        $output = $phase->output;
        $evidence = is_array($output) ? ($output['planning_resolution_correction'] ?? null) : null;
        $dispatchId = is_array($failure) ? ($failure['dispatch_id'] ?? null) : null;
        $receiptId = is_array($failure) ? ($failure['receipt_id'] ?? null) : null;
        $dispatch = is_int($dispatchId) ? $dispatches->firstWhere('id', $dispatchId) : null;
        $receipt = is_int($receiptId) ? $receipts->firstWhere('id', $receiptId) : null;
        $startup = $delivery->phaseRuns->sortBy('id')->first();
        $startupInput = $startup?->input;
        $snapshot = is_array($startupInput) ? ($startupInput['issue_snapshot'] ?? null) : null;
        $contractHash = is_array($snapshot) ? ($snapshot['contract_sha256'] ?? null) : null;
        $correction = is_string($contractHash) && $receipt !== null
            ? $this->catalog->find(
                (string) $delivery->external_issue_id,
                (string) $delivery->external_issue_key,
                $receipt->payload_hash,
                $contractHash,
            )
            : null;
        $linearStateId = is_array($evidence) ? ($evidence['linear_state_id'] ?? null) : null;
        $linearUpdatedAt = is_array($evidence) ? ($evidence['linear_updated_at'] ?? null) : null;
        $expectedEvidence = $correction !== null
            && is_string($linearStateId)
            && Str::isUuid($linearStateId)
            && is_string($linearUpdatedAt)
            ? [
                'issue_id' => $correction->issueId,
                'issue_key' => $correction->issueKey,
                'resolution_receipt_sha256' => $correction->resolutionReceiptHash,
                'old_contract_sha256' => $correction->currentContractHash,
                'new_contract_sha256' => $correction->correctedContractHash,
                'description_sha256' => $correction->correctedDescriptionHash,
                'linear_state_id' => $linearStateId,
                'linear_state' => 'Todo',
                'linear_state_type' => 'unstarted',
                'linear_updated_at' => $linearUpdatedAt,
            ]
            : null;
        $correctionRetained = ($delivery->status === DeliveryStatus::Blocked
                && $delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE)
            || ($delivery->status === DeliveryStatus::Cleaning
                && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE);

        if ($project->state !== ProjectOrchestrationState::Enabled
            || ! $correctionRetained
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->current_block !== null
            || ! is_array($failure) || array_keys($failure) !== [
                'code', 'phase_run_id', 'dispatch_id', 'receipt_id', 'correction',
            ]
            || ($failure['code'] ?? null) !== 'planning_resolution_cleanup_ready'
            || ($failure['phase_run_id'] ?? null) !== $phase->id
            || ! is_array($evidence) || $evidence !== $expectedEvidence
            || ($failure['correction'] ?? null) !== $evidence
            || $dispatches->where('phase_run_id', $phase->id)->count() !== 1
            || $receipts->where('phase_run_id', $phase->id)->count() !== 1
            || $dispatch === null || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->settled_at === null
            || $receipt->phase_run_id !== $phase->id
            || $receipt->kind !== 'orbit_resolution'
            || $receipt->validation_status !== ReceiptValidationStatus::Valid
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitPlanningResolutionCorrectionFailed(
                'The committed planning-resolution correction is inconsistent.',
            );
        }
    }

    /**
     * @return array{
     *   Delivery,
     *   ProjectOrchestration,
     *   Collection<int, PhaseRun>,
     *   Collection<int, AgentDispatch>,
     *   Collection<int, Receipt>
     * }
     */
    private function lockedLedger(int $deliveryId): array
    {
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
        $delivery->setRelation('projectOrchestration', $project);
        $delivery->setRelation('phaseRuns', $phases);

        return [$delivery, $project, $phases, $dispatches, $receipts];
    }
}
