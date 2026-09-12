<?php

declare(strict_types=1);

use App\Delivery\Actions\CaptureHerdrEvent;
use App\Delivery\Actions\CaptureOrbitPullRequestReviewReceipt;
use App\Delivery\Actions\ReconcileOrbitPullRequestReviewWait;
use App\Delivery\Actions\ReconcileWaitingHerdrSettlement;
use App\Delivery\Actions\RecoverExhaustedOrbitPlanningCorrection;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\HerdrSettlementObservationFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewWaitPolicy;
use App\Jobs\AdvanceDelivery;
use App\Jobs\ReconcileDeliveries;
use App\Jobs\ReconcileDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ReviewWaitHerdrRuntime implements HerdrRuntime
{
    public ?Throwable $failure = null;

    public function __construct(public HerdrAgentIdentifiers $agent) {}

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        throw new LogicException('Unexpected Herdr worktree request.');
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        throw new LogicException('Unexpected Herdr pane request.');
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        throw new LogicException('Unexpected Herdr start request.');
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        throw new LogicException('Unexpected Herdr prompt request.');
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->agent;
    }
}

/** @return array{ProjectOrchestration, Delivery, PhaseRun, AgentDispatch, ReviewWaitHerdrRuntime} */
function orbitReviewWaitFixture(
    AgentDispatchStatus $dispatchStatus = AgentDispatchStatus::Waiting,
    ?CarbonImmutable $dispatchedAt = null,
    ?CarbonImmutable $settledAt = null,
    ?string $agentStatus = 'working',
    ?int $observedSequence = 11,
): array {
    $config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: '/home/nckrtl/orbit',
        worktreeRoot: '/fast/worktrees/orbit',
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
    $project = ProjectOrchestration::query()->create([
        'manifest_project_id' => 'orbit-review-wait-'.bin2hex(random_bytes(4)),
        'config' => $config->toArray(),
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $delivery = Delivery::query()->create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'review-wait-'.bin2hex(random_bytes(8)),
        'external_issue_key' => 'ORB-250',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::WaitingForAgent,
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'branch' => 'orb-250',
        'worktree_path' => '/fast/worktrees/orbit/orb-250',
        'candidate_sha' => str_repeat('a', 40),
    ]);
    $phase = PhaseRun::query()->create([
        'delivery_id' => $delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'started_at' => now()->subHours(2),
    ]);
    $dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'review-wait-'.bin2hex(random_bytes(8)),
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-review',
        'herdr_tab_id' => 'tab-review',
        'herdr_pane_id' => 'pane-review',
        'herdr_terminal_id' => 'terminal-review',
        'herdr_agent_id' => 'agent-review',
        'herdr_agent_name' => 'orb-250-loop-pr-reviewer',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => $dispatchStatus,
        'state_change_seq' => 10,
        'dispatched_at' => $dispatchedAt ?? now()->subHour(),
        'settled_at' => $settledAt,
    ]);
    $runtime = new ReviewWaitHerdrRuntime(new HerdrAgentIdentifiers(
        workspaceId: 'workspace-review',
        tabId: 'tab-review',
        paneId: 'pane-review',
        terminalId: 'terminal-review',
        agentId: 'agent-review',
        agentName: 'orb-250-loop-pr-reviewer',
        stateChangeSeq: $observedSequence,
        workingDirectory: '/fast/worktrees/orbit/orb-250',
        agentStatus: $agentStatus,
    ));
    app()->instance(HerdrRuntime::class, $runtime);

    return [$project, $delivery, $phase, $dispatch, $runtime];
}

function runReviewWaitJob(Delivery $delivery, PhaseRun $phase, AgentDispatch $dispatch): void
{
    (new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id))->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    );
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-12 12:00:00');
    config()->set('herdr.session', 'orbit');
    config()->set('herdr.orchestration.enabled', true);
});

afterEach(fn () => Carbon::setTestNow());

it('uses fixed wait and receipt deadlines whose equality is overdue', function (): void {
    $policy = app(OrbitPullRequestReviewWaitPolicy::class);
    $start = CarbonImmutable::parse('2026-09-12T10:00:00Z');

    expect($policy->waitDeadline($start)->toISOString())->toBe('2026-09-12T11:00:00.000000Z')
        ->and($policy->receiptDeadline($start)->toISOString())->toBe('2026-09-12T10:05:00.000000Z')
        ->and($policy->isOverdue($start, $start->subMicrosecond()))->toBeFalse()
        ->and($policy->isOverdue($start, $start))->toBeTrue();
});

it('keeps an exact reviewer unchanged before its wait deadline', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(
        dispatchedAt: now()->subHour()->addSecond()->toImmutable(),
    );

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($delivery->fresh()->failure_details)->toBeNull()
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($phase->fresh()->finished_at)->toBeNull()
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('blocks an overdue exact non-terminal reviewer with retained evidence', function (?string $status): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(agentStatus: $status);

    runReviewWaitJob($delivery, $phase, $dispatch);

    $failure = $phase->fresh()->failure_details;

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_wait_timeout')
        ->and($failure)->toMatchArray([
            'code' => 'pr_review_wait_timeout',
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
            'dispatch_status' => AgentDispatchStatus::Waiting->value,
            'observed_agent_status' => $status,
            'observed_working_directory' => '/fast/worktrees/orbit/orb-250',
        ])
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
})->with(['working', 'blocked', 'unknown', null]);

it('settles an overdue exact terminal observation before applying the timeout', function (string $status): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(agentStatus: $status);
    Queue::fake([AdvanceDelivery::class]);

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->fresh()->state_change_seq)->toBe(11)
        ->and(ExternalEvent::sole()->agent_dispatch_id)->toBe($dispatch->id);
    Queue::assertPushed(AdvanceDelivery::class, 1);
})->with(['idle', 'done']);

it('does not claim settlement from a stale or missing terminal sequence', function (?int $sequence): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(
        dispatchedAt: now()->subMinutes(30)->toImmutable(),
        agentStatus: 'done',
        observedSequence: $sequence,
    );

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and(ExternalEvent::count())->toBe(0);
})->with([10, null]);

it('blocks every changed Herdr identity only after the review deadline', function (string $identity): void {
    [, $delivery, $phase, $dispatch, $runtime] = orbitReviewWaitFixture();
    $agent = $runtime->agent;
    $runtime->agent = new HerdrAgentIdentifiers(
        workspaceId: $identity === 'workspace' ? 'changed' : $agent->workspaceId,
        tabId: $identity === 'tab' ? 'changed' : $agent->tabId,
        paneId: $identity === 'pane' ? 'changed' : $agent->paneId,
        terminalId: $identity === 'terminal' ? 'changed' : $agent->terminalId,
        agentId: $identity === 'agent' ? 'changed' : $agent->agentId,
        agentName: $identity === 'name' ? 'changed' : $agent->agentName,
        stateChangeSeq: $agent->stateChangeSeq,
        workingDirectory: $identity === 'worktree' ? null : $agent->workingDirectory,
        agentStatus: $agent->agentStatus,
    );

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_identity_changed')
        ->and($phase->fresh()->failure_details)->toMatchArray([
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
        ]);
})->with(['workspace', 'tab', 'pane', 'terminal', 'agent', 'name', 'worktree']);

it('turns every incomplete retained identity into explicit blocked evidence at the deadline', function (string $field): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();
    $dispatch->forceFill([$field => $field === 'herdr_session' ? 'other-session' : null])->save();

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_identity_changed')
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
})->with([
    'herdr_session',
    'herdr_workspace_id',
    'herdr_tab_id',
    'herdr_pane_id',
    'herdr_terminal_id',
    'herdr_agent_id',
    'herdr_agent_name',
    'state_change_seq',
    'dispatched_at',
]);

it('applies the receipt grace boundary and lets an existing receipt win', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(
        dispatchStatus: AgentDispatchStatus::Settled,
        settledAt: now()->subMinutes(5)->toImmutable(),
    );

    app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
        null,
    );

    expect($phase->fresh()->failure_code)->toBe('pr_review_receipt_missing')
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked);

    [, $deliveryWithReceipt, $phaseWithReceipt, $dispatchWithReceipt] = orbitReviewWaitFixture(
        dispatchStatus: AgentDispatchStatus::Settled,
        settledAt: now()->subMinutes(5)->toImmutable(),
    );
    Receipt::query()->create([
        'phase_run_id' => $phaseWithReceipt->id,
        'kind' => 'orbit_pr_review',
        'schema_version' => 1,
        'payload' => ['kind' => 'orbit_pr_review'],
        'payload_hash' => hash('sha256', '{}'),
        'validation_status' => 'valid',
        'captured_at' => now(),
        'validated_at' => now(),
    ]);

    app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $deliveryWithReceipt->id,
        $phaseWithReceipt->id,
        $dispatchWithReceipt->id,
        null,
    );

    expect($deliveryWithReceipt->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phaseWithReceipt->fresh()->status)->toBe(PhaseRunStatus::Running);
});

it('does not apply the receipt timeout before the grace boundary', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(
        dispatchStatus: AgentDispatchStatus::Settled,
        settledAt: now()->subMinutes(5)->addSecond()->toImmutable(),
    );

    app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
        null,
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($phase->fresh()->failure_code)->toBeNull();
});

it('blocks a settled reviewer whose settlement timestamp is missing', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture(
        dispatchStatus: AgentDispatchStatus::Settled,
    );

    app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
        null,
    );

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_identity_changed');
});

it('does not mutate a nonmatching or disabled review ledger', function (string $mismatch): void {
    [$project, $delivery, $phase, $dispatch, $runtime] = orbitReviewWaitFixture();

    match ($mismatch) {
        'workflow' => $delivery->forceFill(['workflow_type' => 'other'])->save(),
        'version' => $delivery->forceFill(['workflow_version' => 999])->save(),
        'phase' => $delivery->forceFill(['current_phase' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE])->save(),
        'delivery-state' => $delivery->forceFill(['status' => DeliveryStatus::Preparing])->save(),
        'project-state' => $project->forceFill(['state' => ProjectOrchestrationState::Paused])->save(),
        'phase-state' => $phase->forceFill(['status' => PhaseRunStatus::Pending])->save(),
        'dispatch-state' => $dispatch->forceFill(['status' => AgentDispatchStatus::Ambiguous])->save(),
        'agent-role' => $dispatch->forceFill(['agent_role' => 'other-reviewer'])->save(),
    };

    app(ReconcileOrbitPullRequestReviewWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
        $runtime->agent,
    );

    expect($phase->fresh()->failure_code)->toBeNull()
        ->and($phase->fresh()->finished_at)->toBeNull()
        ->and($delivery->fresh()->status)->not->toBe(DeliveryStatus::Blocked);
})->with([
    'workflow',
    'version',
    'phase',
    'delivery-state',
    'project-state',
    'phase-state',
    'dispatch-state',
    'agent-role',
]);

it('never lets a stale job affect a newer review attempt', function (): void {
    [, $delivery, $firstPhase, $firstDispatch] = orbitReviewWaitFixture();
    $firstPhase->forceFill([
        'status' => PhaseRunStatus::Failed,
        'failure_code' => 'old_failure',
        'finished_at' => now()->subMinute(),
    ])->save();
    $secondPhase = PhaseRun::query()->create([
        'delivery_id' => $delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Running,
        'started_at' => now()->subMinute(),
    ]);
    $secondDispatch = $firstDispatch->replicate([
        'idempotency_key',
        'status',
        'dispatched_at',
        'settled_at',
    ]);
    $secondDispatch->phase_run_id = $secondPhase->id;
    $secondDispatch->idempotency_key = 'new-review-dispatch';
    $secondDispatch->status = AgentDispatchStatus::Waiting;
    $secondDispatch->dispatched_at = now();
    $secondDispatch->save();

    runReviewWaitJob($delivery, $firstPhase, $firstDispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($secondPhase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($secondDispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('preserves the first timeout timestamp and evidence on replay', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();

    runReviewWaitJob($delivery, $phase, $dispatch);
    $finishedAt = $phase->fresh()->finished_at;
    $failure = $phase->fresh()->failure_details;
    Carbon::setTestNow(now()->addMinute());

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($phase->fresh()->finished_at?->equalTo($finishedAt))->toBeTrue()
        ->and($phase->fresh()->failure_details)->toBe($failure)
        ->and($delivery->fresh()->failure_details)->toBe($failure);
});

it('does not affect another delivery that is ready to merge', function (): void {
    [$project, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();
    $other = Delivery::query()->create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'unrelated-ready-to-merge',
        'external_issue_key' => 'ORB-251',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::ReadyToMerge,
        'current_phase' => OrbitFeatureWorkflow::LANDING_PHASE,
        'branch' => 'orb-251',
        'worktree_path' => '/fast/worktrees/orbit/orb-251',
        'candidate_sha' => str_repeat('c', 40),
    ]);

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($other->fresh()->status)->toBe(DeliveryStatus::ReadyToMerge)
        ->and($other->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::LANDING_PHASE)
        ->and($other->fresh()->failure_details)->toBeNull();
});

it('rejects a late Herdr settlement event after timeout failure', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();

    runReviewWaitJob($delivery, $phase, $dispatch);
    $event = app(CaptureHerdrEvent::class)->handle([
        'event' => 'pane.agent_status_changed',
        'data' => [
            'workspace_id' => 'workspace-review',
            'tab_id' => 'tab-review',
            'pane_id' => 'pane-review',
            'terminal_id' => 'terminal-review',
            'agent_id' => 'agent-review',
            'agent_name' => 'orb-250-loop-pr-reviewer',
            'agent_status' => 'done',
            'state_change_seq' => 11,
        ],
    ]);

    expect($event?->failure_message)->toBe('unmatched_dispatch')
        ->and($event?->agent_dispatch_id)->toBeNull()
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_wait_timeout');
});

it('rejects a late review receipt after timeout failure', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();
    $config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: '/home/nckrtl/orbit',
        worktreeRoot: '/fast/worktrees/orbit',
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect(fn () => app(CaptureOrbitPullRequestReviewReceipt::class)->handle(
        $phase,
        $dispatch,
        $config,
        [],
    ))->toThrow(
        OrbitPullRequestReviewReceiptFailed::class,
        'no longer matches the active dispatch',
    )->and(Receipt::query()->where('phase_run_id', $phase->id)->count())->toBe(0)
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('retries an overdue observation failure and blocks only the exact job on exhaustion', function (): void {
    [, $delivery, $phase, $dispatch, $runtime] = orbitReviewWaitFixture();
    $runtime->failure = new RuntimeException('Herdr read timed out.');
    $job = new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id);

    expect(fn () => $job->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    ))->toThrow(HerdrSettlementObservationFailed::class);

    $failure = new HerdrSettlementObservationFailed('Commander could not observe the retained Herdr reviewer.');
    $job->failed($failure);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->failure_code)->toBe('pr_review_observation_failed')
        ->and($phase->fresh()->failure_details)->toMatchArray([
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
        ]);
});

it('keeps a transient observation failure before the deadline unchanged', function (): void {
    [, $delivery, $phase, $dispatch, $runtime] = orbitReviewWaitFixture(
        dispatchedAt: now()->subMinutes(30)->toImmutable(),
    );
    $runtime->failure = new RuntimeException('Herdr read timed out.');

    runReviewWaitJob($delivery, $phase, $dispatch);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running);
});

it('queues exact review reconciliation identity and uses a bounded unique job', function (): void {
    [, $delivery, $phase, $dispatch] = orbitReviewWaitFixture();
    Queue::fake([AdvanceDelivery::class, ReconcileDelivery::class]);

    (new ReconcileDeliveries)->handle(app(RecoverExhaustedOrbitPlanningCorrection::class));

    Queue::assertPushed(ReconcileDelivery::class, fn (ReconcileDelivery $job): bool => $job->deliveryId === $delivery->id
        && $job->phaseRunId === $phase->id
        && $job->dispatchId === $dispatch->id
        && $job->uniqueId() === "commander-delivery-reconciliation:{$delivery->id}:{$phase->id}:{$dispatch->id}"
    );
    Queue::assertNotPushed(AdvanceDelivery::class);

    $job = new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->timeout)->toBe(45)
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->tries)->toBe(0)
        ->and($job->backoff)->toBe([1, 5, 15, 30])
        ->and($job->retryUntil() > now())->toBeTrue();
});
