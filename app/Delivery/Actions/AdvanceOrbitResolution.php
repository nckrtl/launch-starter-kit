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
        $preparation = $this->preparations->startup($delivery);
        $issue = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $this->assertIssue($delivery, $preparation->snapshot->contractHash, $issue);
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

            if ($phase !== null && $this->publicationWasCommitted($delivery, $phase, $receipt)) {
                return null;
            }

            $failure = $delivery->failure_details;

            if ($phase === null || $dispatch === null || $receipt === null
                || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Blocked
                || ! is_array($failure) || ($failure['code'] ?? null) !== 'resolution_proposal_ready'
                || ($failure['phase_run_id'] ?? null) !== $phase->id
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || ($failure['receipt_id'] ?? null) !== $receipt->id
                || $phase->delivery_id !== $delivery->id
                || $phase->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $phase->status !== PhaseRunStatus::Completed
                || ! in_array($phase->current_block, [null, 'resolution_publication'], true)
                || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'proposal']
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Settled
                || ($receipt->payload['result'] ?? null) !== 'proposal'
                || ! $this->receipts->matches($delivery, $phase, $dispatch, $receipt)) {
                throw new OrbitResolutionPublicationFailed('The retained resolution proposal is inconsistent.');
            }

            $priorAdoptions = $phases
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->where('id', '!=', $phase->id)
                ->filter(static fn (PhaseRun $item): bool => is_array($item->output)
                    && ($item->output['adopted'] ?? null) === true)
                ->count();
            $adoption = $this->adoptions->assess($phase, $receipt, $priorAdoptions);
            $phase->current_block = 'resolution_publication';
            $phase->save();

            return [$delivery, $phase, $dispatch, $receipt, $adoption];
        });
    }

    /** @param list<string> $requirements */
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
        ): void {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseRunId)->lockForUpdate()->firstOrFail();
            $dispatch = AgentDispatch::query()->whereKey($dispatchId)->lockForUpdate()->firstOrFail();
            $receipt = Receipt::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();

            if ($this->publicationWasCommitted($delivery, $phase, $receipt)) {
                return;
            }

            $failure = $delivery->failure_details;

            if ($delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
                || $delivery->status !== DeliveryStatus::Blocked
                || ! is_array($failure) || ($failure['code'] ?? null) !== 'resolution_proposal_ready'
                || $phase->status !== PhaseRunStatus::Completed
                || $phase->current_block !== 'resolution_publication'
                || $phase->output !== ['receipt_id' => $receipt->id, 'result' => 'proposal']
                || $dispatch->phase_run_id !== $phase->id
                || $dispatch->status !== AgentDispatchStatus::Settled
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

    private function publicationWasCommitted(Delivery $delivery, ?PhaseRun $phase, ?Receipt $receipt): bool
    {
        $output = $phase?->output;
        $failure = $delivery->failure_details;

        return $phase !== null && $receipt !== null
            && $phase->current_block === null
            && is_array($output) && is_array($output['publication'] ?? null)
            && ($output['receipt_id'] ?? null) === $receipt->id
            && ($output['result'] ?? null) === 'proposal'
            && is_array($failure)
            && in_array($failure['code'] ?? null, ['resolution_adoption_ready', 'resolution_decision_required'], true)
            && ($failure['phase_run_id'] ?? null) === $phase->id
            && ($failure['receipt_id'] ?? null) === $receipt->id;
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
