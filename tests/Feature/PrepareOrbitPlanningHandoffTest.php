<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Actions\PrepareOrbitPlanningHandoff;
use App\Delivery\Actions\StartOrbitDelivery;
use App\Delivery\Actions\StartShadowDelivery;
use App\Delivery\Contracts\HerdrRuntime;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\VerifiedIssueSnapshot;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

final class PlanningHandoffCallLog
{
    /** @var list<string> */
    public array $events = [];
}

final class PlanningHandoffIssueProvider implements OrbitIssueProvider
{
    /** @var list<array{issue_id: string, issue_key: string}> */
    public array $requests = [];

    public ?OrbitIssueProviderFailed $failure = null;

    public function __construct(
        public OrbitIssueSnapshot $snapshot,
        private readonly PlanningHandoffCallLog $log,
    ) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->requests[] = ['issue_id' => $issueId, 'issue_key' => $issueKey];
        $this->log->events[] = 'linear.fetch';

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-11 12:00:00 UTC');
    $this->base = storage_path('framework/testing/planning-handoff-'.bin2hex(random_bytes(4)));
    $this->projectsPath = $this->base.'/projects';
    $this->repositoryPath = $this->base.'/repository';
    $this->commonDirectory = $this->repositoryPath.'/.git';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    $this->headSha = str_repeat('a', 40);
    $this->treeSha = str_repeat('b', 40);
    $this->contractHash = str_repeat('c', 64);
    $this->issueId = '11111111-2222-4333-8444-555555555555';
    $this->receiptPath = $this->commonDirectory.'/orbit-checks/'.$this->headSha.'/startup/result.json';
    $this->snapshotPath = $this->worktreePath.'/.loop/issue.json';
    $this->log = new PlanningHandoffCallLog;

    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repositoryPath.'/bin', 0755, true);
    File::makeDirectory(dirname($this->receiptPath), 0755, true);
    File::makeDirectory($this->worktreePath.'/.loop', 0755, true);
    File::put($this->repositoryPath.'/bin/loop-flow', "#!/usr/bin/env python3\n");
    chmod($this->repositoryPath.'/bin/loop-flow', 0755);
    File::put($this->receiptPath, json_encode(
        planningHandoffReceipt($this->worktreePath, $this->headSha, $this->treeSha),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));

    $initialPayload = planningHandoffIssue($this->issueId);
    $snapshotContents = json_encode(
        $initialPayload,
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    )."\n";
    File::put($this->snapshotPath, $snapshotContents);
    chmod($this->snapshotPath, 0600);

    config()->set('commander.projects_path', $this->projectsPath);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', planningHandoffConfig(
        $this->repositoryPath,
        $this->worktreeRoot,
    ));
    $this->prepared = new PreparedIssueSnapshot(
        schema: OrbitIssueSnapshot::SCHEMA,
        provider: OrbitIssueSnapshot::PROVIDER,
        path: realpath($this->snapshotPath),
        contentsHash: hash('sha256', $snapshotContents),
        contractSchema: OrbitIssueSnapshot::CONTRACT_SCHEMA,
        contractHash: $this->contractHash,
        issueId: $this->issueId,
        issueKey: 'ORB-234',
    );
    $this->candidateCheck = new CandidateCheck(realpath($this->receiptPath), $this->headSha, $this->treeSha);
    $this->delivery = app(StartShadowDelivery::class)->handle(
        $this->project,
        new VerifiedIssueSnapshot($this->prepared, now()->toImmutable()),
        realpath($this->worktreePath),
        $this->candidateCheck,
    );

    $freshPayload = planningHandoffIssue($this->issueId, [
        'url' => 'https://linear.app/orbit/issue/ORB-234/current',
        'updatedAt' => '2026-09-11T11:59:00.000Z',
        'state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'In Progress', 'type' => 'started'],
        'inverseRelations' => [
            'nodes' => [[
                'type' => 'blocks',
                'issue' => ['identifier' => 'ORB-7', 'state' => ['type' => 'completed']],
            ]],
            'pageInfo' => ['hasNextPage' => false],
        ],
    ]);
    $this->provider = new PlanningHandoffIssueProvider(
        new OrbitIssueSnapshot($this->issueId, 'ORB-234', $freshPayload, $this->contractHash),
        $this->log,
    );
    app()->instance(OrbitIssueProvider::class, $this->provider);

    mock(HerdrRuntime::class)
        ->shouldNotReceive('openWorktree', 'splitPane', 'startAgent', 'promptAgent', 'getAgent');
    Queue::fake();
    fakePlanningHandoffInspection($this);
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->base);
});

/** @return array<string, mixed> */
function planningHandoffConfig(string $repository, string $worktreeRoot): array
{
    return [
        'type' => 'orbit',
        'repository' => $repository,
        'worktreeRoot' => $worktreeRoot,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ];
}

/** @return array<string, mixed> */
function planningHandoffIssue(string $issueId, array $overrides = []): array
{
    return [
        'id' => $issueId,
        'identifier' => 'ORB-234',
        'title' => 'Plan the delivery handoff',
        'url' => 'https://linear.app/orbit/issue/ORB-234',
        'description' => "## Outcome\n\nPrepare it.",
        'updatedAt' => '2026-09-11T10:00:00.000Z',
        'state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'Todo', 'type' => 'unstarted'],
        'assignee' => null,
        'delegate' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
        'team' => ['id' => '33333333-4444-4555-8666-777777777777', 'states' => ['nodes' => []]],
        'labels' => ['nodes' => [['name' => 'apps:cli']], 'pageInfo' => ['hasNextPage' => false]],
        'attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function planningHandoffReceipt(string $worktree, string $headSha, string $treeSha): array
{
    $checks = [];

    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        foreach ([
            ['composer', 'validate', '--strict'],
            ['composer', 'check'],
            ['composer', 'test:affected'],
        ] as $command) {
            $checks[] = ['project' => $project, 'command' => $command, 'exit_code' => 0];
        }
    }

    return [
        'schema' => 1,
        'role' => 'builder',
        'candidate' => $headSha,
        'tree' => $treeSha,
        'worktree' => $worktree,
        'checks' => $checks,
        'passed' => true,
        'unchanged' => true,
    ];
}

function fakePlanningHandoffInspection(object $test): void
{
    Process::fake(function ($process) use ($test) {
        $command = $process->command;
        $key = match ($command) {
            ['git', 'worktree', 'list', '--porcelain'] => 'repository.worktrees',
            ['git', 'status', '--porcelain'] => 'worktree.status',
            ['git', 'diff', '--name-only', '--diff-filter=U'] => 'worktree.conflicts',
            ['git', 'rev-parse', 'HEAD'] => 'worktree.head',
            ['git', 'rev-parse', 'HEAD^{tree}'] => 'worktree.tree',
            ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'] => 'worktree.common',
            [realpath($test->repositoryPath.'/bin/loop-flow'), 'status', '--worktree='.$test->worktreePath] => 'worktree.flow',
            default => throw new RuntimeException('Unexpected planning handoff command.'),
        };
        $test->log->events[] = $key;
        $output = match ($key) {
            'repository.worktrees' => "worktree {$test->repositoryPath}\nHEAD ".str_repeat('d', 40)."\nbranch refs/heads/main\n\n"
                ."worktree {$test->worktreePath}\nHEAD {$test->headSha}\nbranch refs/heads/orb-234\n",
            'worktree.head' => $test->headSha."\n",
            'worktree.tree' => $test->treeSha."\n",
            'worktree.common' => $test->commonDirectory."\n",
            'worktree.flow' => "discovery\n",
            default => '',
        };

        return Process::result(output: $output);
    })->preventStrayProcesses();
}

it('prepares an exact fresh non-runnable Orbit planning handoff without changing workflow state', function () {
    $deliveryBefore = $this->delivery->fresh()->getAttributes();
    $phaseBefore = PhaseRun::sole()->getAttributes();

    $handoff = app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id);

    expect($handoff->schema)->toBe(1)
        ->and($handoff->deliveryId)->toBe($this->delivery->id)
        ->and($handoff->phase)->toBe('planning')
        ->and($handoff->provider)->toBe('linear')
        ->and($handoff->issueId)->toBe($this->issueId)
        ->and($handoff->issueKey)->toBe('ORB-234')
        ->and($handoff->issue)->toBe($this->provider->snapshot->payload)
        ->and($handoff->issueUpdatedAt)->toBe('2026-09-11T11:59:00.000Z')
        ->and($handoff->worktreePath)->toBe($this->worktreePath)
        ->and($handoff->branch)->toBe('orb-234')
        ->and($handoff->candidateSha)->toBe($this->headSha)
        ->and($handoff->treeSha)->toBe($this->treeSha)
        ->and($handoff->flow)->toBe('discovery')
        ->and($handoff->qualityReceiptPath)->toBe($this->receiptPath)
        ->and($handoff->snapshotPath)->toBe($this->snapshotPath)
        ->and($handoff->snapshotContentsHash)->toBe(hash_file('sha256', $this->snapshotPath))
        ->and($handoff->contractSchema)->toBe(2)
        ->and($handoff->contractHash)->toBe($this->contractHash)
        ->and($handoff->verifiedAt->toISOString())->toBe('2026-09-11T12:00:00.000000Z')
        ->and($handoff->dispatchable)->toBeFalse()
        ->and($this->log->events)->toBe([
            'repository.worktrees',
            'worktree.status',
            'worktree.conflicts',
            'worktree.head',
            'worktree.tree',
            'worktree.common',
            'worktree.flow',
            'linear.fetch',
        ])
        ->and($this->provider->requests)->toBe([
            ['issue_id' => $this->issueId, 'issue_key' => 'ORB-234'],
        ])
        ->and($this->delivery->fresh()->getAttributes())->toBe($deliveryBefore)
        ->and(PhaseRun::sole()->getAttributes())->toBe($phaseBefore)
        ->and(AgentDispatch::count())->toBe(0);

    Queue::assertNothingPushed();
});

it('repeats repository verification and the final Linear fetch on every preparation', function () {
    $action = app(PrepareOrbitPlanningHandoff::class);

    $action->handle($this->delivery->id);
    $action->handle($this->delivery->id);

    expect($this->provider->requests)->toHaveCount(2)
        ->and(collect($this->log->events)->filter(fn (string $event): bool => $event === 'linear.fetch'))->toHaveCount(2)
        ->and(collect($this->log->events)->filter(fn (string $event): bool => $event === 'repository.worktrees'))->toHaveCount(2)
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts an eligible Todo snapshot as evidence but never marks it dispatchable', function () {
    $payload = planningHandoffIssue($this->issueId);
    $this->provider->snapshot = new OrbitIssueSnapshot(
        $this->issueId,
        'ORB-234',
        $payload,
        $this->contractHash,
    );

    $handoff = app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id);

    expect($handoff->issue['state']['name'])->toBe('Todo')
        ->and($handoff->dispatchable)->toBeFalse()
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('prepares the same non-runnable handoff for the live Orbit workflow ledger', function () {
    $this->delivery->status = DeliveryStatus::Completed;
    $this->delivery->completed_at = now();
    $this->delivery->save();
    $live = app(StartOrbitDelivery::class)->handle(
        $this->project,
        new VerifiedIssueSnapshot($this->prepared, now()->toImmutable()),
        realpath($this->worktreePath),
        $this->candidateCheck,
    );

    $handoff = app(PrepareOrbitPlanningHandoff::class)->handle($live->id);

    expect($handoff->deliveryId)->toBe($live->id)
        ->and($handoff->phase)->toBe('planning')
        ->and($handoff->issueKey)->toBe('ORB-234')
        ->and($handoff->worktreePath)->toBe($this->worktreePath)
        ->and($handoff->contractHash)->toBe($this->contractHash)
        ->and($handoff->dispatchable)->toBeFalse()
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects live Orbit ledger branch drift before repository or Linear work', function () {
    $this->delivery->status = DeliveryStatus::Completed;
    $this->delivery->completed_at = now();
    $this->delivery->save();
    $live = app(StartOrbitDelivery::class)->handle(
        $this->project,
        new VerifiedIssueSnapshot($this->prepared, now()->toImmutable()),
        realpath($this->worktreePath),
        $this->candidateCheck,
    );
    $live->branch = 'orb-999';
    $live->save();

    expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($live->id))
        ->toThrow(OrbitPlanningHandoffFailed::class, 'no valid Orbit preparation record');

    expect($this->provider->requests)->toBe([])
        ->and($this->log->events)->toBe([])
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects current Linear contract or identity drift after repository verification', function (string $case) {
    $this->provider->snapshot = match ($case) {
        'contract' => new OrbitIssueSnapshot($this->issueId, 'ORB-234', planningHandoffIssue($this->issueId), str_repeat('d', 64)),
        'UUID' => new OrbitIssueSnapshot('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'ORB-234', planningHandoffIssue($this->issueId), $this->contractHash),
        'key' => new OrbitIssueSnapshot($this->issueId, 'ORB-235', planningHandoffIssue($this->issueId), $this->contractHash),
    };

    expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id))
        ->toThrow(OrbitIssueContractChanged::class, 'changed before planning preparation');

    expect($this->log->events)->toHaveCount(8)
        ->and($this->log->events[7])->toBe('linear.fetch')
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['contract', 'UUID', 'key']);

it('propagates a current Linear eligibility failure only after repository verification', function () {
    $this->provider->failure = new OrbitIssueProviderFailed('The Orbit issue is not eligible.');

    expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id))
        ->toThrow(OrbitIssueProviderFailed::class, 'not eligible');

    expect($this->log->events)->toHaveCount(8)
        ->and($this->log->events[7])->toBe('linear.fetch')
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();

    $this->provider->failure = null;
    $handoff = app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id);

    expect($handoff->dispatchable)->toBeFalse()
        ->and($this->provider->requests)->toHaveCount(2);
});

it('rejects an inactive project or malformed preparation without external work', function (string $case) {
    if ($case === 'paused project') {
        $this->project->update(['state' => ProjectOrchestrationState::Paused]);
    } else {
        $phase = PhaseRun::sole();
        $input = $phase->input;
        unset($input['candidate_check']['tree_sha']);
        $phase->update(['input' => $input]);
    }

    expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id))
        ->toThrow(OrbitPlanningHandoffFailed::class);

    expect($this->provider->requests)->toBe([])
        ->and($this->log->events)->toBe([])
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['paused project', 'malformed preparation']);

it('stops before repository verification when another controller owns the issue lock', function () {
    $directory = $this->commonDirectory.'/orbit-delivery/v1/orb-234';
    $lockPath = $directory.'/controller.lock';
    File::makeDirectory($directory, 0700, true);
    $handle = fopen($lockPath, 'c+');

    if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Could not arrange the competing controller lock.');
    }

    try {
        expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id))
            ->toThrow(OrbitRepositoryFailed::class, 'Another Orbit delivery controller');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    expect($this->provider->requests)->toBe([])
        ->and($this->log->events)->toBe([])
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stops before repository verification when a legacy journal exists', function (string $journal) {
    $directory = $this->commonDirectory.'/orbit-delivery/v1/orb-234';
    File::makeDirectory($directory, 0700, true);
    File::put($directory.'/'.$journal, '{}');

    expect(fn () => app(PrepareOrbitPlanningHandoff::class)->handle($this->delivery->id))
        ->toThrow(OrbitRepositoryFailed::class, 'legacy Orbit controller journal');

    expect($this->provider->requests)->toBe([])
        ->and($this->log->events)->toBe([])
        ->and(AgentDispatch::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['state.json', 'worker.json']);
