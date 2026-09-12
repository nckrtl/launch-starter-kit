<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitResolutionPublisher;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitResolutionAdoption;
use App\Delivery\Data\PublishedOrbitResolution;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitResolutionPublicationFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestResolutionReceiptValidator;
use App\Delivery\Workflow\OrbitResolutionAdoptionPolicy;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitResolution
{
    public function __construct(
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitActiveIssueProvider $issues,
        private OrbitResolutionPublisher $publisher,
        private OrbitPullRequestResolutionReceiptValidator $receipts,
        private OrbitResolutionAdoptionPolicy $adoptions,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    public function handle(int $deliveryId, int $phaseRunId): void
    {
        $state = $this->reservePublication($deliveryId, $phaseRunId);

        if ($state === null) {
            return;
        }

        [$delivery, $phase, $dispatch, $receipt, $adoption] = $state;
        $projectConfig = $delivery->projectOrchestration->config;
        $preparation = $this->preparations->startup($delivery);
        $issue = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $this->assertIssue($delivery, $preparation->snapshot->contractHash, $issue);
        $this->revalidatePublicationIntent(
            $delivery->id,
            $phase->id,
            $dispatch->id,
            $receipt->id,
            $receipt->payload_hash,
            $adoption,
            $projectConfig,
        );
        $published = $this->publisher->publish(
            $issue,
            $dispatch->id,
            $this->string($receipt->payload, 'handoff'),
            false,
            $adoption->expectedResumePhase,
        );
        $readBack = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $this->assertIssue($delivery, $preparation->snapshot->contractHash, $readBack);
        $this->commitPublication(
            $delivery->id,
            $phase->id,
            $dispatch->id,
            $receipt->id,
            $published,
            $adoption->adopt,
            $adoption->expectedResumePhase,
            $adoption->requirements,
            $adoption->reason,
            $projectConfig,
        );
    }

    /** @return array{Delivery, PhaseRun, AgentDispatch, Receipt, OrbitResolutionAdoption}|null */
    private function reservePublication(int $deliveryId, int $phaseRunId): ?array
    {
        return DB::transaction(function () use ($deliveryId, $phaseRunId): ?array {
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
            $dispatch = $dispatches->firstWhere('phase_run_id', $phaseRunId);
            $receipt = $receipts->firstWhere('phase_run_id', $phaseRunId);
            $delivery->setRelation('projectOrchestration', $project);
            $latestResolution = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $phaseRunId);
            $phaseReceipts = $receipts->where('phase_run_id', $phaseRunId);
            $priorAdoptions = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->where('id', '!=', $phaseRunId)
                ->filter(static fn (PhaseRun $item): bool => is_array($item->output)
                    && ($item->output['adopted'] ?? null) === true)
                ->count();
            $adoption = $phase !== null && $receipt !== null
                ? $this->adoptions->assess($phase, $receipt, $priorAdoptions)
                : null;

            if ($phase !== null && $dispatch !== null && $receipt !== null && $adoption !== null
                && $latestResolution?->id === $phase->id
                && $this->publicationWasCommitted($delivery, $phase, $dispatch, $receipt, $adoption)) {
                return null;
            }

            $failure = $delivery->failure_details;
            $failureCode = is_array($failure) ? ($failure['code'] ?? null) : null;
            $validPublicationBlock = $failureCode === 'resolution_proposal_ready'
                ? in_array($phase?->current_block, [null, 'resolution_publication'], true)
                : ($failureCode === 'resolution_publication_reconciliation_required'
                    && $phase?->current_block === 'resolution_publication');

            if ($phase === null || $dispatch === null || $receipt === null
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $project->state !== ProjectOrchestrationState::Enabled
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Blocked
                || ! is_array($failure) || ! $validPublicationBlock
                || ($failure['phase_run_id'] ?? null) !== $phase->id
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || ($failure['receipt_id'] ?? null) !== $receipt->id
                || $latestResolution?->id !== $phase->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Completed
                || $phase->finished_at === null
                || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'proposal']
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->count() !== 1
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->settled_at === null
                || $receipt->phase_run_id !== $phase->id
                || ($receipt->payload['result'] ?? null) !== 'proposal'
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitResolutionPublicationFailed('The retained resolution proposal is inconsistent.');
            }

            if ($adoption === null) {
                throw new OrbitResolutionPublicationFailed('The retained resolution proposal cannot be classified.');
            }

            $phase->current_block = 'resolution_publication';
            $phase->save();

            return [$delivery, $phase, $dispatch, $receipt, $adoption];
        });
    }

    /** @param array<string, mixed> $projectConfig */
    private function revalidatePublicationIntent(
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
            $receipt = $receipts->firstWhere('id', $receiptId);
            $latestResolution = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $phaseRunId);
            $phaseReceipts = $receipts->where('phase_run_id', $phaseRunId);
            $priorAdoptions = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->where('id', '!=', $phaseRunId)
                ->filter(static fn (PhaseRun $item): bool => is_array($item->output)
                    && ($item->output['adopted'] ?? null) === true)
                ->count();
            $failure = $delivery->failure_details;
            $failureCode = is_array($failure) ? ($failure['code'] ?? null) : null;
            $validPublicationBlock = $failureCode === 'resolution_proposal_ready'
                || ($failureCode === 'resolution_publication_reconciliation_required'
                    && $phase?->current_block === 'resolution_publication');
            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null || $receipt === null
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $projectConfig
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Blocked
                || ! is_array($failure) || ! $validPublicationBlock
                || ($failure['phase_run_id'] ?? null) !== $phase->id
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || ($failure['receipt_id'] ?? null) !== $receipt->id
                || $latestResolution?->id !== $phase->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Completed
                || $phase->finished_at === null
                || $phase->current_block !== 'resolution_publication'
                || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'proposal']
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->count() !== 1
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->settled_at === null
                || $receipt->phase_run_id !== $phase->id
                || ! hash_equals($receipt->payload_hash, $receiptHash)
                || ($receipt->payload['result'] ?? null) !== 'proposal'
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitResolutionPublicationFailed(
                    'The resolution ledger changed before publication.',
                );
            }

            $currentAdoption = $this->adoptions->assess($phase, $receipt, $priorAdoptions);

            if ($currentAdoption != $adoption) {
                throw new OrbitResolutionPublicationFailed(
                    'The resolution adoption assessment changed before publication.',
                );
            }
        });
    }

    /**
     * @param  list<string>  $requirements
     * @param  array<string, mixed>  $projectConfig
     */
    private function commitPublication(
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        int $receiptId,
        PublishedOrbitResolution $published,
        bool $adopt,
        string $expectedResumePhase,
        array $requirements,
        string $reason,
        array $projectConfig,
    ): void {
        DB::transaction(function () use (
            $deliveryId,
            $phaseRunId,
            $dispatchId,
            $receiptId,
            $published,
            $adopt,
            $expectedResumePhase,
            $requirements,
            $reason,
            $projectConfig,
        ): void {
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
            $receipt = $receipts->firstWhere('id', $receiptId);
            $latestResolution = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->sortByDesc('attempt')
                ->first();
            $phaseDispatches = $dispatches->where('phase_run_id', $phaseRunId);
            $phaseReceipts = $receipts->where('phase_run_id', $phaseRunId);
            $priorAdoptions = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->where('id', '!=', $phaseRunId)
                ->filter(static fn (PhaseRun $item): bool => is_array($item->output)
                    && ($item->output['adopted'] ?? null) === true)
                ->count();
            $delivery->setRelation('projectOrchestration', $project);

            if ($phase === null || $dispatch === null || $receipt === null) {
                throw new OrbitResolutionPublicationFailed('The resolution ledger changed during publication.');
            }

            $currentAdoption = $this->adoptions->assess($phase, $receipt, $priorAdoptions);

            if ($latestResolution?->id === $phase->id
                && $this->publicationWasCommitted($delivery, $phase, $dispatch, $receipt, $currentAdoption)) {
                return;
            }

            $failure = $delivery->failure_details;
            $failureCode = is_array($failure) ? ($failure['code'] ?? null) : null;
            $validPublicationBlock = $failureCode === 'resolution_proposal_ready'
                || ($failureCode === 'resolution_publication_reconciliation_required'
                    && $phase->current_block === 'resolution_publication');
            $expectedPublication = $this->expectedPublication($dispatch, $receipt);

            if ($delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->status !== DeliveryStatus::Blocked
                || $project->state !== ProjectOrchestrationState::Enabled
                || $project->config !== $projectConfig
                || ! is_array($failure) || ! $validPublicationBlock
                || ($failure['phase_run_id'] ?? null) !== $phase->id
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || ($failure['receipt_id'] ?? null) !== $receipt->id
                || $latestResolution?->id !== $phase->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->attempt !== 1
                || $phase->status !== PhaseRunStatus::Completed
                || $phase->finished_at === null
                || $phase->current_block !== 'resolution_publication'
                || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'proposal']
                || $phaseDispatches->count() !== 1
                || $phaseReceipts->count() !== 1
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
                || $dispatch->status !== AgentDispatchStatus::Settled
                || $dispatch->settled_at === null
                || $receipt->phase_run_id !== $phase->id
                || ($receipt->payload['result'] ?? null) !== 'proposal'
                || $currentAdoption->adopt !== $adopt
                || $currentAdoption->expectedResumePhase !== $expectedResumePhase
                || $currentAdoption->requirements !== $requirements
                || $currentAdoption->reason !== $reason
                || $published->marker !== $expectedPublication['marker']
                || ! $this->isUuid($published->commentId)
                || ! hash_equals($expectedPublication['body_sha256'], $published->bodyHash)
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitResolutionPublicationFailed('The resolution ledger changed during publication.');
            }

            $publication = [
                'comment_id' => $published->commentId,
                'marker' => $published->marker,
                'body_sha256' => $published->bodyHash,
            ];
            $phase->current_block = null;
            $phase->output = [
                'receipt_id' => $receipt->id,
                'result' => 'proposal',
                'publication' => $publication,
                'adopted' => false,
                'automatic_adoption_eligible' => $adopt,
                'expected_resume_phase' => $expectedResumePhase,
                'requirements' => $requirements,
                'reason' => $reason,
            ];
            $phase->save();
            $delivery->failure_details = [
                'code' => $adopt ? 'resolution_adoption_ready' : 'resolution_decision_required',
                'phase_run_id' => $phase->id,
                'dispatch_id' => $dispatch->id,
                'receipt_id' => $receipt->id,
                'publication' => $publication,
                'expected_resume_phase' => $expectedResumePhase,
                'requirements' => $requirements,
                'reason' => $reason,
            ];
            $delivery->save();
        });
    }

    private function publicationWasCommitted(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
        OrbitResolutionAdoption $adoption,
    ): bool {
        $output = $phase->output;
        $failure = $delivery->failure_details;
        $hasOutput = is_array($output) && array_key_exists('publication', $output);
        $hasFinalFailure = is_array($failure)
            && in_array($failure['code'] ?? null, [
                'resolution_adoption_ready',
                'resolution_decision_required',
            ], true);

        if (! $hasOutput && ! $hasFinalFailure) {
            return false;
        }

        $publication = is_array($output) && is_array($output['publication'] ?? null)
            ? $output['publication']
            : null;
        $expectedCode = $adoption->adopt
            ? 'resolution_adoption_ready'
            : 'resolution_decision_required';
        $expectedPublication = $this->expectedPublication($dispatch, $receipt);

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $delivery->status !== DeliveryStatus::Blocked
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $phase->attempt !== 1
            || $phase->status !== PhaseRunStatus::Completed
            || $phase->finished_at === null
            || $phase->current_block !== null
            || $dispatch->phase_run_id !== $phase->id
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || $dispatch->status !== AgentDispatchStatus::Settled
            || $dispatch->settled_at === null
            || $receipt->phase_run_id !== $phase->id
            || ($receipt->payload['result'] ?? null) !== 'proposal'
            || ! is_array($output)
            || ! $this->hasExactKeys($output, [
                'receipt_id',
                'result',
                'publication',
                'adopted',
                'automatic_adoption_eligible',
                'expected_resume_phase',
                'requirements',
                'reason',
            ])
            || ($output['receipt_id'] ?? null) !== $receipt->id
            || ($output['result'] ?? null) !== 'proposal'
            || $output['adopted'] !== false
            || ($output['automatic_adoption_eligible'] ?? null) !== $adoption->adopt
            || ($output['expected_resume_phase'] ?? null) !== $adoption->expectedResumePhase
            || ($output['requirements'] ?? null) !== $adoption->requirements
            || ($output['reason'] ?? null) !== $adoption->reason
            || ! is_array($publication)
            || ! $this->hasExactKeys($publication, ['comment_id', 'marker', 'body_sha256'])
            || ! $this->isUuid($publication['comment_id'] ?? null)
            || ($publication['marker'] ?? null) !== $expectedPublication['marker']
            || ! is_string($publication['body_sha256'] ?? null)
            || ! hash_equals($expectedPublication['body_sha256'], $publication['body_sha256'])
            || ! is_array($failure)
            || ! $this->hasExactKeys($failure, [
                'code',
                'phase_run_id',
                'dispatch_id',
                'receipt_id',
                'publication',
                'expected_resume_phase',
                'requirements',
                'reason',
            ])
            || ($failure['code'] ?? null) !== $expectedCode
            || ($failure['phase_run_id'] ?? null) !== $phase->id
            || ($failure['dispatch_id'] ?? null) !== $dispatch->id
            || ($failure['receipt_id'] ?? null) !== $receipt->id
            || ($failure['publication'] ?? null) !== $publication
            || ($failure['expected_resume_phase'] ?? null) !== $adoption->expectedResumePhase
            || ($failure['requirements'] ?? null) !== $adoption->requirements
            || ($failure['reason'] ?? null) !== $adoption->reason
            || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
            throw new OrbitResolutionPublicationFailed(
                'The retained resolution publication is inconsistent.',
            );
        }

        return true;
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

    /** @return array{marker: string, body_sha256: string} */
    private function expectedPublication(AgentDispatch $dispatch, Receipt $receipt): array
    {
        $marker = "ORBIT-LOOP-RESOLUTION:{$dispatch->id}";
        $body = implode("\n\n", [
            $marker,
            $this->string($receipt->payload, 'handoff'),
            'Commander routing: Needs an explicit decision or recovery action.',
        ]);

        return [
            'marker' => $marker,
            'body_sha256' => hash('sha256', $body),
        ];
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }

    private function assertIssue(Delivery $delivery, string $contractHash, OrbitIssueSnapshot $issue): void
    {
        if ($issue->issueId !== $delivery->external_issue_id
            || $issue->issueKey !== $delivery->external_issue_key
            || ! $this->snapshots->matchesExpectedContract($issue, $contractHash, $delivery->pull_request_url)) {
            throw new OrbitResolutionPublicationFailed('The Orbit issue changed during resolution publication.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitResolutionPublicationFailed("The resolution receipt has an invalid {$key}.");
        }

        return $value;
    }
}
