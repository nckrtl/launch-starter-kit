<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Delivery\Actions\BindOrbitPullRequestReviewPublicationRecovery;
use App\Delivery\Actions\DispatchOrbitPullRequestReview as DispatchOrbitPullRequestReviewAction;
use App\Delivery\Actions\RecoverExhaustedOrbitPlanningCorrection;
use App\Delivery\Actions\RecoverExhaustedOrbitPlanResolution;
use App\Delivery\Actions\RecoverInitialOrbitPlanningBlocker;
use App\Delivery\Actions\RecoverOrbitPullRequestReviewTransition;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ReconcileDeliveries implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int CHUNK_SIZE = 100;

    public int $timeout = 45;

    public int $tries = 0;

    public int $uniqueFor = 120;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    private CarbonImmutable $retryDeadline;

    public function __construct()
    {
        $this->retryDeadline = now()->addMinutes(5)->toImmutable();
    }

    public function uniqueId(): string
    {
        return 'commander-delivery-reconciliation';
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryDeadline;
    }

    public function handle(
        RecoverExhaustedOrbitPlanningCorrection $planningCorrections,
        RecoverExhaustedOrbitPlanResolution $planResolutions,
        BindOrbitPullRequestReviewPublicationRecovery $reviewPublications,
        ?DispatchOrbitPullRequestReviewAction $reviewDispatches = null,
        ?RecoverOrbitPullRequestReviewTransition $reviewTransitionRecoveries = null,
        ?RecoverInitialOrbitPlanningBlocker $initialPlanningBlockers = null,
    ): void {
        $reviewDispatches ??= app(DispatchOrbitPullRequestReviewAction::class);
        $reviewTransitionRecoveries ??= app(RecoverOrbitPullRequestReviewTransition::class);
        $initialPlanningBlockers ??= app(RecoverInitialOrbitPlanningBlocker::class);

        Delivery::query()
            ->select([
                'deliveries.id',
                'deliveries.status',
                'deliveries.current_phase',
                'deliveries.failure_details',
                'deliveries.workflow_type',
                'deliveries.workflow_version',
            ])
            ->whereHas('projectOrchestration', function (Builder $query): void {
                $query->where('state', ProjectOrchestrationState::Enabled->value);
            })
            ->where(function (Builder $query): void {
                $query->whereIn('status', $this->reconcilableStatuses())
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Failed)
                            ->where('current_phase', OrbitFeatureWorkflow::INITIAL_PHASE)
                            ->where('failure_details->code', 'planning_correction_dispatch_exhausted');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Failed)
                            ->where('current_phase', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                            ->where('failure_details->code', 'resolution_dispatch_exhausted');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::INITIAL_PHASE)
                            ->where('failure_details->code', 'planning_blocked');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                            ->whereIn('failure_details->code', [
                                'resolution_dispatch_failed',
                                'resolution_publication_reconciliation_required',
                                'resolution_adoption_ready',
                                'resolution_adoption_reconciliation_required',
                            ]);
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                            ->where(
                                'failure_details->code',
                                'pr_review_publication_reconciliation_required',
                            );
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                            ->where('failure_details->code', 'herdr_start_ambiguous');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', DeliveryStatus::Blocked)
                            ->where('current_phase', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                            ->where('failure_details->code', 'linear_pr_review_transition_ambiguous');
                    });
            })
            ->orderBy('deliveries.id')
            ->chunkById(self::CHUNK_SIZE, function ($deliveries) use (
                $planningCorrections,
                $planResolutions,
                $reviewPublications,
                $reviewDispatches,
                $reviewTransitionRecoveries,
                $initialPlanningBlockers,
            ): void {
                foreach ($deliveries as $delivery) {
                    $this->dispatchRecovery(
                        $delivery,
                        $planningCorrections,
                        $planResolutions,
                        $reviewPublications,
                        $reviewDispatches,
                        $reviewTransitionRecoveries,
                        $initialPlanningBlockers,
                    );
                }
            }, 'deliveries.id', 'id');
    }

    private function dispatchRecovery(
        Delivery $delivery,
        RecoverExhaustedOrbitPlanningCorrection $planningCorrections,
        RecoverExhaustedOrbitPlanResolution $planResolutions,
        BindOrbitPullRequestReviewPublicationRecovery $reviewPublications,
        DispatchOrbitPullRequestReviewAction $reviewDispatches,
        RecoverOrbitPullRequestReviewTransition $reviewTransitionRecoveries,
        RecoverInitialOrbitPlanningBlocker $initialPlanningBlockers,
    ): void {
        if ($delivery->status === DeliveryStatus::Failed) {
            if ($planningCorrections->handle($delivery->id)
                || $planResolutions->handle($delivery->id)) {
                AdvanceDelivery::dispatch($delivery->id);
            }

            return;
        }

        if ($delivery->status === DeliveryStatus::Blocked) {
            $phaseRunId = $delivery->failure_details['phase_run_id'] ?? null;
            $failureCode = $delivery->failure_details['code'] ?? null;

            if ($delivery->current_phase === OrbitFeatureWorkflow::INITIAL_PHASE
                && $failureCode === 'planning_blocked') {
                if ($initialPlanningBlockers->handle($delivery->id)) {
                    AdvanceDelivery::dispatch($delivery->id);
                }

                return;
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                && $failureCode === 'pr_review_publication_reconciliation_required') {
                $phaseRunId = is_int($phaseRunId)
                    ? $phaseRunId
                    : $reviewPublications->handle($delivery->id);

                if (is_int($phaseRunId)) {
                    AdvanceOrbitPullRequestReview::dispatch($delivery->id, $phaseRunId);
                }
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                && $failureCode === 'herdr_start_ambiguous') {
                $phaseRunId = $reviewDispatches->bindAmbiguousStartRecovery($delivery->id);

                if (is_int($phaseRunId)) {
                    DispatchOrbitPullRequestReview::dispatch($delivery->id, $phaseRunId);
                }
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                && $failureCode === 'linear_pr_review_transition_ambiguous') {
                $phaseRunId = $reviewTransitionRecoveries->bindAmbiguousTransitionRecovery($delivery->id);

                if (is_int($phaseRunId)) {
                    RecoverAmbiguousOrbitPullRequestReviewTransition::dispatch(
                        $delivery->id,
                        $phaseRunId,
                    );
                }
            }

            if ($delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
                && is_int($phaseRunId)) {
                if ($failureCode === 'resolution_dispatch_failed') {
                    DispatchOrbitPullRequestResolution::dispatch($delivery->id, $phaseRunId);
                }

                if ($failureCode === 'resolution_publication_reconciliation_required') {
                    AdvanceOrbitResolution::dispatch($delivery->id, $phaseRunId);
                }

                if (in_array($failureCode, [
                    'resolution_adoption_ready',
                    'resolution_adoption_reconciliation_required',
                ], true)) {
                    AdoptOrbitResolution::dispatch($delivery->id, $phaseRunId);
                }
            }

            return;
        }

        if ($delivery->status !== DeliveryStatus::WaitingForAgent) {
            AdvanceDelivery::dispatch($delivery->id);

            return;
        }

        $phase = PhaseRun::query()
            ->where('delivery_id', $delivery->id)
            ->where('phase_name', $delivery->current_phase)
            ->latest('attempt')
            ->first();
        $dispatches = $phase === null
            ? collect()
            : AgentDispatch::query()
                ->where('phase_run_id', $phase->id)
                ->orderBy('id')
                ->get();

        if ($phase === null
            || $phase->status !== PhaseRunStatus::Running
            || $dispatches->count() !== 1) {
            AdvanceDelivery::dispatch($delivery->id);

            return;
        }

        $dispatch = $dispatches->firstOrFail();
        $phaseReceipts = $phase->receipts()->get();
        $validReceipts = $phaseReceipts->filter(
            static fn (Receipt $receipt): bool => $receipt->validation_status === ReceiptValidationStatus::Valid,
        );
        $hasValidReceipt = $validReceipts->isNotEmpty();

        if ($delivery->workflow_type === OrbitFeatureWorkflow::TYPE
            && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION) {
            $expectedReceiptKind = $this->expectedOrbitReceiptKind($phase->phase_name);
            $expectedAgentRole = $this->expectedOrbitAgentRole($phase->phase_name);
            $hasValidReceipt = $expectedReceiptKind !== null
                && $dispatch->agent_role === $expectedAgentRole
                && $validReceipts->where('kind', $expectedReceiptKind)->count() === 1
                && ($phase->phase_name === OrbitFeatureWorkflow::PR_REVIEW_PHASE
                    || $phaseReceipts->count() === 1);
        }

        if ($dispatch->status === AgentDispatchStatus::Settled
            && $hasValidReceipt) {
            AdvanceDelivery::dispatch($delivery->id);

            return;
        }

        ReconcileDelivery::dispatch(
            $delivery->id,
            $phase->id,
            $dispatch->id,
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }
    }

    /** @return list<string> */
    private function reconcilableStatuses(): array
    {
        return array_map(
            static fn (DeliveryStatus $status): string => $status->value,
            [
                DeliveryStatus::Queued,
                DeliveryStatus::Preparing,
                DeliveryStatus::WaitingForAgent,
                DeliveryStatus::ValidatingReceipt,
                DeliveryStatus::WaitingForChanges,
                DeliveryStatus::ReadyToMerge,
                DeliveryStatus::Merging,
                DeliveryStatus::Landed,
                DeliveryStatus::Cleaning,
            ],
        );
    }

    private function expectedOrbitReceiptKind(string $phaseName): ?string
    {
        return match ($phaseName) {
            OrbitFeatureWorkflow::INITIAL_PHASE => 'orbit_planning',
            OrbitFeatureWorkflow::PLAN_REVIEW_PHASE => 'orbit_plan_review',
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => 'orbit_implementation',
            OrbitFeatureWorkflow::PR_REVIEW_PHASE => 'orbit_pr_review',
            OrbitFeatureWorkflow::RESOLUTION_PHASE => 'orbit_resolution',
            default => null,
        };
    }

    private function expectedOrbitAgentRole(string $phaseName): ?string
    {
        return match ($phaseName) {
            OrbitFeatureWorkflow::INITIAL_PHASE => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
            OrbitFeatureWorkflow::PLAN_REVIEW_PHASE => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
            OrbitFeatureWorkflow::IMPLEMENTATION_PHASE => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
            OrbitFeatureWorkflow::PR_REVIEW_PHASE => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            OrbitFeatureWorkflow::RESOLUTION_PHASE => OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
            default => null,
        };
    }
}
