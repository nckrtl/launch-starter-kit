<?php

use App\Delivery\Actions\CaptureHerdrEvent;
use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\DispatchOrbitPlanning;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\HerdrAgentIdentifiers;
use App\Delivery\Data\HerdrAgentLaunch;
use App\Delivery\Data\OpenedHerdrWorktree;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use App\Delivery\Exceptions\OrbitPlanningDispatchFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Jobs\DispatchOrbitPlanning as DispatchOrbitPlanningJob;
use App\Models\AgentDispatch;
use App\Models\ExternalEvent;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class PlanningDispatchLog
{
    /** @var list<string> */
    public array $events = [];
}

final class PlanningDispatchRepository implements OrbitRepository
{
    public mixed $reservationHandle = null;

    public int $verificationCount = 0;

    public ?int $failVerificationAt = null;

    public ?Closure $afterReserve = null;

    public function __construct(private readonly PlanningDispatchLog $log) {}

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        $this->log->events[] = 'repository.reserve';
        $handle = tmpfile();

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not create the test reservation.');
        }

        $this->reservationHandle = $handle;

        if ($this->afterReserve instanceof Closure) {
            ($this->afterReserve)();
        }

        return new OrbitDeliveryReservation($handle, 'planning-dispatch.lock');
    }

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree
    {
        throw new LogicException('Not used by this test.');
    }

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck
    {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningHandoff(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        CandidateCheck $candidate,
        PreparedIssueSnapshot $snapshot,
    ): VerifiedOrbitPlanningRepository {
        $this->verificationCount++;
        $this->log->events[] = 'repository.verify.'.$this->verificationCount;

        if ($this->failVerificationAt === $this->verificationCount) {
            throw new OrbitRepositoryFailed('Planning repository changed.');
        }

        return new VerifiedOrbitPlanningRepository(
            worktreePath: $worktree->path,
            branch: strtolower($snapshot->issueKey),
            candidateSha: $candidate->candidateSha,
            treeSha: $candidate->treeSha,
            flow: 'discovery',
            qualityReceiptPath: $candidate->receiptPath,
            snapshotPath: $snapshot->path,
            snapshotContentsHash: $snapshot->contentsHash,
        );
    }

    public function verifyPlanningArtifact(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        string $issueKey,
        string $artifactSha,
        string $expectedVerdict,
    ): VerifiedOrbitPlanningArtifact {
        throw new LogicException('Not used by this test.');
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        throw new LogicException('Not used by this test.');
    }

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot {
        throw new LogicException('Not used by this test.');
    }

    public function verifyIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $snapshot,
    ): void {
        throw new LogicException('Not used by this test.');
    }

    public function reservationIsHeld(): bool
    {
        return is_resource($this->reservationHandle);
    }
}

final class PlanningDispatchIssueProvider implements OrbitIssueProvider
{
    /** @param list<OrbitIssueSnapshot> $snapshots */
    public function __construct(
        private array $snapshots,
        private readonly PlanningDispatchLog $log,
    ) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->log->events[] = 'linear.fetch';

        return array_shift($this->snapshots)
            ?? throw new RuntimeException('No planning issue snapshot remains.');
    }
}

final class PlanningDispatchTransitioner implements OrbitIssueTransitioner
{
    public ?OrbitIssueTransitionFailed $failure = null;

    public function __construct(
        private readonly OrbitIssueSnapshot $result,
        private readonly PlanningDispatchLog $log,
    ) {}

    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        $this->log->events[] = 'linear.transition';

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}

final class PlanningDispatchHerdrRuntime implements HerdrRuntime
{
    public ?string $failure = null;

    public ?string $error = null;

    public ?Closure $beforePromptReturn = null;

    public ?HerdrAgentLaunch $launch = null;

    public ?HerdrAgentIdentifiers $observedAgent = null;

    public ?string $startedAgentId = 'codex-session-1';

    public ?string $promptedAgentId = 'codex-session-1';

    /** @var list<string> */
    public array $prompts = [];

    /** @var array{repository: string, worktree: string, label: string|null}|null */
    public ?array $opened = null;

    public ?string $startedName = null;

    public function __construct(
        private readonly PlanningDispatchLog $log,
        private readonly PlanningDispatchRepository $repository,
    ) {}

    public function openWorktree(
        string $repositoryPath,
        string $worktreePath,
        ?string $label = null,
    ): OpenedHerdrWorktree {
        $this->opened = ['repository' => $repositoryPath, 'worktree' => $worktreePath, 'label' => $label];
        $this->call('herdr.open');

        return new OpenedHerdrWorktree('workspace-1', 'tab-1', 'root-pane', 'root-terminal', false);
    }

    public function splitPane(string $paneId, string $workingDirectory): HerdrAgentIdentifiers
    {
        $this->call('herdr.split');

        return $this->identifiers('');
    }

    public function startAgent(
        string $paneId,
        string $name,
        ?HerdrAgentLaunch $launch = null,
    ): HerdrAgentIdentifiers {
        $this->launch = $launch;
        $this->startedName = $name;
        $this->call('herdr.start');

        return $this->identifiers($name, 40, $this->startedAgentId);
    }

    public function promptAgent(string $name, string $prompt): HerdrAgentIdentifiers
    {
        $this->prompts[] = $prompt;
        $this->call('herdr.prompt');

        if (! $this->repository->reservationIsHeld()) {
            throw new RuntimeException('Planning reservation was released before prompting.');
        }

        if ($this->beforePromptReturn instanceof Closure) {
            ($this->beforePromptReturn)();
        }

        return $this->identifiers($name, 42, $this->promptedAgentId);
    }

    public function getAgent(string $name): HerdrAgentIdentifiers
    {
        $this->call('herdr.get');

        return $this->observedAgent ?? $this->identifiers($name, 40, $this->startedAgentId);
    }

    private function call(string $event): void
    {
        $this->log->events[] = $event;

        if ($this->error === $event) {
            throw new Error("{$event} stopped the process.");
        }

        if ($this->failure === $event) {
            throw new RuntimeException("{$event} failed ambiguously.");
        }
    }

    private function identifiers(
        string $name,
        int $sequence = 40,
        ?string $agentId = 'codex-session-1',
    ): HerdrAgentIdentifiers {
        return new HerdrAgentIdentifiers(
            'workspace-1',
            'tab-1',
            'worker-pane',
            'worker-terminal',
            $agentId,
            $name,
            $sequence,
        );
    }
}

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-planning-dispatch-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->contractHash = str_repeat('d', 64);
    $this->todo = planningDispatchSnapshot('Todo', $this->contractHash);
    $this->inProgress = planningDispatchSnapshot('In Progress', $this->contractHash);
    $this->delivery = app(StartOrbitDelivery::class)->handle(
        $this->project,
        verifiedOrbitIssueSnapshot(
            $this->todo->issueId,
            $this->todo->issueKey,
            $this->worktree.'/.loop/issue.json',
        ),
        $this->worktree,
        new CandidateCheck(
            '/home/nckrtl/orbit/.git/orbit-checks/'.str_repeat('a', 40).'/startup/result.json',
            str_repeat('a', 40),
            str_repeat('b', 40),
        ),
    );
    $this->log = new PlanningDispatchLog;
    $this->repository = new PlanningDispatchRepository($this->log);
    $this->issues = new PlanningDispatchIssueProvider([$this->todo, $this->inProgress], $this->log);
    $this->transitioner = new PlanningDispatchTransitioner($this->inProgress, $this->log);
    $this->herdr = new PlanningDispatchHerdrRuntime($this->log, $this->repository);
    app()->instance(OrbitRepository::class, $this->repository);
    app()->instance(OrbitIssueProvider::class, $this->issues);
    app()->instance(OrbitIssueTransitioner::class, $this->transitioner);
    app()->instance(HerdrRuntime::class, $this->herdr);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->base));

function planningDispatchSnapshot(string $state, string $contractHash): OrbitIssueSnapshot
{
    $inProgress = $state === 'In Progress';
    $payload = [
        'id' => '11111111-2222-4333-8444-555555555555',
        'identifier' => 'ORB-234',
        'title' => 'Dispatch planning',
        'description' => "## Outcome\n\nPlan it.",
        'state' => $inProgress
            ? ['id' => '44444444-5555-4666-8777-888888888888', 'name' => 'In Progress', 'type' => 'started']
            : ['id' => '55555555-6666-4777-8888-999999999999', 'name' => 'Todo', 'type' => 'unstarted'],
        'assignee' => null,
        'delegate' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
    ];

    return new OrbitIssueSnapshot($payload['id'], $payload['identifier'], $payload, $contractHash);
}

it('dispatches one verified planner while retaining the controller reservation through prompt submission', function () {
    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($this->log->events)->toBe([
        'repository.reserve',
        'repository.verify.1',
        'linear.fetch',
        'linear.transition',
        'herdr.open',
        'herdr.split',
        'herdr.start',
        'repository.verify.2',
        'linear.fetch',
        'herdr.prompt',
    ])->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_session)->toBe('orbit')
        ->and($dispatch->herdr_workspace_id)->toBe('workspace-1')
        ->and($dispatch->herdr_tab_id)->toBe('tab-1')
        ->and($dispatch->herdr_pane_id)->toBe('worker-pane')
        ->and($dispatch->herdr_terminal_id)->toBe('worker-terminal')
        ->and($dispatch->herdr_agent_id)->toBe('codex-session-1')
        ->and($dispatch->herdr_agent_name)->toBe('orb-234-loop-builder')
        ->and($dispatch->state_change_seq)->toBe(42)
        ->and($dispatch->prompt_name)->toBe('orbit_planning')
        ->and($dispatch->prompt_version)->toBe(OrbitFeatureWorkflow::PLANNING_PROMPT_VERSION)
        ->and($dispatch->prompt_hash)->toBe(hash('sha256', $this->herdr->prompts[0]))
        ->and($this->herdr->opened)->toBe([
            'repository' => '/home/nckrtl/orbit',
            'worktree' => $this->worktree,
            'label' => 'ORB-234',
        ])
        ->and($this->herdr->startedName)->toBe('orb-234-loop-builder')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and(PhaseRun::sole()->status)->toBe(PhaseRunStatus::Running)
        ->and($this->herdr->prompts[0])->toContain(
            'delivery:submit-orbit-receipt 1 1 --result=ready',
            $this->worktree.'/.agents/skills/planning-features/SKILL.md',
            'Review verdict: PENDING',
        )
        ->and($this->herdr->launch?->kind)->toBe('codex')
        ->and($this->herdr->launch?->timeoutMilliseconds)->toBe(120_000)
        ->and($this->herdr->launch?->arguments)->toContain(
            'gpt-5.6-sol',
            'model_reasoning_effort="high"',
            'features.multi_agent=true',
            '--dangerously-bypass-approvals-and-sandbox',
        );
    Queue::assertNothingPushed();

    app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($this->log->events)->toHaveCount(10)
        ->and(AgentDispatch::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('includes retained stale-worktree recovery evidence in the initial planning prompt', function () {
    $retired = new RetiredOrbitStaleWorktree(
        repository: '/home/nckrtl/orbit',
        worktree: '/home/nckrtl/orbit/.worktrees/orb-234-old-title',
        issueKey: 'ORB-234',
        branch: 'orb-234-old-title',
        headSha: str_repeat('c', 40),
        treeSha: str_repeat('d', 40),
        retainedRef: 'refs/orbit-delivery/retired-worktrees/orb-234/'.str_repeat('c', 40),
        archive: '/home/nckrtl/orbit/.git/orbit-delivery/v1/orb-234/retired-worktrees/'
            .str_repeat('c', 40).'/'.str_repeat('e', 64),
        archiveDigest: str_repeat('e', 64),
        disposition: 'retired',
        retiredAt: '2026-09-12T02:00:00Z',
    );
    $phase = PhaseRun::sole();
    $phase->forceFill([
        'input' => [
            ...$phase->input,
            'retired_stale_worktree' => $retired->toArray(),
        ],
    ])->save();

    app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($this->herdr->prompts[0])->toContain(
        $retired->retainedRef,
        $retired->archive,
        'historical recovery evidence only',
    );
});

it('retains an agent session identity first reported by the successful prompt', function () {
    $this->herdr->startedAgentId = null;
    $this->herdr->promptedAgentId = 'codex-session-after-prompt';

    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->herdr_agent_id)->toBe('codex-session-after-prompt')
        ->and($dispatch->state_change_seq)->toBe(42)
        ->and($this->herdr->prompts)->toHaveCount(1);
});

it('blocks a prompt that reports a different known agent session', function () {
    $this->herdr->startedAgentId = 'codex-session-before-prompt';
    $this->herdr->promptedAgentId = 'different-codex-session';

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'outside the recorded planning dispatch');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and(AgentDispatch::sole()->error_code)->toBe('herdr_prompt_identity_ambiguous');
});

it('exposes the verified live planning dispatch through its explicit command', function () {
    $this->artisan('delivery:dispatch-orbit-planning', ['delivery' => (string) $this->delivery->id])
        ->expectsOutput('Orbit planning dispatch 1 is waiting.')
        ->assertSuccessful();

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);
    Queue::assertNothingPushed();
});

it('runs the initial planning dispatch through a bounded queued job', function () {
    $job = new DispatchOrbitPlanningJob($this->delivery->id);

    $job->handle(app(DispatchOrbitPlanning::class));

    expect($job->tries)->toBe(0)
        ->and($job->timeout)->toBe(DispatchOrbitPlanningJob::TIMEOUT_SECONDS)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(DispatchOrbitPlanningJob::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job->retryUntil() > now())->toBeTrue()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Waiting);
});

it('preserves a blocked planning dispatch outcome in the queued job', function () {
    $this->transitioner->failure = new OrbitIssueTransitionFailed(
        'Linear read-back unavailable.',
        ambiguous: true,
    );

    (new DispatchOrbitPlanningJob($this->delivery->id))
        ->handle(app(DispatchOrbitPlanning::class));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toMatchArray([
            'code' => 'linear_transition_ambiguous',
        ]);
});

it('fails only an active initial planning dispatch after queue exhaustion', function () {
    $job = new DispatchOrbitPlanningJob($this->delivery->id);

    $job->failed(new RuntimeException('Queue exhausted.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($this->delivery->fresh()->failed_at)->not->toBeNull()
        ->and($this->delivery->fresh()->failure_details)->toBe([
            'code' => 'planning_dispatch_exhausted',
            'message' => 'Queue exhausted.',
        ]);
});

it('does not let an exhausted initial dispatch job overwrite a later planning attempt', function () {
    PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
        'attempt' => 2,
        'status' => PhaseRunStatus::Pending,
    ]);

    (new DispatchOrbitPlanningJob($this->delivery->id))
        ->failed(new RuntimeException('Stale queue failure.'));

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->delivery->fresh()->failed_at)->toBeNull()
        ->and($this->delivery->fresh()->failure_details)->toBeNull();
});

it('releases a contended initial planning dispatch lock for retry', function () {
    $lock = Cache::lock(
        "delivery:planning-dispatch:{$this->delivery->id}",
        DispatchOrbitPlanningJob::LOCK_SECONDS,
    );
    $lock->get();

    try {
        $job = (new DispatchOrbitPlanningJob($this->delivery->id))->withFakeQueueInteractions();
        $job->handle(app(DispatchOrbitPlanning::class));
        $job->assertReleased(1);
    } finally {
        $lock->release();
    }
});

it('reconciles an ambiguous deterministic agent start without starting a replacement', function () {
    $this->herdr->failure = 'herdr.start';

    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->log->events)->toContain('herdr.start', 'herdr.get', 'herdr.prompt')
        ->and(collect($this->log->events)->filter(fn (string $event): bool => $event === 'herdr.start'))->toHaveCount(1)
        ->and(AgentDispatch::count())->toBe(1);
});

it('does not repeat external mutation after a concurrent caller resolves the dispatch', function () {
    $this->repository->afterReserve = function (): void {
        AgentDispatch::sole()->forceFill(['status' => AgentDispatchStatus::Waiting])->save();
        $this->delivery->forceFill(['status' => DeliveryStatus::WaitingForAgent])->save();
    };

    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($this->log->events)->toBe(['repository.reserve'])
        ->and($this->repository->reservationIsHeld())->toBeFalse();
    Queue::assertNothingPushed();
});

it('blocks an interrupted persisted startup without replaying external work', function () {
    $this->herdr->error = 'herdr.open';

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(Error::class, 'herdr.open stopped the process');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Starting)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing);

    $this->herdr->error = null;

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'manual recovery is required');

    expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details)->toMatchArray([
            'code' => 'planning_dispatch_interrupted',
            'dispatch_id' => AgentDispatch::sole()->id,
            'stage' => 'planning_dispatch_starting',
        ])
        ->and($this->log->events)->toBe(['repository.reserve', 'repository.verify.1', 'linear.fetch', 'linear.transition', 'herdr.open', 'repository.reserve']);
    Queue::assertNothingPushed();
});

it('reconciles a working planner after prompt delivery was interrupted without replaying the prompt', function () {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);
    $dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'state_change_seq' => 40,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'planning_dispatch_interrupted',
            'dispatch_id' => $dispatch->id,
            'stage' => 'herdr_prompt_attempted',
        ],
    ])->save();
    $this->herdr->observedAgent = new HerdrAgentIdentifiers(
        workspaceId: (string) $dispatch->herdr_workspace_id,
        tabId: (string) $dispatch->herdr_tab_id,
        paneId: (string) $dispatch->herdr_pane_id,
        terminalId: (string) $dispatch->herdr_terminal_id,
        agentId: $dispatch->herdr_agent_id,
        agentName: (string) $dispatch->herdr_agent_name,
        stateChangeSeq: 43,
        workingDirectory: $this->worktree,
        agentStatus: 'working',
    );
    $this->herdr->prompts = [];
    $this->log->events = [];

    $reconciled = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($reconciled->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($reconciled->state_change_seq)->toBe(43)
        ->and($reconciled->error_code)->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->herdr->prompts)->toBe([])
        ->and($this->log->events)->toBe(['herdr.get'])
        ->and(ExternalEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('records and settles a terminal planner observation while recovering an interrupted prompt', function (string $status) {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);
    $dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'state_change_seq' => 40,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'planning_dispatch_interrupted',
            'dispatch_id' => $dispatch->id,
            'stage' => 'herdr_prompt_attempted',
        ],
    ])->save();
    $this->herdr->observedAgent = new HerdrAgentIdentifiers(
        workspaceId: (string) $dispatch->herdr_workspace_id,
        tabId: (string) $dispatch->herdr_tab_id,
        paneId: (string) $dispatch->herdr_pane_id,
        terminalId: (string) $dispatch->herdr_terminal_id,
        agentId: $dispatch->herdr_agent_id,
        agentName: (string) $dispatch->herdr_agent_name,
        stateChangeSeq: 43,
        workingDirectory: $this->worktree,
        agentStatus: $status,
    );
    $this->herdr->prompts = [];
    $this->log->events = [];

    $reconciled = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($reconciled->status)->toBe(AgentDispatchStatus::Settled)
        ->and($reconciled->state_change_seq)->toBe(43)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent)
        ->and($this->delivery->fresh()->failure_details)->toBeNull()
        ->and($this->herdr->prompts)->toBe([])
        ->and($this->log->events)->toBe(['herdr.get'])
        ->and(ExternalEvent::sole()->agent_dispatch_id)->toBe($dispatch->id)
        ->and(ExternalEvent::sole()->processed_at)->not->toBeNull();
    Queue::assertPushed(AdvanceDelivery::class, 1);
})->with(['idle', 'done']);

it('rejects an interrupted prompt observation that does not exactly match the retained planner', function () {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);
    $dispatch->forceFill([
        'status' => AgentDispatchStatus::Starting,
        'state_change_seq' => 40,
        'error_code' => 'herdr_prompt_attempted',
    ])->save();
    $this->delivery->forceFill([
        'status' => DeliveryStatus::Blocked,
        'failure_details' => [
            'code' => 'planning_dispatch_interrupted',
            'dispatch_id' => $dispatch->id,
            'stage' => 'herdr_prompt_attempted',
        ],
    ])->save();
    $this->herdr->observedAgent = new HerdrAgentIdentifiers(
        workspaceId: (string) $dispatch->herdr_workspace_id,
        tabId: (string) $dispatch->herdr_tab_id,
        paneId: (string) $dispatch->herdr_pane_id,
        terminalId: (string) $dispatch->herdr_terminal_id,
        agentId: $dispatch->herdr_agent_id,
        agentName: (string) $dispatch->herdr_agent_name,
        stateChangeSeq: 40,
        workingDirectory: '/fast/worktrees/orbit/another-issue',
        agentStatus: 'working',
    );
    $this->herdr->prompts = [];

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'not safe to reconcile');

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Starting)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->herdr->prompts)->toBe([]);
    Queue::assertNothingPushed();
});

it('rejects a project session that cannot correlate events from the active Herdr listener', function () {
    app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'another-session',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'active Herdr session');

    expect($this->log->events)->toBe([])
        ->and(AgentDispatch::count())->toBe(0);
});

it('blocks an ambiguous prompt and never submits it a second time', function () {
    $this->herdr->failure = 'herdr.prompt';

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'will not submit it again automatically');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and(AgentDispatch::sole()->error_code)->toBe('herdr_prompt_ambiguous')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->delivery->fresh()->failure_details['code'])->toBe('herdr_prompt_ambiguous')
        ->and($this->repository->reservationIsHeld())->toBeFalse()
        ->and($this->herdr->prompts)->toHaveCount(1);

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'not eligible');

    expect($this->herdr->prompts)->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('blocks an ambiguous Linear transition before creating Herdr state', function () {
    $this->transitioner->failure = new OrbitIssueTransitionFailed('Linear read-back unavailable.', ambiguous: true);

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'will not replay it automatically');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and(AgentDispatch::sole()->error_code)->toBe('linear_transition_ambiguous')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->log->events)->not->toContain('herdr.open')
        ->and($this->repository->reservationIsHeld())->toBeFalse();
    Queue::assertNothingPushed();
});

it('records a deterministic Linear rejection separately from an ambiguous transition', function () {
    $this->transitioner->failure = new OrbitIssueTransitionFailed('Linear rejected the state metadata.');

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'Linear rejected the planning transition');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Failed)
        ->and(AgentDispatch::sole()->error_code)->toBe('linear_transition_failed')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->log->events)->not->toContain('herdr.open');
    Queue::assertNothingPushed();
});

it('leaves a pre-Herdr verification failure safely retryable', function () {
    $this->repository->failVerificationAt = 1;

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitRepositoryFailed::class, 'Planning repository changed');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Pending)
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Preparing)
        ->and($this->log->events)->not->toContain('linear.transition', 'herdr.open')
        ->and($this->repository->reservationIsHeld())->toBeFalse();
    Queue::assertNothingPushed();
});

it('stops before prompting when final repository verification changes', function () {
    $this->repository->failVerificationAt = 2;

    expect(fn () => app(DispatchOrbitPlanning::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningDispatchFailed::class, 'Final planning verification failed');

    expect(AgentDispatch::sole()->status)->toBe(AgentDispatchStatus::Ambiguous)
        ->and(AgentDispatch::sole()->error_code)->toBe('planning_final_verification_failed')
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Blocked)
        ->and($this->log->events)->toContain('herdr.start', 'repository.verify.2')
        ->and($this->log->events)->not->toContain('herdr.prompt')
        ->and($this->repository->reservationIsHeld())->toBeFalse();
    Queue::assertNothingPushed();
});

it('settles live planning events and queues the unified advancement job', function () {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    app(CaptureHerdrEvent::class)->handle([
        'event' => 'pane.agent_status_changed',
        'data' => [
            'pane_id' => $dispatch->herdr_pane_id,
            'workspace_id' => $dispatch->herdr_workspace_id,
            'agent_status' => 'done',
            'state_change_seq' => 43,
        ],
    ]);

    expect($dispatch->fresh()->status)->toBe(AgentDispatchStatus::Settled)
        ->and(ExternalEvent::sole()->delivery_id)->toBe($this->delivery->id)
        ->and(ExternalEvent::sole()->processed_at)->not->toBeNull();
    Queue::assertPushed(AdvanceDelivery::class, 1);
});

it('does not settle an unsequenced startup event while the planning prompt is in flight', function () {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $event = null;
    $this->herdr->beforePromptReturn = function () use (&$event): void {
        $dispatch = AgentDispatch::sole();

        $event = app(CaptureHerdrEvent::class)->handle([
            'event' => 'pane.agent_status_changed',
            'data' => [
                'pane_id' => $dispatch->herdr_pane_id,
                'workspace_id' => $dispatch->herdr_workspace_id,
                'agent_status' => 'idle',
            ],
        ]);
    };

    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->settled_at)->toBeNull()
        ->and($event?->delivery_id)->toBeNull()
        ->and($event?->agent_dispatch_id)->toBeNull()
        ->and($event?->failure_message)->toBe('unmatched_dispatch')
        ->and($event?->processed_at)->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::WaitingForAgent);
    Queue::assertNothingPushed();
});

it('does not overwrite a plan-review transition committed before the prompt call returns', function () {
    config()->set('herdr.orchestration.enabled', true);
    config()->set('herdr.session', 'orbit');
    $this->herdr->beforePromptReturn = function (): void {
        $dispatch = AgentDispatch::sole();

        app(CaptureHerdrEvent::class)->handle([
            'event' => 'pane.agent_status_changed',
            'data' => [
                'pane_id' => $dispatch->herdr_pane_id,
                'workspace_id' => $dispatch->herdr_workspace_id,
                'agent_status' => 'done',
                'state_change_seq' => $dispatch->state_change_seq + 1,
            ],
        ]);

        $review = PhaseRun::query()->create([
            'delivery_id' => $this->delivery->id,
            'phase_name' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
            'attempt' => 1,
            'status' => PhaseRunStatus::Pending,
        ]);
        AgentDispatch::query()->create([
            'phase_run_id' => $review->id,
            'agent_role' => OrbitFeatureWorkflow::PLAN_REVIEW_AGENT_ROLE,
            'idempotency_key' => 'prompt-return-race-review',
            'herdr_agent_name' => 'orb-234-loop-plan-review',
            'prompt_name' => 'orbit_plan_review',
            'prompt_version' => 1,
            'prompt_hash' => str_repeat('0', 64),
            'status' => AgentDispatchStatus::Pending,
        ]);
        $this->delivery->forceFill([
            'current_phase' => OrbitFeatureWorkflow::PLAN_REVIEW_PHASE,
            'status' => DeliveryStatus::Queued,
        ])->save();
    };

    $dispatch = app(DispatchOrbitPlanning::class)->handle($this->delivery->id);

    expect($dispatch->status)->toBe(AgentDispatchStatus::Waiting)
        ->and($dispatch->error_code)->toBeNull()
        ->and($this->delivery->fresh()->status)->toBe(DeliveryStatus::Queued)
        ->and($this->delivery->fresh()->current_phase)->toBe(OrbitFeatureWorkflow::PLAN_REVIEW_PHASE)
        ->and(ExternalEvent::sole()->agent_dispatch_id)->toBeNull()
        ->and(ExternalEvent::sole()->failure_message)->toBe('unmatched_dispatch')
        ->and(ExternalEvent::sole()->processed_at)->toBeNull();
    Queue::assertNothingPushed();
});
