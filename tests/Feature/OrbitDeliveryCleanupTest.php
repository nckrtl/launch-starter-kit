<?php

declare(strict_types=1);

use App\Delivery\Actions\AdvanceDeliveryAction;
use App\Delivery\Actions\AdvanceOrbitCleanup;
use App\Delivery\Actions\ShutdownOrbitHerdrWorkspace;
use App\Delivery\Actions\StartOrbitDeliveryCleanup;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Contracts\OrbitAbandonedWorktreeCleaner;
use App\Delivery\Data\CleanedOrbitAbandonedWorktree;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedOrbitAbandonedWorktreeCleanup;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitDeliveryCleanupFailed;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Jobs\AdvanceOrbitCleanup as AdvanceOrbitCleanupJob;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function retainWaitingReviewCleanupFailure(
    Delivery $delivery,
    PhaseRun $source,
    AgentDispatch $dispatch,
    Receipt $receipt,
    string $code,
): array {
    DB::table('receipts')->where('id', $receipt->id)->delete();
    $dispatch->forceFill([
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'prompt_name' => 'orbit_pr_review',
        'status' => AgentDispatchStatus::Waiting,
        'settled_at' => null,
    ])->save();
    $details = [
        'code' => $code,
        'phase_run_id' => $source->id,
        'dispatch_id' => $dispatch->id,
        'dispatch_status' => AgentDispatchStatus::Waiting->value,
    ];
    $source->forceFill([
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'failure_code' => $code,
        'failure_message' => 'The retained reviewer did not settle safely.',
        'failure_details' => $details,
    ])->save();
    $delivery->forceFill([
        'current_phase' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'failure_details' => $details,
    ])->save();

    return $details;
}

final class AbsentCleanupHerdrRuntime implements HerdrWorkspaceRuntime
{
    public int $snapshots = 0;

    public function __construct(private ?HerdrSessionSnapshot $retainedSnapshot = null) {}

    public function snapshot(): HerdrSessionSnapshot
    {
        $this->snapshots++;

        return $this->retainedSnapshot ?? new HerdrSessionSnapshot('0.9.0', 22, [], [], []);
    }

    public function readAgent(string $name): HerdrAgentOutput
    {
        throw new LogicException('No absent cleanup agent may be read.');
    }

    public function sendAgentKeys(string $name, array $keys): void
    {
        throw new LogicException('No absent cleanup agent may receive input.');
    }

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo
    {
        throw new LogicException('No absent cleanup pane may be inspected.');
    }

    public function closeWorkspace(string $workspaceId, int $protocol): void
    {
        throw new LogicException('An absent cleanup workspace must not be closed.');
    }
}

final class AbsentOrbitWorktreeCleaner implements OrbitAbandonedWorktreeCleaner
{
    public int $preparations = 0;

    public int $cleanups = 0;

    public function prepareAbandonedWorktreeCleanup(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
    ): PreparedOrbitAbandonedWorktreeCleanup {
        $this->preparations++;

        return new PreparedOrbitAbandonedWorktreeCleanup(
            repository: $config->repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            cleanupAttemptId: $cleanupAttemptId,
            disposition: 'already_absent',
            protectedWorktrees: [],
            protectedBranches: [],
            authorizedAt: '2026-09-12T01:00:00Z',
        );
    }

    public function cleanupAbandonedWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
        bool $resume,
    ): CleanedOrbitAbandonedWorktree {
        $this->cleanups++;
        expect($resume)->toBeFalse()
            ->and($authorization->disposition)->toBe('already_absent');

        return new CleanedOrbitAbandonedWorktree(
            repository: $config->repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            cleanupAttemptId: $cleanupAttemptId,
            disposition: 'already_absent',
            recordedAt: '2026-09-12T01:00:01Z',
        );
    }
}

beforeEach(function () {
    config()->set('herdr.session', 'orbit');
    Queue::fake();
    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: '/home/nckrtl/orbit',
        worktreeRoot: '/fast/worktrees/orbit',
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
    $this->project = ProjectOrchestration::query()->create([
        'manifest_project_id' => 'orbit',
        'config' => $this->config->toArray(),
    ]);
    $this->delivery = Delivery::query()->create([
        'project_orchestration_id' => $this->project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => '11111111-2222-4333-8444-555555555555',
        'external_issue_key' => 'ORB-240',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Blocked,
        'current_phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'branch' => 'orb-240',
        'worktree_path' => '/fast/worktrees/orbit/orb-240',
        'candidate_sha' => str_repeat('a', 40),
    ]);
    $this->source = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'failure_code' => 'planning_blocked',
        'failure_message' => 'The requested outcome already landed through ORB-239.',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $this->dispatch = AgentDispatch::query()->create([
        'phase_run_id' => $this->source->id,
        'agent_role' => OrbitFeatureWorkflow::PLANNING_AGENT_ROLE,
        'idempotency_key' => 'cleanup-source-dispatch',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'w2H',
        'herdr_tab_id' => 'w2H:t1',
        'herdr_pane_id' => 'w2H:p2',
        'herdr_terminal_id' => 'term_cleanup_source',
        'herdr_agent_id' => 'codex',
        'herdr_agent_name' => 'orb-240-loop-builder',
        'prompt_name' => 'orbit_planning',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now()->subMinute(),
        'settled_at' => now(),
    ]);
    $this->receipt = Receipt::query()->create([
        'phase_run_id' => $this->source->id,
        'kind' => 'orbit_planning',
        'schema_version' => 1,
        'payload' => ['result' => 'blocked', 'handoff' => 'Already shipped.'],
        'payload_hash' => hash('sha256', 'cleanup-source-receipt'),
        'candidate_sha' => null,
        'validation_status' => ReceiptValidationStatus::Valid,
        'captured_at' => now(),
        'validated_at' => now(),
    ]);
    $this->delivery->failure_details = [
        'code' => 'planning_blocked',
        'phase_run_id' => $this->source->id,
        'dispatch_id' => $this->dispatch->id,
        'receipt_id' => $this->receipt->id,
        'handoff' => 'Already shipped.',
    ];
    $this->delivery->save();
});

it('starts one idempotent cleanup phase without altering failed source evidence', function () {
    $sourceBefore = DB::table('phase_runs')->where('id', $this->source->id)->first();
    $dispatchBefore = DB::table('agent_dispatches')->where('id', $this->dispatch->id)->first();
    $receiptBefore = DB::table('receipts')->where('id', $this->receipt->id)->first();
    $failureBefore = DB::table('deliveries')->where('id', $this->delivery->id)->value('failure_details');

    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $again = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $delivery = $this->delivery->fresh();

    expect($again->id)->toBe($phase->id)
        ->and($delivery->status)->toBe(DeliveryStatus::Cleaning)
        ->and($delivery->current_phase)->toBe(OrbitFeatureWorkflow::CLEANUP_PHASE)
        ->and($phase->status)->toBe(PhaseRunStatus::Pending)
        ->and($phase->input['source']['delivery_failure_details'])->toBe($this->delivery->failure_details)
        ->and(PhaseRun::query()->where('delivery_id', $delivery->id)->count())->toBe(2)
        ->and(DB::table('deliveries')->where('id', $delivery->id)->value('failure_details'))->toBe($failureBefore)
        ->and(DB::table('phase_runs')->where('id', $this->source->id)->first())->toEqual($sourceBefore)
        ->and(DB::table('agent_dispatches')->where('id', $this->dispatch->id)->first())->toEqual($dispatchBefore)
        ->and(DB::table('receipts')->where('id', $this->receipt->id)->first())->toEqual($receiptBefore);

    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('reuses the cleanup phase while its worker is running', function () {
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $phase->status = PhaseRunStatus::Running;
    $phase->current_block = 'workspace_shutdown';
    $phase->output = ['repository' => $this->config->repository];
    $phase->started_at = now();
    $phase->save();

    $again = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);

    expect($again->id)->toBe($phase->id)
        ->and($again->status)->toBe(PhaseRunStatus::Running)
        ->and(PhaseRun::query()->where('delivery_id', $this->delivery->id)->count())->toBe(2);

    Queue::assertPushed(AdvanceDelivery::class, 2);
});

it('routes cleanup through the dedicated long-running worker', function () {
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    Queue::fake();

    expect(app(AdvanceDeliveryAction::class)->handle($this->delivery->id))->toBeFalse();

    Queue::assertPushed(
        AdvanceOrbitCleanupJob::class,
        fn (AdvanceOrbitCleanupJob $job): bool => $job->deliveryId === $this->delivery->id
            && $job->phaseRunId === $phase->id,
    );
});

it('records already-absent cleanup honestly and terminalizes the blocked delivery', function () {
    $sourceBefore = DB::table('phase_runs')->where('id', $this->source->id)->first();
    $dispatchBefore = DB::table('agent_dispatches')->where('id', $this->dispatch->id)->first();
    $receiptBefore = DB::table('receipts')->where('id', $this->receipt->id)->first();
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $herdr = new AbsentCleanupHerdrRuntime;
    $worktrees = new AbsentOrbitWorktreeCleaner;
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace($herdr),
        $worktrees,
    );

    expect($advance->handle($this->delivery->id, $phase->id))->toBeNull();

    $delivery = $this->delivery->fresh();
    $phase = $phase->fresh();
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->active_issue_key)->toBeNull()
        ->and($delivery->failed_at)->not->toBeNull()
        ->and($delivery->failure_details['code'])->toBe('delivery_aborted')
        ->and($delivery->failure_details['original']['delivery_failure_details']['code'])
        ->toBe('planning_blocked')
        ->and($delivery->failure_details['cleanup'])->toBe([
            'workspace' => 'already_absent',
            'worktree' => 'already_absent',
        ])
        ->and($phase->status)->toBe(PhaseRunStatus::Completed)
        ->and($phase->current_block)->toBeNull()
        ->and($phase->output['workspace_shutdown']['closed']['disposition'])->toBe('already_absent')
        ->and($phase->output['worktree_cleanup']['disposition'])->toBe('already_absent')
        ->and($herdr->snapshots)->toBe(1)
        ->and($worktrees->preparations)->toBe(1)
        ->and($worktrees->cleanups)->toBe(1)
        ->and(DB::table('phase_runs')->where('id', $this->source->id)->first())->toEqual($sourceBefore)
        ->and(DB::table('agent_dispatches')->where('id', $this->dispatch->id)->first())->toEqual($dispatchBefore)
        ->and(DB::table('receipts')->where('id', $this->receipt->id)->first())->toEqual($receiptBefore);

    expect($advance->handle($this->delivery->id, $phase->id))->toBeNull()
        ->and($worktrees->cleanups)->toBe(1);
});

it('cleans only the exact retained waiting reviewer after an allowed review failure', function (string $code) {
    $original = retainWaitingReviewCleanupFailure(
        $this->delivery,
        $this->source,
        $this->dispatch,
        $this->receipt,
        $code,
    );
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace(new AbsentCleanupHerdrRuntime),
        new AbsentOrbitWorktreeCleaner,
    );

    expect($advance->handle($this->delivery->id, $phase->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failure_details['original']['delivery_failure_details'])
        ->toBe($original)
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->source->fresh()->status)->toBe(PhaseRunStatus::Failed)
        ->and($this->source->fresh()->failure_details)->toBe($original);
})->with(['pr_review_wait_timeout', 'pr_review_identity_changed']);

it('keeps the exact timed-out reviewer workspace while its real agent is active', function () {
    retainWaitingReviewCleanupFailure(
        $this->delivery,
        $this->source,
        $this->dispatch,
        $this->receipt,
        'pr_review_wait_timeout',
    );
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $snapshot = new HerdrSessionSnapshot(
        '0.9.0',
        22,
        [new HerdrSnapshotWorkspace(
            'w2H',
            $this->config->repository,
            $this->delivery->worktree_path,
            true,
        )],
        [new HerdrSnapshotPane(
            'w2H',
            'w2H:t1',
            'w2H:p2',
            'term_cleanup_source',
            $this->delivery->worktree_path,
        )],
        [new HerdrSnapshotAgent(
            'w2H',
            'w2H:t1',
            'w2H:p2',
            'term_cleanup_source',
            'codex',
            'orb-240-loop-builder',
            'working',
            $this->delivery->worktree_path,
        )],
    );
    $worktrees = new AbsentOrbitWorktreeCleaner;
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace(new AbsentCleanupHerdrRuntime($snapshot)),
        $worktrees,
    );

    expect(fn () => $advance->handle($this->delivery->id, $phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'unknown, active, or displaced')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($worktrees->preparations)->toBe(0)
        ->and($worktrees->cleanups)->toBe(0);
});

it('rejects an unrelated waiting dispatch during cleanup', function () {
    retainWaitingReviewCleanupFailure(
        $this->delivery,
        $this->source,
        $this->dispatch,
        $this->receipt,
        'other_review_failure',
    );
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace(new AbsentCleanupHerdrRuntime),
        new AbsentOrbitWorktreeCleaner,
    );

    expect(fn () => $advance->handle($this->delivery->id, $phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'incomplete Herdr dispatch identity')
        ->and($this->dispatch->fresh()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('refuses already-absent reconciliation while a retained Herdr identity remains', function (string $identity) {
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $agents = match ($identity) {
        'agent name' => [new HerdrSnapshotAgent(
            'other-workspace',
            'other-tab',
            'other-pane',
            'other-terminal',
            'codex',
            'orb-240-loop-builder',
            'done',
            '/tmp',
        )],
        'agent terminal' => [new HerdrSnapshotAgent(
            'other-workspace',
            'other-tab',
            'other-pane',
            'term_cleanup_source',
            'codex',
            'other-agent',
            'done',
            '/tmp',
        )],
        'agent pane' => [new HerdrSnapshotAgent(
            'other-workspace',
            'other-tab',
            'w2H:p2',
            'other-terminal',
            'codex',
            'other-agent',
            'done',
            '/tmp',
        )],
        default => [],
    };
    $panes = in_array($identity, ['pane', 'pane terminal'], true)
        ? [new HerdrSnapshotPane(
            'other-workspace',
            'other-tab',
            $identity === 'pane' ? 'w2H:p2' : 'other-pane',
            $identity === 'pane terminal' ? 'term_cleanup_source' : 'other-terminal',
            '/tmp',
        )]
        : [];
    $herdr = new AbsentCleanupHerdrRuntime(
        new HerdrSessionSnapshot('0.9.0', 22, [], $panes, $agents),
    );
    $worktrees = new AbsentOrbitWorktreeCleaner;
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace($herdr),
        $worktrees,
    );

    expect(fn () => $advance->handle($this->delivery->id, $phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'identity is still present')
        ->and($worktrees->preparations)->toBe(0)
        ->and($worktrees->cleanups)->toBe(0);
})->with(['agent name', 'agent terminal', 'agent pane', 'pane', 'pane terminal']);

it('terminalizes from retained cleanup evidence without replaying the adapter', function () {
    $phase = app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id);
    $attemptId = str_repeat('d', 32);
    $authorization = new PreparedOrbitAbandonedWorktreeCleanup(
        repository: $this->config->repository,
        worktree: $this->delivery->worktree_path,
        issueKey: $this->delivery->external_issue_key,
        branch: $this->delivery->branch,
        candidateSha: $this->delivery->candidate_sha,
        cleanupAttemptId: $attemptId,
        disposition: 'already_absent',
        protectedWorktrees: [],
        protectedBranches: [],
        authorizedAt: '2026-09-12T01:00:00Z',
    );
    $cleaned = new CleanedOrbitAbandonedWorktree(
        repository: $this->config->repository,
        worktree: $this->delivery->worktree_path,
        issueKey: $this->delivery->external_issue_key,
        branch: $this->delivery->branch,
        candidateSha: $this->delivery->candidate_sha,
        cleanupAttemptId: $attemptId,
        disposition: 'already_absent',
        recordedAt: '2026-09-12T01:00:01Z',
    );
    $phase->status = PhaseRunStatus::Running;
    $phase->current_block = 'worktree_cleanup';
    $phase->started_at = now();
    $phase->output = [
        'repository' => $this->config->repository,
        'workspace_shutdown' => [
            'closed' => [
                'workspace_id' => 'w2H',
                'worktree_path' => $this->delivery->worktree_path,
                'disposition' => 'already_absent',
                'verified_at' => '2026-09-12T01:00:00Z',
            ],
        ],
        'worktree_cleanup_intent' => [
            'schema' => 1,
            'attempt_id' => $attemptId,
            'repository' => $this->config->repository,
            'issue_key' => $this->delivery->external_issue_key,
            'worktree' => $this->delivery->worktree_path,
            'branch' => $this->delivery->branch,
            'candidate_sha' => $this->delivery->candidate_sha,
            'started_at' => '2026-09-12T01:00:00Z',
        ],
        'worktree_cleanup_authorization' => $authorization->toArray(),
        'worktree_cleanup' => $cleaned->toArray(),
    ];
    $phase->save();
    $worktrees = new AbsentOrbitWorktreeCleaner;
    $advance = new AdvanceOrbitCleanup(
        app(ProjectConfigRegistry::class),
        new ShutdownOrbitHerdrWorkspace(new AbsentCleanupHerdrRuntime),
        $worktrees,
    );

    expect($advance->handle($this->delivery->id, $phase->id))->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($phase->fresh()->status)->toBe(PhaseRunStatus::Completed)
        ->and($worktrees->preparations)->toBe(0)
        ->and($worktrees->cleanups)->toBe(0);
});

it('rejects terminal, landing, and incomplete blocked ledgers', function (string $case) {
    if ($case === 'terminal') {
        $this->delivery->status = DeliveryStatus::Failed;
        $this->delivery->failed_at = now();
    } elseif ($case === 'landing') {
        $this->delivery->current_phase = OrbitFeatureWorkflow::LANDING_PHASE;
    } else {
        $this->delivery->failure_details = ['code' => 'planning_blocked'];
    }
    $this->delivery->save();

    expect(fn () => app(StartOrbitDeliveryCleanup::class)->handle($this->delivery->id))
        ->toThrow(OrbitDeliveryCleanupFailed::class)
        ->and(PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::CLEANUP_PHASE)->count())
        ->toBe(0);
})->with(['terminal', 'landing', 'missing source']);

it('exposes a confirmed abort command and bounded cleanup recovery job', function () {
    $this->artisan('delivery:abort-orbit', [
        'delivery' => $this->delivery->id,
        '--force' => true,
    ])->expectsOutputToContain('cleanup queued as phase')->assertSuccessful();

    $phase = PhaseRun::query()->where('phase_name', OrbitFeatureWorkflow::CLEANUP_PHASE)->sole();
    $job = new AdvanceOrbitCleanupJob($this->delivery->id, $phase->id);

    expect($job->timeout)->toBe(AdvanceOrbitCleanupJob::TIMEOUT_SECONDS)
        ->and(AdvanceOrbitCleanupJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->tries)->toBe(0)
        ->and($job->backoff)->toBe([1, 5, 15, 30])
        ->and($job->retryUntil() > now())->toBeTrue();

    $phase->status = PhaseRunStatus::Running;
    $phase->current_block = 'workspace_shutdown';
    $phase->output = ['repository' => $this->config->repository];
    $phase->started_at = now();
    $phase->save();
    $job->failed(new RuntimeException('Herdr unavailable.'));

    expect($this->delivery->fresh()->failure_details)->toBe([
        'code' => 'cleanup_workspace_shutdown_required',
        'phase_run_id' => $phase->id,
        'message' => 'Herdr unavailable.',
    ]);
});
