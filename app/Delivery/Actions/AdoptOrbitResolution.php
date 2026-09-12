<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitResolutionAdoption;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitResolutionAdoptionFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestResolutionReceiptValidator;
use App\Delivery\Workflow\OrbitResolutionAdoptionPolicy;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AdoptOrbitResolution
{
    public function __construct(
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitActiveIssueProvider $issues,
        private OrbitIssueTransitioner $transitions,
        private OrbitIssueSnapshotFactory $snapshots,
        private OrbitPullRequestResolutionReceiptValidator $receipts,
        private OrbitResolutionAdoptionPolicy $adoptions,
    ) {}

    public function handle(int $deliveryId, int $phaseRunId): void
    {
        $state = $this->reserveAdoption($deliveryId, $phaseRunId);

        if ($state === null) {
            return;
        }

        [$delivery, $phase, $dispatch, $receipt, $adoption, $projectConfig] = $state;
        $preparation = $this->preparations->startup($delivery);
        $issue = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $this->assertIssue($delivery, $preparation->snapshot->contractHash, $issue, [
            'In Review',
            'In Progress',
        ]);
        $this->revalidateAdoptionIntent(
            $delivery->id,
            $phase->id,
            $dispatch->id,
            $receipt->id,
            $receipt->payload_hash,
            $adoption,
            $projectConfig,
        );

        try {
            $resumed = $this->transitions->transitionToInProgress(
                $issue,
                $preparation->snapshot->contractHash,
            );
        } catch (OrbitIssueTransitionFailed $exception) {
            throw new OrbitResolutionAdoptionFailed(
                'The automatic resolution adoption could not verify Linear In Progress.',
                0,
                $exception,
            );
        }

        $this->assertIssue(
            $delivery,
            $preparation->snapshot->contractHash,
            $resumed,
            ['In Progress'],
        );
        $this->commitAdoption(
            $delivery->id,
            $phase->id,
            $dispatch->id,
            $receipt->id,
            $adoption,
            $projectConfig,
            $resumed,
        );
    }

    /**
     * @return array{Delivery, PhaseRun, AgentDispatch, Receipt, OrbitResolutionAdoption, array<string, mixed>}|null
     */
    private function reserveAdoption(int $deliveryId, int $phaseRunId): ?array
    {
        return DB::transaction(function () use ($deliveryId, $phaseRunId): ?array {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatch = $dispatches->firstWhere('phase_run_id', $phaseRunId);
            $receipt = $receipts->firstWhere('phase_run_id', $phaseRunId);

            if ($phase !== null && $dispatch !== null && $receipt !== null
                && $this->adoptionWasCommitted($delivery, $phase, $dispatch, $receipt, $phases, $dispatches, $receipts)) {
                return null;
            }

            if ($phase === null || $dispatch === null || $receipt === null) {
                throw new OrbitResolutionAdoptionFailed('The retained resolution adoption is incomplete.');
            }

            $adoption = $this->assertPendingAdoption(
                $delivery,
                $project,
                $phase,
                $dispatch,
                $receipt,
                $phases,
                $dispatches,
                $receipts,
                false,
            );
            $phase->current_block = 'resolution_adoption';
            $phase->save();

            return [$delivery, $phase, $dispatch, $receipt, $adoption, $project->config];
        });
    }

    /** @param array<string, mixed> $projectConfig */
    private function revalidateAdoptionIntent(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        int $receiptId,
        string $receiptHash,
        OrbitResolutionAdoption $adoption,
        array $projectConfig,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $receiptId,
            $receiptHash,
            $adoption,
            $projectConfig,
        ): void {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $receipt = $receipts->firstWhere('id', $receiptId);
            $current = $this->assertPendingAdoption(
                $delivery,
                $project,
                $phase,
                $dispatch,
                $receipt,
                $phases,
                $dispatches,
                $receipts,
                true,
            );

            if ($project->config !== $projectConfig
                || $receipt === null
                || ! hash_equals($receipt->payload_hash, $receiptHash)
                || $current != $adoption) {
                throw new OrbitResolutionAdoptionFailed(
                    'The resolution adoption ledger changed before the Linear transition.',
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $projectConfig
     */
    private function commitAdoption(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        int $receiptId,
        OrbitResolutionAdoption $adoption,
        array $projectConfig,
        OrbitIssueSnapshot $issue,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $receiptId,
            $adoption,
            $projectConfig,
            $issue,
        ): void {
            [$delivery, $project, $phases, $dispatches, $receipts] = $this->lockedLedger($deliveryId);
            $phase = $phases->firstWhere('id', $phaseRunId);
            $dispatch = $dispatches->firstWhere('id', $dispatchId);
            $receipt = $receipts->firstWhere('id', $receiptId);

            if ($phase !== null && $dispatch !== null && $receipt !== null
                && $this->adoptionWasCommitted($delivery, $phase, $dispatch, $receipt, $phases, $dispatches, $receipts)) {
                return;
            }

            $current = $this->assertPendingAdoption(
                $delivery,
                $project,
                $phase,
                $dispatch,
                $receipt,
                $phases,
                $dispatches,
                $receipts,
                true,
            );

            if ($project->config !== $projectConfig || $current != $adoption
                || $phase === null || $dispatch === null || $receipt === null) {
                throw new OrbitResolutionAdoptionFailed(
                    'The resolution adoption ledger changed during the Linear transition.',
                );
            }

            $this->assertIssue(
                $delivery,
                $this->startupContractHash($delivery),
                $issue,
                ['In Progress'],
            );
            $source = $this->sourceImplementationReceipt($phase, $receipts);
            $sourcePhase = $phases->firstWhere('id', $source->phase_run_id);

            if ($sourcePhase === null) {
                throw new OrbitResolutionAdoptionFailed(
                    'The resolution adoption source implementation is missing.',
                );
            }

            $attempt = $sourcePhase->attempt + 1;
            $input = $this->correctionInput($phase, $dispatch, $receipt, $source);
            $implementation = $phases
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->firstWhere('attempt', $attempt);

            if ($implementation === null) {
                $implementation = PhaseRun::query()->create([
                    'delivery_id' => $delivery->id,
                    'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                    'attempt' => $attempt,
                    'status' => PhaseRunStatus::Pending,
                    'input' => $input,
                ]);
            }

            $implementationDispatches = AgentDispatch::query()
                ->where('phase_run_id', $implementation->id)
                ->lockForUpdate()
                ->get();
            $implementationDispatch = $implementationDispatches->first();

            if ($implementationDispatch === null) {
                $implementationDispatch = AgentDispatch::query()->create([
                    'phase_run_id' => $implementation->id,
                    'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                    'idempotency_key' => IdempotencyKey::forDispatch(
                        $delivery->id,
                        OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                        $attempt,
                        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                    )->value,
                    'herdr_agent_name' => strtolower((string) $delivery->external_issue_key).'-loop-builder',
                    'prompt_name' => 'orbit_resolution_correction',
                    'prompt_version' => OrbitFeatureWorkflow::RESOLUTION_CORRECTION_PROMPT_VERSION,
                    'prompt_hash' => str_repeat('0', 64),
                    'status' => AgentDispatchStatus::Pending,
                ]);
                $implementationDispatches = collect([$implementationDispatch]);
            }

            if ($implementation->delivery_id !== $delivery->id
                || $implementation->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
                || $implementation->attempt !== $attempt
                || $implementation->status !== PhaseRunStatus::Pending
                || $implementation->input !== $input
                || $implementation->current_block !== null
                || $implementation->output !== null
                || $implementation->failure_code !== null
                || $implementation->failure_message !== null
                || $implementation->failure_details !== null
                || $implementation->started_at !== null
                || $implementation->finished_at !== null
                || $implementation->receipts()->exists()
                || $implementationDispatches->count() !== 1
                || $implementationDispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
                || $implementationDispatch->idempotency_key !== IdempotencyKey::forDispatch(
                    $delivery->id,
                    OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                    $attempt,
                    OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                )->value
                || $implementationDispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
                || $implementationDispatch->prompt_name !== 'orbit_resolution_correction'
                || $implementationDispatch->prompt_version !== OrbitFeatureWorkflow::RESOLUTION_CORRECTION_PROMPT_VERSION
                || $implementationDispatch->prompt_hash !== str_repeat('0', 64)
                || $implementationDispatch->status !== AgentDispatchStatus::Pending) {
                throw new OrbitResolutionAdoptionFailed(
                    'The retained resolution correction intent is inconsistent.',
                );
            }

            $state = $issue->payload['state'];
            $output = $phase->output;

            if (! is_array($state) || ! is_array($output)) {
                throw new OrbitResolutionAdoptionFailed(
                    'The resolution adoption evidence is incomplete.',
                );
            }

            $phase->forceFill([
                'current_block' => null,
                'output' => [
                    ...$output,
                    'adopted' => true,
                    'adoption' => [
                        'implementation_phase_run_id' => $implementation->id,
                        'implementation_attempt' => $implementation->attempt,
                        'linear_state_id' => $state['id'],
                        'linear_state' => 'In Progress',
                        'contract_sha256' => $issue->contractHash,
                    ],
                ],
            ])->save();
            $delivery->forceFill([
                'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                'status' => DeliveryStatus::Queued,
                'failure_details' => null,
            ])->save();
            AdvanceDelivery::dispatch($delivery->id)->afterCommit();
        });
    }

    /**
     * @return array{Delivery, ProjectOrchestration, Collection<int, PhaseRun>, Collection<int, AgentDispatch>, Collection<int, Receipt>}
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

        return [$delivery, $project, $phases, $dispatches, $receipts];
    }

    /**
     * @param  Collection<int, PhaseRun>  $phases
     * @param  Collection<int, AgentDispatch>  $dispatches
     * @param  Collection<int, Receipt>  $receipts
     */
    private function assertPendingAdoption(
        Delivery $delivery,
        ProjectOrchestration $project,
        ?PhaseRun $phase,
        ?AgentDispatch $dispatch,
        ?Receipt $receipt,
        Collection $phases,
        Collection $dispatches,
        Collection $receipts,
        bool $reserved,
    ): OrbitResolutionAdoption {
        if ($phase === null || $dispatch === null || $receipt === null) {
            throw new OrbitResolutionAdoptionFailed('The retained resolution adoption is incomplete.');
        }

        $output = $phase->output;
        $failure = $delivery->failure_details;
        $publication = is_array($output) ? ($output['publication'] ?? null) : null;
        $expectedPublication = $this->expectedPublication($dispatch, $receipt);
        $latestResolution = $phases
            ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
            ->sortByDesc('attempt')
            ->first();
        $priorAdoptions = $this->priorAdoptions($phases, $phase->id);
        $adoption = $this->adoptions->assess($phase, $receipt, $priorAdoptions);
        $failureCode = is_array($failure) ? ($failure['code'] ?? null) : null;
        $validAdoptionBlock = $reserved
            ? $phase->current_block === 'resolution_adoption'
            : in_array($phase->current_block, [null, 'resolution_adoption'], true);
        $validFailureKeys = $failureCode === 'resolution_adoption_ready'
            ? [
                'code', 'phase_run_id', 'dispatch_id', 'receipt_id', 'publication',
                'expected_resume_phase', 'requirements', 'reason',
            ]
            : [
                'code', 'phase_run_id', 'dispatch_id', 'receipt_id', 'publication',
                'expected_resume_phase', 'requirements', 'reason', 'message',
            ];

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $project->state !== ProjectOrchestrationState::Enabled
            || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || ! is_array($failure)
            || ! in_array($failureCode, [
                'resolution_adoption_ready',
                'resolution_adoption_reconciliation_required',
            ], true)
            || ! $this->hasExactKeys($failure, $validFailureKeys)
            || ($failure['phase_run_id'] ?? null) !== $phase->id
            || ($failure['dispatch_id'] ?? null) !== $dispatch->id
            || ($failure['receipt_id'] ?? null) !== $receipt->id
            || ($failure['publication'] ?? null) !== $publication
            || ($failure['expected_resume_phase'] ?? null) !== $adoption->expectedResumePhase
            || ($failure['requirements'] ?? null) !== $adoption->requirements
            || ($failure['reason'] ?? null) !== $adoption->reason
            || $latestResolution?->id !== $phase->id
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $phase->attempt < 1
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->finished_at === null
            || ! $validAdoptionBlock
            || ! is_array($output)
            || ! $this->hasExactKeys($output, [
                'receipt_id', 'result', 'publication', 'adopted',
                'automatic_adoption_eligible', 'expected_resume_phase', 'requirements', 'reason',
            ])
            || ($output['receipt_id'] ?? null) !== $receipt->id
            || ($output['result'] ?? null) !== 'proposal'
            || ($output['adopted'] ?? null) !== false
            || ($output['automatic_adoption_eligible'] ?? null) !== true
            || ($output['expected_resume_phase'] ?? null) !== $adoption->expectedResumePhase
            || ($output['requirements'] ?? null) !== $adoption->requirements
            || ($output['reason'] ?? null) !== $adoption->reason
            || ! is_array($publication)
            || ! $this->hasExactKeys($publication, ['comment_id', 'marker', 'body_sha256'])
            || ! $this->isUuid($publication['comment_id'] ?? null)
            || ($publication['marker'] ?? null) !== $expectedPublication['marker']
            || ! is_string($publication['body_sha256'] ?? null)
            || ! hash_equals($expectedPublication['body_sha256'], $publication['body_sha256'])
            || $dispatches->where('phase_run_id', $phase->id)->count() !== 1
            || $receipts->where('phase_run_id', $phase->id)->count() !== 1
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->settled_at === null
            || $receipt->phase_run_id !== $phase->id
            || ($receipt->payload['result'] ?? null) !== 'proposal'
            || ! $adoption->adopt
            || $adoption->expectedResumePhase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $adoption->requirements !== []
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitResolutionAdoptionFailed(
                'The retained resolution adoption is inconsistent.',
            );
        }

        return $adoption;
    }

    /**
     * @param  Collection<int, PhaseRun>  $phases
     * @param  Collection<int, AgentDispatch>  $dispatches
     * @param  Collection<int, Receipt>  $receipts
     */
    private function adoptionWasCommitted(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
        Collection $phases,
        Collection $dispatches,
        Collection $receipts,
    ): bool {
        $output = $phase->output;

        if (! is_array($output) || ($output['adopted'] ?? null) !== true) {
            return false;
        }

        $adoption = $output['adoption'] ?? null;
        $implementationId = is_array($adoption) ? ($adoption['implementation_phase_run_id'] ?? null) : null;
        $implementation = is_int($implementationId) ? $phases->firstWhere('id', $implementationId) : null;
        $implementationDispatch = $implementation === null
            ? null
            : $dispatches->firstWhere('phase_run_id', $implementation->id);
        $source = $this->sourceImplementationReceipt($phase, $receipts);
        $sourcePhase = $phases->firstWhere('id', $source->phase_run_id);
        $expectedInput = $this->correctionInput($phase, $dispatch, $receipt, $source);
        $current = $this->adoptions->assess(
            $phase,
            $receipt,
            $this->priorAdoptions($phases, $phase->id),
        );
        $expectedPublication = $this->expectedPublication($dispatch, $receipt);
        $publication = $output['publication'] ?? null;

        if ($sourcePhase === null || $implementation === null || $implementationDispatch === null) {
            throw new OrbitResolutionAdoptionFailed(
                'The committed resolution adoption is inconsistent: its correction is missing.',
            );
        }

        if ($delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $delivery->status !== DeliveryStatus::Queued
            || $delivery->failure_details !== null
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->finished_at === null
            || $phase->failure_code !== null
            || $phase->failure_message !== null
            || $phase->failure_details !== null
            || $dispatches->where('phase_run_id', $phase->id)->count() !== 1
            || $receipts->where('phase_run_id', $phase->id)->count() !== 1
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->settled_at === null
            || $receipt->phase_run_id !== $phase->id) {
            throw new OrbitResolutionAdoptionFailed(
                'The committed resolution adoption is inconsistent: its delivery ledger changed.',
            );
        }

        if (! $this->hasExactKeys($output, [
            'receipt_id', 'result', 'publication', 'adopted', 'adoption',
            'automatic_adoption_eligible', 'expected_resume_phase', 'requirements', 'reason',
        ])
            || ($output['receipt_id'] ?? null) !== $receipt->id
            || ($output['result'] ?? null) !== 'proposal'
            || ($output['automatic_adoption_eligible'] ?? null) !== true
            || ($output['expected_resume_phase'] ?? null) !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || ($output['requirements'] ?? null) !== []
            || ($output['reason'] ?? null) !== $current->reason
            || ! is_array($publication)
            || ! $this->isUuid($publication['comment_id'] ?? null)
            || ($publication['marker'] ?? null) !== $expectedPublication['marker']
            || ! is_string($publication['body_sha256'] ?? null)
            || ! hash_equals($expectedPublication['body_sha256'], $publication['body_sha256'])
            || ! $this->hasExactKeys($adoption, [
                'implementation_phase_run_id', 'implementation_attempt', 'linear_state_id',
                'linear_state', 'contract_sha256',
            ])
            || ($adoption['implementation_attempt'] ?? null) !== $sourcePhase->attempt + 1
            || ! $this->isUuid($adoption['linear_state_id'] ?? null)
            || ($adoption['linear_state'] ?? null) !== 'In Progress'
            || ! is_string($adoption['contract_sha256'] ?? null)
            || ! hash_equals($this->startupContractHash($delivery), $adoption['contract_sha256'])
            || ! $current->adopt
            || $phase->current_block !== null) {
            throw new OrbitResolutionAdoptionFailed(
                'The committed resolution adoption is inconsistent: its evidence changed.',
            );
        }

        if ($implementation->delivery_id !== $delivery->id
            || $implementation->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $implementation->attempt !== $sourcePhase->attempt + 1
            || $implementation->status !== PhaseRunStatus::Pending
            || $implementation->input !== $expectedInput
            || $implementation->current_block !== null
            || $implementation->output !== null
            || $implementation->failure_code !== null
            || $implementation->failure_message !== null
            || $implementation->failure_details !== null
            || $implementation->started_at !== null
            || $implementation->finished_at !== null
            || $implementation->receipts()->exists()
            || $dispatches->where('phase_run_id', $implementation->id)->count() !== 1
            || $implementationDispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            || $implementationDispatch->idempotency_key !== IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                $implementation->attempt,
                OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            )->value
            || $implementationDispatch->herdr_agent_name !== strtolower((string) $delivery->external_issue_key).'-loop-builder'
            || $implementationDispatch->prompt_name !== 'orbit_resolution_correction'
            || $implementationDispatch->prompt_version !== OrbitFeatureWorkflow::RESOLUTION_CORRECTION_PROMPT_VERSION
            || $implementationDispatch->prompt_hash !== str_repeat('0', 64)
            || $implementationDispatch->status !== AgentDispatchStatus::Pending
            || $implementationDispatch->herdr_session !== null
            || $implementationDispatch->herdr_workspace_id !== null
            || $implementationDispatch->herdr_tab_id !== null
            || $implementationDispatch->herdr_pane_id !== null
            || $implementationDispatch->herdr_terminal_id !== null
            || $implementationDispatch->herdr_agent_id !== null
            || $implementationDispatch->state_change_seq !== null
            || $implementationDispatch->error_code !== null
            || $implementationDispatch->error_message !== null
            || $implementationDispatch->dispatched_at !== null
            || $implementationDispatch->settled_at !== null) {
            throw new OrbitResolutionAdoptionFailed(
                'The committed resolution adoption is inconsistent: its correction intent changed.',
            );
        }

        if (! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitResolutionAdoptionFailed(
                'The committed resolution adoption is inconsistent: its source ledger changed.',
            );
        }

        return true;
    }

    /** @param Collection<int, Receipt> $receipts */
    private function sourceImplementationReceipt(PhaseRun $phase, Collection $receipts): Receipt
    {
        $input = $phase->input;
        $receiptId = is_array($input) ? ($input['implementation_receipt_id'] ?? null) : null;
        $payload = is_array($input) ? ($input['implementation_receipt'] ?? null) : null;
        $source = is_int($receiptId) ? $receipts->firstWhere('id', $receiptId) : null;

        if ($source === null || ! is_array($payload) || $source->payload !== $payload
            || $source->kind !== 'orbit_implementation') {
            throw new OrbitResolutionAdoptionFailed(
                'The resolution adoption has no exact implementation source.',
            );
        }

        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionInput(
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
        Receipt $source,
    ): array {
        $input = $phase->input;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;
        $output = $phase->output;
        $publication = is_array($output) ? ($output['publication'] ?? null) : null;

        if (! is_array($pullRequest) || array_is_list($pullRequest)
            || ! is_array($publication) || array_is_list($publication)) {
            throw new OrbitResolutionAdoptionFailed(
                'The resolution adoption has malformed correction evidence.',
            );
        }

        return [
            'resolution_phase_run_id' => $phase->id,
            'resolution_dispatch_id' => $dispatch->id,
            'resolution_receipt_id' => $receipt->id,
            'resolution_receipt' => $receipt->payload,
            'resolution_publication' => $publication,
            'implementation_receipt_id' => $source->id,
            'implementation_receipt' => $source->payload,
            'pull_request' => $pullRequest,
        ];
    }

    /** @param Collection<int, PhaseRun> $phases */
    private function priorAdoptions(Collection $phases, int $phaseRunId): int
    {
        return $phases
            ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
            ->where('id', '!=', $phaseRunId)
            ->filter(static fn (PhaseRun $phase): bool => is_array($phase->output)
                && ($phase->output['adopted'] ?? null) === true)
            ->count();
    }

    /** @return array{marker: string, body_sha256: string} */
    private function expectedPublication(AgentDispatch $dispatch, Receipt $receipt): array
    {
        $marker = "ORBIT-LOOP-RESOLUTION:{$dispatch->id}";
        $handoff = $receipt->payload['handoff'] ?? null;

        if (! is_string($handoff) || trim($handoff) === '') {
            throw new OrbitResolutionAdoptionFailed(
                'The resolution adoption has an invalid handoff.',
            );
        }

        $body = implode("\n\n", [
            $marker,
            $handoff,
            'Commander routing: Needs an explicit decision or recovery action.',
        ]);

        return [
            'marker' => $marker,
            'body_sha256' => hash('sha256', $body),
        ];
    }

    private function startupContractHash(Delivery $delivery): string
    {
        $planning = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::INITIAL_PHASE)
            ->where('attempt', 1)
            ->first();
        $input = $planning?->input;
        $snapshot = is_array($input) ? ($input['issue_snapshot'] ?? null) : null;
        $hash = is_array($snapshot) ? ($snapshot['contract_sha256'] ?? null) : null;

        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new OrbitResolutionAdoptionFailed(
                'The resolution adoption has no valid startup contract.',
            );
        }

        return $hash;
    }

    /** @param list<string> $states */
    private function assertIssue(
        Delivery $delivery,
        string $contractHash,
        OrbitIssueSnapshot $issue,
        array $states,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');

        if ($issue->issueId !== $delivery->external_issue_id
            || $issue->issueKey !== $delivery->external_issue_key
            || ! $this->snapshots->matchesExpectedContract($issue, $contractHash, $delivery->pull_request_url)
            || ! is_array($state)
            || ! in_array($state['name'] ?? null, $states, true)
            || ($state['type'] ?? null) !== 'started'
            || ! $this->isUuid($state['id'] ?? null)
            || ! is_string($viewerId)
            || ! is_array($delegate)
            || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || $issue->payload['assignee'] !== null) {
            throw new OrbitResolutionAdoptionFailed(
                'The Orbit issue changed during automatic resolution adoption.',
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
