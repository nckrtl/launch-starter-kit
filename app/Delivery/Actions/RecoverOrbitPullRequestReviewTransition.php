<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewDispatchFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\Workflow\IdempotencyKey;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class RecoverOrbitPullRequestReviewTransition
{
    public function __construct(
        private ResolveOrbitDeliveryPreparation $preparations,
        private OrbitActiveIssueProvider $issues,
        private OrbitPullRequestReviewSourceValidator $sources,
        private OrbitIssueSnapshotFactory $snapshots,
        private OrbitPullRequestInspector $pullRequests,
        private OrbitIssueTransitioner $transitions,
        private OrbitReviewIssueTransitioner $reviewTransitions,
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
        $failure = $delivery->failure_details;
        $failureCode = is_array($failure) ? ($failure['code'] ?? null) : null;
        $mergeable = true;

        if ($failureCode === 'pr_review_mergeability_changed') {
            $state = $issue->payload['state'] ?? null;
            $stateName = is_array($state) ? ($state['name'] ?? null) : null;

            if (! is_string($stateName) || ! in_array($stateName, ['In Review', 'In Progress'], true)) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'Linear does not confirm an exact active state and ownership for recovery.',
                );
            }

            $this->assertExactActiveState($delivery, $preparation, $issue, $stateName);
            $phase = $delivery->phaseRuns()
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->latest('attempt')
                ->first();
            $source = $phase === null ? null : $this->sources->sourceReceipt($delivery, $phase);
            $mergeable = $this->inspectPullRequest($delivery, $source);

            if (! $mergeable) {
                if ($stateName === 'In Review') {
                    try {
                        $issue = $this->transitions->transitionToInProgress($issue, $issue->contractHash);
                    } catch (OrbitIssueTransitionFailed $exception) {
                        throw new OrbitPullRequestReviewDispatchFailed(
                            'The Linear merge-conflict correction transition could not be verified.',
                            0,
                            $exception,
                        );
                    }
                }

                $this->assertExactActiveState($delivery, $preparation, $issue, 'In Progress');
            } elseif ($stateName !== 'In Review') {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'Linear already entered correction while the pull request is mergeable.',
                );
            }
        } elseif ($failureCode !== 'linear_pr_review_transition_ambiguous') {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The blocked pull request review failure is not recoverable.',
            );
        } else {
            if ($this->isPartialInReviewState($delivery, $preparation, $issue)) {
                try {
                    $issue = $this->reviewTransitions->transitionToInReview(
                        $issue,
                        $issue->contractHash,
                    );
                } catch (OrbitIssueTransitionFailed $exception) {
                    throw new OrbitPullRequestReviewDispatchFailed(
                        'The partial Linear In Review transition could not be completed.',
                        0,
                        $exception,
                    );
                }
            }

            $this->assertExactActiveState($delivery, $preparation, $issue, 'In Review');
        }
        $expectedDispatchStatus = $failureCode === 'linear_pr_review_transition_ambiguous'
            ? AgentDispatchStatus::Ambiguous
            : AgentDispatchStatus::Failed;

        return DB::transaction(function () use (
            $deliveryId,
            $expectedDispatchStatus,
            $failureCode,
            $mergeable,
        ): PhaseRun {
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
                || ($failure['code'] ?? null) !== $failureCode
                || $project->state !== ProjectOrchestrationState::Enabled
                || $phase->attempt < 1
                || $phase->status !== PhaseRunStatus::Running
                || $source === null
                || $dispatches->count() !== 1
                || $dispatch === null
                || ($failure['dispatch_id'] ?? null) !== $dispatch->id
                || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
                || $dispatch->idempotency_key !== $expectedKey
                || $dispatch->status !== $expectedDispatchStatus
                || $dispatch->error_code !== $failureCode
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

            if ($mergeable) {
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
            }

            if ($phase->receipts()->exists()) {
                throw new OrbitPullRequestReviewDispatchFailed(
                    'The merge-conflicted pull request review cannot enter a Builder correction.',
                );
            }

            $sourcePhase = $source->phaseRun()->firstOrFail();
            $input = [
                'implementation_receipt_id' => $source->id,
                'implementation_receipt' => $source->payload,
                'pull_request' => [
                    'number' => $delivery->pull_request_number,
                    'url' => $delivery->pull_request_url,
                    'mergeable' => false,
                ],
            ];
            $correction = PhaseRun::query()->create([
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                'attempt' => $sourcePhase->attempt + 1,
                'status' => PhaseRunStatus::Pending,
                'input' => $input,
            ]);
            AgentDispatch::query()->create([
                'phase_run_id' => $correction->id,
                'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                'idempotency_key' => IdempotencyKey::forDispatch(
                    $delivery->id,
                    OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                    $correction->attempt,
                    OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
                )->value,
                'herdr_agent_name' => strtolower((string) $delivery->external_issue_key).'-loop-builder',
                'prompt_name' => 'orbit_implementation_correction',
                'prompt_version' => OrbitFeatureWorkflow::IMPLEMENTATION_CORRECTION_PROMPT_VERSION,
                'prompt_hash' => str_repeat('0', 64),
                'status' => AgentDispatchStatus::Pending,
            ]);
            $phase->forceFill([
                'status' => PhaseRunStatus::Failed,
                'failure_code' => 'pr_review_mergeability_changed',
                'failure_message' => 'The published pull request became unmergeable before independent review.',
                'finished_at' => now(),
            ])->save();
            $delivery->forceFill([
                'current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
                'status' => DeliveryStatus::Queued,
                'failure_details' => null,
            ])->save();

            return $correction;
        });
    }

    private function assertExactActiveState(
        Delivery $delivery,
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
        string $expectedState,
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
            || ($state['name'] ?? null) !== $expectedState
            || ($state['type'] ?? null) !== 'started'
            || ! is_string($viewerId)
            || ! is_array($delegate)
            || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $issue->payload)
            || $issue->payload['assignee'] !== null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                "Linear does not confirm the exact {$expectedState} state and ownership for recovery.",
            );
        }
    }

    private function isPartialInReviewState(
        Delivery $delivery,
        OrbitDeliveryPreparation $preparation,
        OrbitIssueSnapshot $issue,
    ): bool {
        $state = $issue->payload['state'] ?? null;
        $delegate = $issue->payload['delegate'] ?? null;
        $assignee = $issue->payload['assignee'] ?? null;
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $nickId = config('commander.hermes.nick_linear_user_id');

        return $issue->issueId === $preparation->snapshot->issueId
            && $issue->issueKey === $preparation->snapshot->issueKey
            && $this->snapshots->matchesExpectedContract(
                $issue,
                $preparation->snapshot->contractHash,
                $delivery->pull_request_url,
            )
            && is_array($state)
            && ($state['name'] ?? null) === 'In Review'
            && ($state['type'] ?? null) === 'started'
            && is_string($viewerId)
            && is_string($nickId)
            && is_array($delegate)
            && ($delegate['id'] ?? null) === $viewerId
            && is_array($assignee)
            && ($assignee['id'] ?? null) === $nickId;
    }

    private function inspectPullRequest(Delivery $delivery, ?Receipt $source): bool
    {
        $payload = $source?->payload;
        $candidateSha = is_array($payload) ? ($payload['candidate_sha'] ?? null) : null;
        $body = is_array($payload) ? ($payload['pull_request_body'] ?? null) : null;
        $number = $delivery->pull_request_number;

        if (! is_string($candidateSha) || ! is_string($body) || ! is_int($number)) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The blocked pull request review has no valid implementation source.',
            );
        }

        $pullRequest = $this->pullRequests->inspect(
            $number,
            (string) $delivery->external_issue_key,
            $candidateSha,
            $body,
        );

        if ($pullRequest->number !== $number
            || $pullRequest->url !== $delivery->pull_request_url
            || $pullRequest->candidateSha !== $candidateSha
            || ! hash_equals($pullRequest->bodyHash, hash('sha256', $body))
            || $pullRequest->mergeable === null) {
            throw new OrbitPullRequestReviewDispatchFailed(
                'The blocked pull request could not be verified for recovery.',
            );
        }

        return $pullRequest->mergeable;
    }
}
