<?php

declare(strict_types=1);

use App\Delivery\Actions\ReconcileOrbitPullRequestReviewWait;
use App\Delivery\Actions\ReconcileWaitingHerdrSettlement;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\HerdrSettlementReconciliationFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdoptOrbitResolution;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitResolution;
use App\Jobs\ReconcileDeliveries;
use App\Jobs\ReconcileDelivery;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ReconciliationFakeHerdrRuntime implements HerdrRuntime
{
    public int $getCalls = 0;

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
        $this->getCalls++;

        return $this->agent;
    }
}

/** @return array{Delivery, PhaseRun, AgentDispatch} */
function waitingReconciliationDelivery(string $status = 'working', int $sequence = 11): array
{
    $project = ProjectOrchestration::create([
        'manifest_project_id' => 'waiting-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $delivery = Delivery::create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'test',
        'external_issue_id' => 'waiting-issue',
        'external_issue_key' => 'TEST-1',
        'workflow_type' => 'test',
        'workflow_version' => 1,
        'status' => DeliveryStatus::WaitingForAgent,
        'current_phase' => 'review',
        'worktree_path' => '/fast/worktrees/orbit/test-1',
    ]);
    $phase = PhaseRun::create([
        'delivery_id' => $delivery->id,
        'phase_name' => 'review',
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'started_at' => now(),
    ]);
    $dispatch = AgentDispatch::create([
        'phase_run_id' => $phase->id,
        'agent_role' => 'reviewer',
        'idempotency_key' => 'waiting-reviewer',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-1',
        'herdr_tab_id' => 'tab-1',
        'herdr_pane_id' => 'pane-1',
        'herdr_terminal_id' => 'terminal-1',
        'herdr_agent_id' => 'agent-1',
        'herdr_agent_name' => 'test-1-reviewer',
        'prompt_name' => 'review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('a', 64),
        'status' => AgentDispatchStatus::Waiting,
        'state_change_seq' => 10,
        'dispatched_at' => now(),
    ]);
    $herdr = new ReconciliationFakeHerdrRuntime(new HerdrAgentIdentifiers(
        workspaceId: 'workspace-1',
        tabId: 'tab-1',
        paneId: 'pane-1',
        terminalId: 'terminal-1',
        agentId: 'agent-1',
        agentName: 'test-1-reviewer',
        stateChangeSeq: $sequence,
        workingDirectory: '/fast/worktrees/orbit/test-1',
        agentStatus: $status,
    ));
    app()->instance(HerdrRuntime::class, $herdr);

    return [$delivery, $phase, $dispatch];
}

it('queues per-delivery recovery only for enabled recoverable deliveries', function (): void {
    Queue::fake([
        AdvanceDelivery::class,
        AdoptOrbitResolution::class,
        AdvanceOrbitResolution::class,
        ReconcileDelivery::class,
    ]);
    $enabled = ProjectOrchestration::create([
        'manifest_project_id' => 'enabled-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $paused = ProjectOrchestration::create([
        'manifest_project_id' => 'paused-project',
        'config' => [],
        'state' => ProjectOrchestrationState::Paused,
    ]);
    $sequence = 0;
    $delivery = static function (
        ProjectOrchestration $project,
        DeliveryStatus $status,
    ) use (&$sequence): Delivery {
        $sequence++;

        return Delivery::create([
            'project_orchestration_id' => $project->id,
            'external_issue_provider' => 'test',
            'external_issue_id' => "issue-{$sequence}",
            'external_issue_key' => "TEST-{$sequence}",
            'workflow_type' => 'test',
            'workflow_version' => 1,
            'status' => $status,
            'current_phase' => 'test',
        ]);
    };
    $recoverableStatuses = [
        DeliveryStatus::Queued,
        DeliveryStatus::Preparing,
        DeliveryStatus::WaitingForAgent,
        DeliveryStatus::ValidatingReceipt,
        DeliveryStatus::WaitingForChanges,
        DeliveryStatus::ReadyToMerge,
        DeliveryStatus::Merging,
        DeliveryStatus::Landed,
        DeliveryStatus::Cleaning,
    ];
    $recoverable = array_map(
        static fn (DeliveryStatus $status): Delivery => $delivery($enabled, $status),
        $recoverableStatuses,
    );
    $resolutionPublication = Delivery::create([
        'project_orchestration_id' => $enabled->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'resolution-publication',
        'external_issue_key' => 'ORB-314',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Blocked,
        'current_phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'failure_details' => [
            'code' => 'resolution_publication_reconciliation_required',
            'phase_run_id' => 314,
        ],
    ]);
    $resolutionAdoptionReady = Delivery::create([
        'project_orchestration_id' => $enabled->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'resolution-adoption-ready',
        'external_issue_key' => 'ORB-315',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Blocked,
        'current_phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'failure_details' => [
            'code' => 'resolution_adoption_ready',
            'phase_run_id' => 315,
        ],
    ]);
    $resolutionAdoptionRecovery = Delivery::create([
        'project_orchestration_id' => $enabled->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'resolution-adoption-recovery',
        'external_issue_key' => 'ORB-316',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Blocked,
        'current_phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'failure_details' => [
            'code' => 'resolution_adoption_reconciliation_required',
            'phase_run_id' => 316,
        ],
    ]);
    $resolutionDecision = Delivery::create([
        'project_orchestration_id' => $enabled->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'resolution-decision',
        'external_issue_key' => 'ORB-317',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Blocked,
        'current_phase' => OrbitFeatureWorkflow::RESOLUTION_PHASE,
        'failure_details' => [
            'code' => 'resolution_decision_required',
            'phase_run_id' => 317,
        ],
    ]);
    $excluded = [
        $delivery($enabled, DeliveryStatus::Paused),
        $delivery($enabled, DeliveryStatus::Blocked),
        $delivery($enabled, DeliveryStatus::Completed),
        $delivery($enabled, DeliveryStatus::Failed),
        $delivery($paused, DeliveryStatus::Queued),
        $delivery($paused, DeliveryStatus::Landed),
    ];

    $job = new ReconcileDeliveries;
    $job->handle();

    Queue::assertPushed(AdvanceDelivery::class, count($recoverable));
    Queue::assertPushed(
        AdvanceOrbitResolution::class,
        fn (AdvanceOrbitResolution $queued): bool => $queued->deliveryId === $resolutionPublication->id
            && $queued->phaseRunId === 314,
    );
    Queue::assertPushed(
        AdoptOrbitResolution::class,
        fn (AdoptOrbitResolution $queued): bool => $queued->deliveryId === $resolutionAdoptionReady->id
            && $queued->phaseRunId === 315,
    );
    Queue::assertPushed(
        AdoptOrbitResolution::class,
        fn (AdoptOrbitResolution $queued): bool => $queued->deliveryId === $resolutionAdoptionRecovery->id
            && $queued->phaseRunId === 316,
    );
    Queue::assertNotPushed(
        AdoptOrbitResolution::class,
        fn (AdoptOrbitResolution $queued): bool => $queued->deliveryId === $resolutionDecision->id,
    );
    Queue::assertNotPushed(ReconcileDelivery::class);

    foreach ($recoverable as $candidate) {
        Queue::assertPushed(
            AdvanceDelivery::class,
            fn (AdvanceDelivery $queued): bool => $queued->deliveryId === $candidate->id,
        );
    }

    foreach ($excluded as $candidate) {
        Queue::assertNotPushed(
            AdvanceDelivery::class,
            fn (AdvanceDelivery $queued): bool => $queued->deliveryId === $candidate->id,
        );
    }

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('commander-delivery-reconciliation')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('recovers one missed terminal Herdr event from the exact later agent state', function (): void {
    config()->set('herdr.session', 'orbit');
    config()->set('herdr.orchestration.enabled', true);
    [$delivery, , $dispatch] = waitingReconciliationDelivery('idle', 11);
    Queue::fake([AdvanceDelivery::class]);
    $phase = $dispatch->phaseRun;
    $job = new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id);

    $job->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    );
    $event = ExternalEvent::sole();

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and($dispatch->fresh()->state_change_seq)->toBe(11)
        ->and($event->provider)->toBe('herdr')
        ->and($event->provider_event_id)->toBe("reconciliation:{$dispatch->id}:11:idle")
        ->and($event->agent_dispatch_id)->toBe($dispatch->id)
        ->and($event->processed_at)->not->toBeNull();
    Queue::assertPushed(AdvanceDelivery::class, 1);

    $job->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    );

    expect(ExternalEvent::count())->toBe(1);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('recovers a missed terminal Herdr event without protocol agent ids', function (): void {
    config()->set('herdr.session', 'orbit');
    config()->set('herdr.orchestration.enabled', true);
    [$delivery, $phase, $dispatch] = waitingReconciliationDelivery('done', 11);
    $dispatch->update(['herdr_agent_id' => null]);
    $herdr = app(HerdrRuntime::class);

    if (! $herdr instanceof ReconciliationFakeHerdrRuntime) {
        throw new LogicException('The reconciliation fake is not bound.');
    }

    $agent = $herdr->agent;
    $herdr->agent = new HerdrAgentIdentifiers(
        workspaceId: $agent->workspaceId,
        tabId: $agent->tabId,
        paneId: $agent->paneId,
        terminalId: $agent->terminalId,
        agentId: null,
        agentName: $agent->agentName,
        stateChangeSeq: $agent->stateChangeSeq,
        workingDirectory: $agent->workingDirectory,
        agentStatus: $agent->agentStatus,
    );
    Queue::fake([AdvanceDelivery::class]);

    (new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id))->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    );

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and(ExternalEvent::sole()->agent_dispatch_id)->toBe($dispatch->id);
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('keeps a current or non-terminal Herdr agent waiting', function (string $status, int $sequence): void {
    config()->set('herdr.session', 'orbit');
    config()->set('herdr.orchestration.enabled', true);
    [$delivery, , $dispatch] = waitingReconciliationDelivery($status, $sequence);
    Queue::fake([AdvanceDelivery::class]);
    $phase = $dispatch->phaseRun;
    $job = new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id);

    $job->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    );

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and(ExternalEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'still working' => ['working', 11],
    'stale terminal observation' => ['done', 10],
]);

it('refuses a terminal observation outside the exact dispatch identity', function (string $identity): void {
    config()->set('herdr.session', 'orbit');
    config()->set('herdr.orchestration.enabled', true);
    [$delivery, , $dispatch] = waitingReconciliationDelivery('done', 11);
    $herdr = app(HerdrRuntime::class);

    if (! $herdr instanceof ReconciliationFakeHerdrRuntime) {
        throw new LogicException('The reconciliation fake is not bound.');
    }

    $agent = $herdr->agent;
    $herdr->agent = new HerdrAgentIdentifiers(
        workspaceId: $identity === 'workspace' ? 'other-workspace' : $agent->workspaceId,
        tabId: $identity === 'tab' ? 'other-tab' : $agent->tabId,
        paneId: $identity === 'pane' ? 'other-pane' : $agent->paneId,
        terminalId: $identity === 'terminal' ? 'other-terminal' : $agent->terminalId,
        agentId: $identity === 'agent' ? 'other-agent' : $agent->agentId,
        agentName: $identity === 'name' ? 'other-name' : $agent->agentName,
        stateChangeSeq: $agent->stateChangeSeq,
        workingDirectory: $identity === 'worktree' ? '/other/worktree' : $agent->workingDirectory,
        agentStatus: $agent->agentStatus,
    );
    Queue::fake([AdvanceDelivery::class]);

    $phase = $dispatch->phaseRun;

    expect(fn () => (new ReconcileDelivery($delivery->id, $phase->id, $dispatch->id))->handle(
        app(ReconcileWaitingHerdrSettlement::class),
        app(ReconcileOrbitPullRequestReviewWait::class),
    ))->toThrow(
        HerdrSettlementReconciliationFailed::class,
        'Herdr returned an agent outside the waiting dispatch identity.',
    )->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and(ExternalEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['workspace', 'tab', 'pane', 'terminal', 'agent', 'name', 'worktree']);

it('uses a bounded unique job for each delivery reconciliation', function (): void {
    $job = new ReconcileDelivery(42, 84, 126);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('commander-delivery-reconciliation:42:84:126')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil() > now())->toBeTrue();
});

it('registers the reconciliation job on the one-minute schedule', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);
    $schedule = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($schedule)->toHaveCount(2)
        ->and($schedule[0]['expression'])->toBe('* * * * *')
        ->and($schedule[0]['command'])->toBe('deliveries:reconcile')
        ->and($schedule[1]['expression'])->toBe('* * * * *')
        ->and($schedule[1]['command'])->toBe('deliveries:admit-orbit');
});
