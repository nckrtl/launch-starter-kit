<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;

final readonly class RecoverOrbitPullRequestReviewTransition
{
    public function __construct(
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitActiveIssueProvider $issues,
        private OrbitPullRequestReviewSourceValidator $sources,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    public function handle(int $deliveryId): PhaseRun
    {
        $delivery = Delivery::query()->with('projectOrchestration')->find($deliveryId);

        if ($delivery === null) {
            throw new OrbitPullRequestReviewDispatchFailed('The Orbit delivery does not exist.');
        }

        $preparation = $this->preparations->startup($delivery);
        $issue = $this->issues->fetchActive(
            $preparation->snapshot->issueId,
            $preparation->snapshot->issueKey,
        );
        $this->assertExactInReview($delivery, $preparation, $issue);

        return DB::transaction(function () use ($deliveryId): PhaseRun {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $project = $delivery->projectOrchestration()->lockForUpdate()->firstOrFail();
            $phases = PhaseRun::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $phase = $phases
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->sortByDesc('attempt')
                ->first();

            if ($phase === null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The blocked pull request review phase is missing.',
                );
            }

            $dispatches = AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatch = $dispatches->first();
            $failure = $delivery->failure_details;
            $source = $this->sources->sourceReceipt($delivery, $phase);
            $expectedKey = IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::PR_REVIEW_PHASE,
                $phase->attempt,
                OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            )->value;

            if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
                || $delivery->status !== DeliveryStatus::Blocked
                || ! is_array($failure)
                || ($failure['code'] ?? null) !== 'linear_pr_review_transition_ambiguous'
                || $project->state !== ProjectOrchestrationState::Enabled
                || ! in_array($phase->attempt, [1, 2], true)
                || $phase->status !== PhaseRunStatus::Running
                || $source === null
                || $dispatches->count() !== 1
                || $dispatch === null
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->status !== AgentDispatchStatus::Ambiguous
                || $dispatch->error_code !== 'linear_pr_review_transition_ambiguous'
                || $dispatch->herdr_session !== null
                || $dispatch->herdr_workspace_id !== null
                || $dispatch->herdr_tab_id !== null
                || $dispatch->herdr_pane_id !== null
                || $dispatch->herdr_terminal_id !== null
                || $dispatch->herdr_agent_id !== null
                || $dispatch->dispatched_at !== null
                || $dispatch->settled_at !== null) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The blocked Linear pull request review transition is not safe to recover.',
                );
            }

            $dispatch->forceFill([
                'status' => AgentDispatchStatus::Pending,
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $delivery->forceFill([
                'status' => DeliveryStatus::Preparing,
                'failure_details' => null,
            ])->save();

            return $phase;
        });
    }

    private function assertExactInReview(
        Delivery $delivery,
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
    ): void {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');

        if ($issue->issueId !== $preparation->snapshot->issueId
            || $issue->issueKey !== $preparation->snapshot->issueKey
            || ! $this->snapshots->matchesExpectedContract(
                $issue,
                $preparation->snapshot->contractHash,
                $delivery->pull_request_url,
            )
            || ! is_array($state)
            || ($state['name'] ?? null) !== 'In Review'
            || ($state['type'] ?? null) !== 'started'
            || ! is_string($viewerId)
            || ! is_array($delegate)
            || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || $issue->payload['assignee'] !== null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'Linear does not confirm the exact In Review state and ownership for recovery.',
            );
        }
    }
}
