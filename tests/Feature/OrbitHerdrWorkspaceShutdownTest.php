<?php

use App\Delivery\Actions\ShutdownOrbitHerdrWorkspace;
use App\Delivery\Contracts\HerdrWorkspaceRuntime;
use App\Delivery\Data\HerdrAgentOutput;
use App\Delivery\Data\HerdrForegroundProcess;
use App\Delivery\Data\HerdrPaneProcessInfo;
use App\Delivery\Data\HerdrSessionSnapshot;
use App\Delivery\Data\HerdrSnapshotAgent;
use App\Delivery\Data\HerdrSnapshotPane;
use App\Delivery\Data\HerdrSnapshotWorkspace;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Herdr\RequestFailed;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

final class ShutdownHerdrRuntime implements HerdrWorkspaceRuntime
{
    /** @var list<HerdrSessionSnapshot> */
    public array $snapshots = [];

    /** @var list<array{name: string, keys: list<string>}> */
    public array $sentKeys = [];

    /** @var list<string> */
    public array $closedWorkspaces = [];

    public int $snapshotIndex = 0;

    public int $processCalls = 0;

    public bool $busyPane = false;

    public bool $failClose = false;

    public string $agentOutput = '> /quit';

    public function snapshot(): HerdrSessionSnapshot
    {
        $snapshot = $this->snapshots[min($this->snapshotIndex, count($this->snapshots) - 1)] ?? null;
        $this->snapshotIndex++;

        return $snapshot ?? throw new LogicException('No Herdr snapshot was configured.');
    }

    public function readAgent(string $name): HerdrAgentOutput
    {
        return new HerdrAgentOutput('issue-workspace', 'issue-tab', 'issue-pane', $this->agentOutput);
    }

    public function sendAgentKeys(string $name, array $keys): void
    {
        $this->sentKeys[] = ['name' => $name, 'keys' => $keys];
    }

    public function inspectPaneProcess(string $paneId): HerdrPaneProcessInfo
    {
        $this->processCalls++;

        return $this->busyPane
            ? new HerdrPaneProcessInfo(
                $paneId,
                100,
                200,
                [new HerdrForegroundProcess(200, 'php')],
            )
            : new HerdrPaneProcessInfo(
                $paneId,
                100,
                100,
                [new HerdrForegroundProcess(100, 'zsh')],
            );
    }

    public function closeWorkspace(string $workspaceId, int $protocol): void
    {
        expect($protocol)->toBeGreaterThanOrEqual(20);
        $this->closedWorkspaces[] = $workspaceId;

        if ($this->failClose) {
            throw new RequestFailed('workspace.close timed out');
        }
    }
}

beforeEach(function () {
    Sleep::fake();
    config()->set('herdr.session', 'orbit');
    $this->repository = '/tmp/orbit';
    $this->worktree = '/fast/worktrees/orbit/orb-234';
    $this->config = new OrbitProjectConfig(
        type: 'orbit',
        repository: $this->repository,
        worktreeRoot: '/fast/worktrees/orbit',
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
    $project = ProjectOrchestration::query()->create([
        'manifest_project_id' => 'orbit',
        'config' => $this->config->toArray(),
    ]);
    $this->delivery = Delivery::query()->create([
        'project_orchestration_id' => $project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => '11111111-2222-4333-8444-555555555555',
        'external_issue_key' => 'ORB-234',
        'workflow_type' => OrbitFeatureWorkflow::TYPE,
        'workflow_version' => OrbitFeatureWorkflow::VERSION,
        'status' => DeliveryStatus::Landed,
        'current_phase' => OrbitFeatureWorkflow::LANDING_PHASE,
        'branch' => 'orb-234',
        'worktree_path' => $this->worktree,
        'candidate_sha' => str_repeat('a', 40),
    ]);
    $source = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::IMPLEMENTATION_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Completed,
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $source->id,
        'agent_role' => OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE,
        'idempotency_key' => 'workspace-shutdown-builder',
        'herdr_session' => 'orbit',
        'herdr_workspace_id' => 'issue-workspace',
        'herdr_tab_id' => 'issue-tab',
        'herdr_pane_id' => 'issue-pane',
        'herdr_terminal_id' => 'issue-terminal',
        'herdr_agent_id' => 'codex',
        'herdr_agent_name' => 'orb-234-loop-builder',
        'prompt_name' => 'orbit_implementation',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('b', 64),
        'status' => AgentDispatchStatus::Settled,
        'dispatched_at' => now(),
        'settled_at' => now(),
    ]);
    $this->phase = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::LANDING_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Running,
        'current_block' => 'workspace_shutdown',
        'output' => ['repository' => $this->repository],
        'started_at' => now(),
    ]);
    $this->herdr = new ShutdownHerdrRuntime;
    $this->action = new ShutdownOrbitHerdrWorkspace($this->herdr);
});

afterEach(fn () => Sleep::fake(false));

it('exits owned agents, verifies idle shells, and closes only the recorded workspace', function () {
    $withAgent = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $withoutAgent = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$withAgent, $withAgent, $withoutAgent, $withoutAgent, $closed];

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
            ],
            ['name' => 'orb-234-loop-builder', 'keys' => ['enter']],
        ])
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace'])
        ->and($this->herdr->processCalls)->toBe(1);

    $state = $this->phase->fresh()->output['workspace_shutdown'];
    expect($state['exit_attempted']['orb-234-loop-builder']['command'])->toBe('/quit')
        ->and($state['exit_attempted']['orb-234-loop-builder']['attempt_count'])->toBe(1)
        ->and($state['exit_submitted']['orb-234-loop-builder']['command'])->toBe('/quit')
        ->and($state['workspace_close_attempted_at'])->toBeString()
        ->and($state['closed'])->toMatchArray([
            'session' => 'orbit',
            'workspace_id' => 'issue-workspace',
            'worktree_path' => $this->worktree,
        ]);

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->sentKeys)->toHaveCount(2)
        ->and($this->herdr->closedWorkspaces)->toHaveCount(1);
});

it('confirms an exact exit command wrapped across narrow terminal lines', function () {
    $withAgent = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $withoutAgent = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$withAgent, $withAgent, $withoutAgent, $withoutAgent, $closed];
    $this->herdr->agentOutput = implode("\n", [
        '─ Work',
        '',
        '› /',
        '  qui',
        '  t',
        '',
        '  gpt…',
    ]);

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
            ],
            ['name' => 'orb-234-loop-builder', 'keys' => ['enter']],
        ])
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace']);
});

it('ignores a recovered pre-start mergeability failure without a runtime identity', function () {
    $failedReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'failure_code' => 'pr_review_mergeability_changed',
        'failure_message' => 'The published pull request became unmergeable before independent review.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $failedReview->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'workspace-shutdown-failed-review',
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('c', 64),
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'pr_review_mergeability_changed',
        'error_message' => 'The published pull request became unmergeable before independent review.',
    ]);
    $open = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$open, $open, $closed];

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace']);
});

it('rejects another failed dispatch without a runtime identity', function () {
    $failedReview = PhaseRun::query()->create([
        'delivery_id' => $this->delivery->id,
        'phase_name' => OrbitFeatureWorkflow::PR_REVIEW_PHASE,
        'attempt' => 1,
        'status' => PhaseRunStatus::Failed,
        'failure_code' => 'other_failure',
        'failure_message' => 'Another failure.',
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    AgentDispatch::query()->create([
        'phase_run_id' => $failedReview->id,
        'agent_role' => OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
        'idempotency_key' => 'workspace-shutdown-other-failure',
        'herdr_agent_name' => 'orb-234-loop-pr-review-1',
        'prompt_name' => 'orbit_pr_review',
        'prompt_version' => 1,
        'prompt_hash' => str_repeat('c', 64),
        'status' => AgentDispatchStatus::Failed,
        'error_code' => 'other_failure',
        'error_message' => 'Another failure.',
    ]);
    $this->herdr->snapshots = [shutdownSnapshot($this->repository, $this->worktree)];

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'incomplete Herdr dispatch identity');
});

it('retries a swallowed exit command twice and then waits without replaying it', function () {
    $snapshot = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $this->herdr->snapshots = [$snapshot];
    $this->herdr->agentOutput = 'Agent is idle. No command is waiting.';

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeFalse()
        ->and($this->herdr->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
        ])
        ->and($this->herdr->closedWorkspaces)->toBeEmpty();
    Sleep::assertSleptTimes(10);

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeFalse()
        ->and($this->herdr->sentKeys)->toHaveCount(3);

    $attempt = $this->phase->fresh()->output['workspace_shutdown']['exit_attempted']['orb-234-loop-builder'];
    expect($attempt['attempt_count'])->toBe(3)
        ->and($attempt['last_attempted_at'])->toBeString();
});

it('stops retrying as soon as the owned agent exits', function () {
    $withAgent = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $withoutAgent = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$withAgent, $withAgent, $withoutAgent, $withoutAgent, $closed];
    $this->herdr->agentOutput = 'Agent is idle. No command is waiting.';

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
        ])
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace']);
});

it('resumes a retained exit intent created before attempt counts were recorded', function () {
    $snapshot = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $this->herdr->snapshots = [$snapshot];
    $this->herdr->agentOutput = 'Agent is idle. No command is waiting.';

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeFalse();

    $phase = $this->phase->fresh();
    $output = $phase->output;
    unset(
        $output['workspace_shutdown']['exit_attempted']['orb-234-loop-builder']['attempt_count'],
        $output['workspace_shutdown']['exit_attempted']['orb-234-loop-builder']['last_attempted_at'],
    );
    $phase->output = $output;
    $phase->save();
    $this->herdr->sentKeys = [];

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeFalse()
        ->and($this->herdr->sentKeys)->toBe([
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
            [
                'name' => 'orb-234-loop-builder',
                'keys' => ['ctrl+c', '/', 'q', 'u', 'i', 't', 'enter'],
            ],
        ])
        ->and($this->phase->fresh()->output['workspace_shutdown']['exit_attempted']['orb-234-loop-builder']['attempt_count'])
        ->toBe(3);
});

it('rejects a retained retry count without its retry timestamp', function () {
    $snapshot = shutdownSnapshot($this->repository, $this->worktree, agentStatus: 'idle');
    $this->herdr->snapshots = [$snapshot];
    $this->herdr->agentOutput = 'Agent is idle. No command is waiting.';

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeFalse();

    $phase = $this->phase->fresh();
    $output = $phase->output;
    unset($output['workspace_shutdown']['exit_attempted']['orb-234-loop-builder']['last_attempted_at']);
    $phase->output = $output;
    $phase->save();

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'exit intent is inconsistent');
});

it('uses the Claude exit command for an owned Claude agent', function () {
    $withAgent = shutdownSnapshot(
        $this->repository,
        $this->worktree,
        agentStatus: 'done',
        agentKind: 'claude',
    );
    $withoutAgent = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$withAgent, $withoutAgent, $withoutAgent, $closed];

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->sentKeys)->toBe([[
            'name' => 'orb-234-loop-builder',
            'keys' => ['/', 'e', 'x', 'i', 't', 'enter'],
        ]])
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace']);
});

it('preserves a workspace with an active or unknown agent', function (string $status, string $name) {
    $this->herdr->snapshots = [shutdownSnapshot(
        $this->repository,
        $this->worktree,
        agentStatus: $status,
        agentName: $name,
    )];

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'unknown, active, or displaced')
        ->and($this->herdr->sentKeys)->toBeEmpty()
        ->and($this->herdr->closedWorkspaces)->toBeEmpty();
})->with([
    'active' => ['working', 'orb-234-loop-builder'],
    'unknown' => ['idle', 'unowned-agent'],
]);

it('preserves a workspace whose pane is not an idle shell', function () {
    $snapshot = shutdownSnapshot($this->repository, $this->worktree);
    $this->herdr->snapshots = [$snapshot];
    $this->herdr->busyPane = true;

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'not an idle shell')
        ->and($this->herdr->closedWorkspaces)->toBeEmpty()
        ->and($this->herdr->processCalls)->toBe(21);
});

it('reconciles an ambiguous close from its retained intent without closing twice', function () {
    $open = shutdownSnapshot($this->repository, $this->worktree);
    $closed = shutdownSnapshot($this->repository, $this->worktree, includeTarget: false);
    $this->herdr->snapshots = [$open, $open];
    $this->herdr->failClose = true;

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(RequestFailed::class, 'timed out');
    expect($this->herdr->closedWorkspaces)->toBe(['issue-workspace'])
        ->and($this->phase->fresh()->output['workspace_shutdown']['workspace_close_attempted_at'])
        ->toBeString();

    $this->herdr->failClose = false;
    $this->herdr->snapshots = [$closed];
    $this->herdr->snapshotIndex = 0;

    expect($this->action->handle($this->config, $this->delivery->id, $this->phase->id))->toBeTrue()
        ->and($this->herdr->closedWorkspaces)->toHaveCount(1);
});

it('detects an unrelated workspace disappearance after the targeted close', function () {
    $open = shutdownSnapshot($this->repository, $this->worktree);
    $unsafe = new HerdrSessionSnapshot('0.9.0', 22, [], [], []);
    $this->herdr->snapshots = [$open, $open, $unsafe];

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'unrelated Herdr workspace')
        ->and($this->herdr->closedWorkspaces)->toBe(['issue-workspace'])
        ->and($this->phase->fresh()->output['workspace_shutdown']['closed'])->toBeNull();
});

it('rejects an unsupported protocol before recording or mutating cleanup', function () {
    $snapshot = shutdownSnapshot($this->repository, $this->worktree);
    $this->herdr->snapshots = [new HerdrSessionSnapshot(
        $snapshot->version,
        19,
        $snapshot->workspaces,
        $snapshot->panes,
        $snapshot->agents,
    )];

    expect(fn () => $this->action->handle($this->config, $this->delivery->id, $this->phase->id))
        ->toThrow(OrbitLandingAdvancementFailed::class, 'unsupported session snapshot')
        ->and($this->phase->fresh()->output)->not->toHaveKey('workspace_shutdown')
        ->and($this->herdr->sentKeys)->toBeEmpty()
        ->and($this->herdr->closedWorkspaces)->toBeEmpty();
});

function shutdownSnapshot(
    string $repository,
    string $worktree,
    ?string $agentStatus = null,
    string $agentName = 'orb-234-loop-builder',
    bool $includeTarget = true,
    string $agentKind = 'codex',
): HerdrSessionSnapshot {
    $canonical = new HerdrSnapshotWorkspace('canonical-workspace', null, null, null);
    $canonicalPane = new HerdrSnapshotPane(
        'canonical-workspace',
        'canonical-tab',
        'canonical-pane',
        'canonical-terminal',
        $repository,
    );

    if (! $includeTarget) {
        return new HerdrSessionSnapshot('0.9.0', 22, [$canonical], [$canonicalPane], []);
    }

    $agents = $agentStatus === null ? [] : [new HerdrSnapshotAgent(
        'issue-workspace',
        'issue-tab',
        'issue-pane',
        'issue-terminal',
        $agentKind,
        $agentName,
        $agentStatus,
        $worktree,
    )];

    return new HerdrSessionSnapshot(
        '0.9.0',
        22,
        [
            $canonical,
            new HerdrSnapshotWorkspace('issue-workspace', $repository, $worktree, true),
        ],
        [
            $canonicalPane,
            new HerdrSnapshotPane(
                'issue-workspace',
                'issue-tab',
                'issue-pane',
                'issue-terminal',
                $worktree,
            ),
        ],
        $agents,
    );
}
