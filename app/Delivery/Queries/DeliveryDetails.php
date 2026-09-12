<?php

declare(strict_types=1);

namespace App\Delivery\Queries;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewWaitPolicy;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;

final readonly class DeliveryDetails
{
    public function __construct(private OrbitPullRequestReviewWaitPolicy $reviewWaits) {}

    /**
     * @return array{
     *   delivery: array<string, mixed>,
     *   current_phase_run: array<string, mixed>|null,
     *   wait: array{reason: string, details: array<string, mixed>}|null
     * }
     */
    public function get(int $deliveryId): array
    {
        $delivery = Delivery::query()
            ->with('projectOrchestration:id,manifest_project_id')
            ->findOrFail($deliveryId);
        $phaseRun = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->first();
        $dispatch = $phaseRun === null
            ? null
            : AgentDispatch::query()->where('phase_run_id', $phaseRun->id)->latest('id')->first();

        return [
            'delivery' => [
                'id' => $delivery->id,
                'project_id' => $delivery->projectOrchestration->manifest_project_id,
                'issue_provider' => $delivery->external_issue_provider,
                'issue_id' => $delivery->external_issue_id,
                'issue_key' => $delivery->external_issue_key,
                'workflow_type' => $delivery->workflow_type,
                'workflow_version' => $delivery->workflow_version,
                'status' => $delivery->status->value,
                'current_phase' => $delivery->current_phase,
                'branch' => $delivery->branch,
                'worktree' => $delivery->worktree_path,
                'candidate_sha' => $delivery->candidate_sha,
                'pull_request_number' => $delivery->pull_request_number,
                'pull_request_url' => $delivery->pull_request_url,
                'failure' => $delivery->failure_details,
                'completion' => $delivery->completion_details,
                'created_at' => $delivery->created_at?->toISOString(),
                'updated_at' => $delivery->updated_at?->toISOString(),
                'completed_at' => $delivery->completed_at?->toISOString(),
                'failed_at' => $delivery->failed_at?->toISOString(),
            ],
            'current_phase_run' => $phaseRun === null ? null : [
                'id' => $phaseRun->id,
                'phase' => $phaseRun->phase_name,
                'attempt' => $phaseRun->attempt,
                'status' => $phaseRun->status->value,
                'dispatch_status' => $dispatch?->status->value,
                'agent_name' => $dispatch?->herdr_agent_name,
            ],
            'wait' => $this->wait($delivery, $dispatch),
        ];
    }

    /** @return array{reason: string, details: array<string, mixed>}|null */
    private function wait(Delivery $delivery, ?AgentDispatch $dispatch): ?array
    {
        if ($delivery->status === DeliveryStatus::Blocked) {
            return ['reason' => 'blocked', 'details' => $delivery->failure_details ?? []];
        }

        if ($dispatch?->status === AgentDispatchStatus::Waiting) {
            $details = ['agent_name' => $dispatch->herdr_agent_name];

            if ($this->isOrbitPullRequestReview($delivery) && $dispatch->dispatched_at !== null) {
                $deadline = $this->reviewWaits->waitDeadline($dispatch->dispatched_at);
                $details = [
                    ...$details,
                    'dispatch_id' => $dispatch->id,
                    'dispatched_at' => $dispatch->dispatched_at->toISOString(),
                    'wait_deadline_at' => $deadline->toISOString(),
                    'overdue' => $this->reviewWaits->isOverdue($deadline),
                ];
            }

            return ['reason' => 'herdr_agent', 'details' => $details];
        }

        if ($dispatch?->status === AgentDispatchStatus::Settled) {
            $details = ['dispatch_id' => $dispatch->id];

            if ($this->isOrbitPullRequestReview($delivery) && $dispatch->settled_at !== null) {
                $deadline = $this->reviewWaits->receiptDeadline($dispatch->settled_at);
                $details = [
                    ...$details,
                    'settled_at' => $dispatch->settled_at->toISOString(),
                    'receipt_deadline_at' => $deadline->toISOString(),
                    'overdue' => $this->reviewWaits->isOverdue($deadline),
                ];
            }

            return ['reason' => 'receipt', 'details' => $details];
        }

        return null;
    }

    private function isOrbitPullRequestReview(Delivery $delivery): bool
    {
        return $delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION
            && $delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE;
    }
}
