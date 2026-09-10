<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Jobs\AdvanceDelivery;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

final class ShadowCommandIssueProvider implements OrbitIssueProvider
{
    /** @var list<array{issue_id: string, issue_key: string}> */
    public array $requests = [];

    public ?OrbitIssueProviderFailed $failure = null;

    public function __construct(private readonly OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->requests[] = ['issue_id' => $issueId, 'issue_key' => $issueKey];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }
}

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/shadow-command-projects-'.bin2hex(random_bytes(4)));
    $this->repository = storage_path('framework/testing/shadow-command-repository-'.bin2hex(random_bytes(4)));
    $this->worktreeRoot = storage_path('framework/testing/shadow-command-worktrees-'.bin2hex(random_bytes(4)));
    $this->worktree = $this->worktreeRoot.'/orb-234';
    $this->headSha = str_repeat('a', 40);
    $this->treeSha = str_repeat('b', 40);
    $this->commonDirectory = $this->repository.'/.git';
    $this->candidateReceipt = $this->commonDirectory.'/orbit-checks/'.$this->headSha.'/review-test/result.json';
    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->worktree.'/.loop', 0755, true);
    File::makeDirectory(dirname($this->candidateReceipt), 0755, true);
    File::put($this->repository.'/bin/worktree-create', "#!/usr/bin/env bash\n");
    File::put($this->repository.'/bin/review-check', "#!/usr/bin/env python3\n");
    File::put($this->candidateReceipt, json_encode(
        shadowCandidateReceipt($this->worktree, $this->headSha, $this->treeSha),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));
    chmod($this->repository.'/bin/worktree-create', 0755);
    chmod($this->repository.'/bin/review-check', 0755);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('herdr.orchestration.enabled', true);
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', shadowCommandConfig($this->repository, $this->worktreeRoot));
    $this->issueProvider = new ShadowCommandIssueProvider(new OrbitIssueSnapshot(
        issueId: shadowIssueId(),
        issueKey: 'ORB-234',
        payload: ['id' => shadowIssueId(), 'identifier' => 'ORB-234', 'title' => 'Test issue'],
        contractHash: str_repeat('e', 64),
    ));
    app()->instance(OrbitIssueProvider::class, $this->issueProvider);

    Queue::fake();
    Process::fake(['*' => Process::sequence()
        ->push($this->worktree."\n")
        ->push($this->headSha."\n")
        ->push("Candidate gate PASSED at {$this->headSha}; receipt: {$this->candidateReceipt}\n")
        ->push($this->treeSha."\n")
        ->push($this->commonDirectory."\n")])
        ->preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->repository);
    File::deleteDirectory($this->worktreeRoot);
});

function shadowCommandConfig(string $repository, string $worktreeRoot): array
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

function runShadowCommand(string $project, string $issueId, string $issueKey): array
{
    return [
        'project' => $project,
        'issue-id' => $issueId,
        'issue-key' => $issueKey,
        '--force' => true,
    ];
}

function shadowIssueId(): string
{
    return '11111111-2222-4333-8444-555555555555';
}

/** @return array<string, mixed> */
function shadowCandidateReceipt(string $worktree, string $headSha, string $treeSha): array
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

it('prepares and records an Orbit worktree before queueing advancement', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Shadow delivery 1 queued for ORB-234 in project orbit.')
        ->assertSuccessful();

    $delivery = Delivery::sole();

    $issueSnapshot = realpath($this->worktree.'/.loop/issue.json');

    expect($delivery->external_issue_id)->toBe(shadowIssueId())
        ->and($delivery->external_issue_key)->toBe('ORB-234')
        ->and($delivery->worktree_path)->toBe(realpath($this->worktree))
        ->and($delivery->candidate_sha)->toBe($this->headSha)
        ->and(PhaseRun::sole()->input)->toBe([
            'issue_snapshot' => [
                'schema' => 1,
                'provider' => 'linear',
                'issue_id' => shadowIssueId(),
                'issue_key' => 'ORB-234',
                'path' => $issueSnapshot,
                'contents_sha256' => hash_file('sha256', $issueSnapshot),
                'contract_schema' => 1,
                'contract_sha256' => str_repeat('e', 64),
            ],
            'candidate_check' => [
                'receipt_path' => realpath($this->candidateReceipt),
                'candidate_sha' => $this->headSha,
                'tree_sha' => $this->treeSha,
            ],
        ])
        ->and($this->issueProvider->requests)->toBe([
            ['issue_id' => shadowIssueId(), 'issue_key' => 'ORB-234'],
        ]);

    Queue::assertPushed(AdvanceDelivery::class, fn (AdvanceDelivery $job): bool => $job->deliveryId === $delivery->id);
    Process::assertRan(fn ($process): bool => $process->command === [realpath($this->repository.'/bin/worktree-create'), 'ORB-234', '--flow=discovery']
        && $process->path === realpath($this->repository)
        && $process->timeout === 300);
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'rev-parse', 'HEAD']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 10);
    Process::assertRan(fn ($process): bool => $process->command === ['composer', 'check']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 3600);
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'rev-parse', 'HEAD^{tree}']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 10);
    Process::assertRan(fn ($process): bool => $process->command === ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir']
        && $process->path === realpath($this->worktree)
        && $process->timeout === 10);
});

it('refuses to start when shadow mode is disabled', function () {
    config()->set('herdr.orchestration.enabled', false);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Herdr orchestration shadow mode is disabled.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses missing and paused projects', function (string $project, bool $pause) {
    if ($pause) {
        $this->project->update(['state' => ProjectOrchestrationState::Paused]);
    }

    $this->artisan('delivery:start-shadow', runShadowCommand($project, shadowIssueId(), 'ORB-234'))
        ->expectsOutput("Project [{$project}] is not configured and enabled.")
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
})->with([
    'missing project' => ['missing', false],
    'paused project' => ['orbit', true],
]);

it('refuses invalid issue keys before preparing a worktree', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'not-a-key'))
        ->expectsOutputToContain('issue key field format is invalid')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses invalid issue IDs before fetching the issue', function () {
    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', 'not-a-uuid', 'ORB-234'))
        ->expectsOutputToContain('issue id field must be a valid UUID')
        ->assertFailed();

    expect(Delivery::count())->toBe(0)
        ->and($this->issueProvider->requests)->toBe([]);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('stops before worktree preparation when the issue snapshot cannot be trusted', function () {
    $this->issueProvider->failure = new OrbitIssueProviderFailed('The Linear issue response is malformed.');

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('The Linear issue response is malformed.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses invalid live project config', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode(['type' => 'orbit'], JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses project config that is not a JSON object', function () {
    DB::table('project_orchestrations')->where('id', $this->project->id)->update([
        'config' => json_encode('invalid', JSON_THROW_ON_ERROR),
    ]);

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutputToContain('The project has invalid orchestration config:')
        ->assertFailed();

    Queue::assertNothingPushed();
    Process::assertNothingRan();
});

it('refuses a failed Orbit worktree adapter', function () {
    Process::fake(['*' => Process::result(errorOutput: 'adapter failed', exitCode: 1)])->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Orbit worktree preparation failed: adapter failed')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes an Orbit worktree adapter launch failure', function () {
    Process::fake(fn () => throw new RuntimeException('launch failed'))->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('The Orbit worktree adapter could not run.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes a prepared worktree Git inspection failure', function () {
    $calls = 0;
    $worktree = $this->worktree;

    Process::fake(function () use (&$calls, $worktree) {
        if ($calls++ === 0) {
            return Process::result(output: $worktree."\n");
        }

        throw new RuntimeException('inspection failed');
    })->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('The prepared worktree Git HEAD could not be inspected.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses returned worktrees outside the configured root', function () {
    Process::fake(['*' => Process::result(output: sys_get_temp_dir()."\n")])->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Orbit returned a worktree outside the configured worktree root.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
    Process::assertRanTimes(fn () => true, 1);
});

it('refuses a malformed Git head from the prepared worktree', function () {
    Process::fake(['*' => Process::sequence()->push($this->worktree."\n")->push('not-a-sha')])
        ->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Could not resolve a valid Git HEAD for the prepared worktree.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a failed Orbit candidate check', function () {
    Process::fake(['*' => Process::sequence()
        ->push($this->worktree."\n")
        ->push($this->headSha."\n")
        ->push(Process::result(errorOutput: 'checks failed', exitCode: 1))])
        ->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Orbit candidate check failed: checks failed')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes an Orbit candidate check launch failure', function () {
    $calls = 0;
    $worktree = $this->worktree;
    $headSha = $this->headSha;

    Process::fake(function () use (&$calls, $worktree, $headSha) {
        return match ($calls++) {
            0 => Process::result(output: $worktree."\n"),
            1 => Process::result(output: $headSha."\n"),
            default => throw new RuntimeException('check launch failed'),
        };
    })->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('The Orbit candidate check could not run.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses an invalid Orbit candidate receipt', function () {
    File::put($this->candidateReceipt, json_encode([
        ...shadowCandidateReceipt($this->worktree, $this->headSha, $this->treeSha),
        'unchanged' => false,
    ], JSON_THROW_ON_ERROR));

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Orbit candidate check returned an invalid receipt.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a candidate receipt outside the repository check directory', function () {
    $outside = $this->repository.'/outside/result.json';
    File::makeDirectory(dirname($outside), 0755, true);
    File::put($outside, File::get($this->candidateReceipt));
    Process::fake(['*' => Process::sequence()
        ->push($this->worktree."\n")
        ->push($this->headSha."\n")
        ->push("Candidate gate PASSED at {$this->headSha}; receipt: {$outside}\n")
        ->push($this->treeSha."\n")
        ->push($this->commonDirectory."\n")])
        ->preventStrayProcesses();

    $this->artisan('delivery:start-shadow', runShadowCommand('orbit', shadowIssueId(), 'ORB-234'))
        ->expectsOutput('Orbit candidate check returned an invalid receipt path.')
        ->assertFailed();

    expect(Delivery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a duplicate active delivery without resolving Git again', function () {
    $arguments = runShadowCommand('orbit', shadowIssueId(), 'ORB-234');
    $this->artisan('delivery:start-shadow', $arguments)->assertSuccessful();
    Queue::fake();

    $this->artisan('delivery:start-shadow', $arguments)
        ->expectsOutput('An active delivery already exists for [ORB-234].')
        ->assertFailed();

    expect(Delivery::count())->toBe(1);
    Queue::assertNothingPushed();
    Process::assertRanTimes(fn () => true, 5);
});
