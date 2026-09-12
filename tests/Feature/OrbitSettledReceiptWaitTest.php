<?php

declare(strict_types=1);

use App\Delivery\Actions\ReconcileOrbitSettledReceiptWait;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** @return array{Delivery, PhaseRun, AgentDispatch} */
function settledOrbitReceiptFixture(
    string $phaseName,
    string $agentRole,
    int $secondsSinceSettlement = 300,
): array {
    $project = ProjectOrchestration::query()->create([
        'manifest_project_id' => 'orbit-settled-receipt-'.bin2hex(random_bytes(4)),
        'config' => [
            'type' => 'orbit',
            'repository' => '/home/nckrtl/orbit',
            'worktreeRoot' => '/fast/worktrees/orbit',
            'herdrSession' => 'orbit',
            'concurrency' => 1,
            'defaultFlow' => 'discovery',
        ],
        'state' => ProjectOrchestrationState::Enabled,
    ]);
    $delivery = Delivery::query()->create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'settled-receipt-'.bin2hex(random_bytes(8)),
        'external_issue_key' => 'ORB-260',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::WaitingForAgent,
        'current_phase' => $phaseName,
        'branch' => 'orb-260',
        'worktree_path' => '/fast/worktrees/orbit/orb-260',
        'candidate_sha' => str_repeat('a', 40),
    ]);
    $phase = PhaseRun::query()->create([
        'delivery_id' => $delivery->id,
        'phase_name' => $phaseName,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'started_at' => now()->subHour(),
    ]);
    $dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $phase->id,
        'agent_role' => $agentRole,
        'idempotency_key' => 'settled-receipt-'.bin2hex(random_bytes(8)),
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'workspace-receipt',
        'herdr_tab_id' => 'tab-receipt',
        'herdr_pane_id' => 'pane-receipt',
        'herdr_terminal_id' => 'terminal-receipt',
        'herdr_agent_id' => 'agent-receipt',
        'herdr_agent_name' => 'orb-260-loop-agent',
        'prompt_name' => 'orbit-phase',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => AgentDispatchStatus::Settled,
        'state_change_seq' => 42,
        'dispatched_at' => now()->subHour(),
        'settled_at' => now()->subSeconds($secondsSinceSettlement),
    ]);

    return [$delivery, $phase, $dispatch];
}

beforeEach(fn () => Carbon::setTestNow('2026-09-12 12:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('blocks an exact settled Orbit phase with no receipt at the grace boundary', function (
    string $phaseName,
    string $agentRole,
    string $receiptKind,
): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture($phaseName, $agentRole);

    expect(app(ReconcileOrbitSettledReceiptWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
    ))->toBeTrue();

    $failure = $phase->fresh()->failure_details;

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($delivery->fresh()->failure_details)->toBe($failure)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($phase->fresh()->failure_code)->toBe('orbit_receipt_missing')
        ->and($failure)->toMatchArray([
            'code' => 'orbit_receipt_missing',
            'phase_run_id' => $phase->id,
            'dispatch_id' => $dispatch->id,
            'phase_name' => $phaseName,
            'phase_attempt' => 1,
            'agent_role' => $agentRole,
            'expected_receipt_kind' => $receiptKind,
            'dispatch_status' => AgentDispatchStatus::Settled->value,
            'state_change_seq' => 42,
        ])
        ->and($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and(PhaseRun::query()->where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(AgentDispatch::query()->where('phase_run_id', $phase->id)->count())->toBe(1);
})->with([
    'planning' => [OrbitFeatureWorkflow::INITIAL_PHASE, OrbitFeatureWorkflow::PLANNING_AGENT_ROLE, 'orbit_planning'],
    'plan review' => [OrbitFeatureWorkflow::PLAN_REVIEW_PHASE, OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE, 'orbit_plan_review'],
    'implementation' => [OrbitFeatureWorkflow::IMPLEMENTATION_PHASE, OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE, 'orbit_implementation'],
    'resolution' => [OrbitFeatureWorkflow::RESOLUTION_PHASE, OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE, 'orbit_resolution'],
]);

it('keeps a settled Orbit phase unchanged before the receipt grace boundary', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        299,
    );

    expect(app(ReconcileOrbitSettledReceiptWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
    ))->toBeFalse()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running)
        ->and($phase->fresh()->failure_code)->toBeNull();
});

it('lets an existing phase receipt win over the missing-receipt timeout', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::RESOLUTION_PHASE,
        OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
    );
    $payload = ['kind' => 'orbit_resolution'];
    Receipt::query()->create([
        'phase_run_id' => $phase->id,
        'kind' => 'orbit_resolution',
        'schema_version' => 1,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);

    expect(app(ReconcileOrbitSettledReceiptWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
    ))->toBeFalse()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running);
});

it('blocks unexpected retained receipt evidence for a settled phase', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::RESOLUTION_PHASE,
        OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE,
    );
    $payload = ['kind' => 'orbit_planning'];
    Receipt::query()->create([
        'phase_run_id' => $phase->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => $payload,
        'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);

    expect(app(ReconcileOrbitSettledReceiptWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
    ))->toBeTrue()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($phase->fresh()->failure_code)->toBe('orbit_receipt_settlement_inconsistent');
});

it('ignores pull request review because its established wait policy owns that phase', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
    );

    expect(app(ReconcileOrbitSettledReceiptWait::class)->handle(
        $delivery->id,
        $phase->id,
        $dispatch->id,
    ))->toBeFalse()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Running);
});

it('rejects late recovery when the retained timeout evidence drifts', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::INITIAL_PHASE,
        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
    );
    $waits = app(ReconcileOrbitSettledReceiptWait::class);
    $waits->handle($delivery->id, $phase->id, $dispatch->id);
    $failure = $delivery->fresh()->failure_details;
    $failure['state_change_seq'] = 999;
    $delivery->fresh()->forceFill(['failure_details' => $failure])->save();
    $phase->fresh()->forceFill(['failure_details' => $failure])->save();

    expect($waits->canRecoverLateReceipt(
        $delivery->fresh(),
        $delivery->projectOrchestration()->sole(),
        $phase->fresh(),
        $dispatch->fresh(),
        1,
        0,
    ))->toBeFalse();
});

it('rejects late recovery after the project is paused', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
        OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
    );
    $waits = app(ReconcileOrbitSettledReceiptWait::class);
    $waits->handle($delivery->id, $phase->id, $dispatch->id);
    $project = $delivery->projectOrchestration()->sole();
    $project->forceFill(['state' => ProjectOrchestrationState::Paused])->save();

    expect($waits->canRecoverLateReceipt(
        $delivery->fresh(),
        $project->fresh(),
        $phase->fresh(),
        $dispatch->fresh(),
        1,
        0,
    ))->toBeFalse();
});

it('rejects late recovery for a stale attempt', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
    );
    $waits = app(ReconcileOrbitSettledReceiptWait::class);
    $waits->handle($delivery->id, $phase->id, $dispatch->id);
    PhaseRun::query()->create([
        'delivery_id' => $delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
    ]);

    expect($waits->canRecoverLateReceipt(
        $delivery->fresh(),
        $delivery->projectOrchestration()->sole(),
        $phase->fresh(),
        $dispatch->fresh(),
        1,
        0,
    ))->toBeFalse();
});

it('rejects late recovery when the settled dispatch role changes', function (): void {
    [$delivery, $phase, $dispatch] = settledOrbitReceiptFixture(
        OrbitFeatureWorkflow::INITIAL_PHASE,
        OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
    );
    $waits = app(ReconcileOrbitSettledReceiptWait::class);
    $waits->handle($delivery->id, $phase->id, $dispatch->id);
    $dispatch->forceFill([
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
    ])->save();

    expect($waits->canRecoverLateReceipt(
        $delivery->fresh(),
        $delivery->projectOrchestration()->sole(),
        $phase->fresh(),
        $dispatch->fresh(),
        1,
        0,
    ))->toBeFalse();
});
