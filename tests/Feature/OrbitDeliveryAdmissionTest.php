<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Contracts\OrbitDeliveryLoopStarter;
use App\Delivery\Contracts\OrbitEligibleIssueProvider;
use App\Delivery\Contracts\OrbitIssueOwnershipClaimer;
use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\IssueProviders\SshOrbitIssueOwnershipClaimer;
use App\Delivery\Queries\NextEligibleIssue;
use App\Delivery\Repositories\ProcessOrbitDeliveryLoopStarter;
use App\Jobs\AdmitNextOrbitDelivery;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

final class AdmissionEligibleProvider implements OrbitEligibleIssueProvider
{
    public int $calls = 0;

    public function __construct(private readonly OrbitEligibleIssue $issue) {}

    public function next(): ?OrbitEligibleIssue
    {
        $this->calls++;

        return $this->issue;
    }
}

final class AdmissionOwnershipClaimer implements OrbitIssueOwnershipClaimer
{
    public int $calls = 0;

    public function claim(OrbitEligibleIssue $issue): OrbitIssueSnapshot
    {
        $this->calls++;

        return $issue->snapshot;
    }
}

final class AdmissionLoopStarter implements OrbitDeliveryLoopStarter
{
    /** @var list<array{project: string, issue: string}> */
    public array $starts = [];

    public function start(string $projectId, string $issueKey): void
    {
        $this->starts[] = ['project' => $projectId, 'issue' => $issueKey];
    }
}

function admissionIssue(bool $owned = false): array
{
    $labels = [
        ['id' => '44444444-5555-4666-8777-000000000001', 'name' => 'Feature'],
        ['id' => '44444444-5555-4666-8777-000000000002', 'name' => 'docs'],
    ];

    if ($owned) {
        $labels[] = [
            'id' => '34651888-f68e-4bf2-b322-ec05c2a7fc64',
            'name' => 'controller:commander',
        ];
    }

    return [
        'id' => 'd0178b6c-9564-4c43-81b1-232505f404e2',
        'identifier' => 'ORB-200',
        'title' => 'Report production release drift',
        'url' => 'https://linear.app/nckrtl/issue/ORB-200',
        'description' => "## Outcome\n\nReport it.",
        'updatedAt' => '2026-09-12T08:00:00.000Z',
        'sortOrder' => -34258,
        'state' => [
            'id' => '22222222-3333-4444-8555-666666666666',
            'name' => 'Todo',
            'type' => 'unstarted',
        ],
        'assignee' => null,
        'delegate' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
        'team' => [
            'id' => 'adf44a8f-4b0c-46ae-a846-1414e7d919aa',
            'states' => ['nodes' => [
                ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'Todo'],
                ['id' => '33333333-4444-4555-8666-777777777777', 'name' => 'In Progress'],
            ]],
        ],
        'labels' => ['nodes' => $labels, 'pageInfo' => ['hasNextPage' => false]],
        'attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
    ];
}

function admissionQueueResponse(bool $owned = false): array
{
    $issue = admissionIssue($owned);
    $issue['team'] = ['id' => 'adf44a8f-4b0c-46ae-a846-1414e7d919aa'];

    return ['data' => [
        'viewer' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
        'team' => [
            'id' => 'adf44a8f-4b0c-46ae-a846-1414e7d919aa',
            'states' => admissionIssue()['team']['states'],
            'issues' => [
                'nodes' => [$issue],
                'pageInfo' => ['hasNextPage' => false],
            ],
        ],
    ]];
}

function admissionReadBack(bool $owned = true): array
{
    return ['data' => [
        'viewer' => ['id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf'],
        'issue' => admissionIssue($owned),
    ]];
}

function admissionCandidate(): OrbitEligibleIssue
{
    $payload = admissionIssue();

    return new OrbitEligibleIssue(
        snapshot: new OrbitIssueSnapshot(
            issueId: $payload['id'],
            issueKey: $payload['identifier'],
            payload: $payload,
            contractHash: app(OrbitIssueSnapshotFactory::class)->contractHash($payload),
        ),
        title: $payload['title'],
        url: $payload['url'],
        labels: ['Feature', 'docs'],
    );
}

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/admission-projects-'.bin2hex(random_bytes(4)));
    $this->repository = storage_path('framework/testing/admission-repository-'.bin2hex(random_bytes(4)));
    $this->worktrees = storage_path('framework/testing/admission-worktrees-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    File::makeDirectory($this->repository, 0755, true);
    File::makeDirectory($this->repository.'/.git', 0755, true);
    File::makeDirectory($this->worktrees, 0755, true);

    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('commander.hermes.ssh_target', 'tom@mini');
    config()->set('commander.hermes.profiles.tom', '/Users/tom/.hermes/profiles/tom');
    config()->set('commander.hermes.tom_linear_viewer_id', '4fa61558-9052-45f7-8a7c-49e0b891d4bf');
    config()->set('commander.hermes.orbit_linear_team_id', 'adf44a8f-4b0c-46ae-a846-1414e7d919aa');
    config()->set('commander.delivery.orbit_controller_label_id', '34651888-f68e-4bf2-b322-ec05c2a7fc64');
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => $this->repository,
        'worktreeRoot' => $this->worktrees,
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
    File::deleteDirectory($this->repository);
    File::deleteDirectory($this->worktrees);
});

it('adds the Commander label and accepts only the exact Linear read-back', function () {
    Process::fake(['*' => Process::sequence()
        ->push(json_encode(admissionQueueResponse(), JSON_THROW_ON_ERROR))
        ->push('{"data":{"issueAddLabel":{"success":true}}}')
        ->push(json_encode(admissionReadBack(), JSON_THROW_ON_ERROR))])
        ->preventStrayProcesses();

    $candidate = app(OrbitEligibleIssueProvider::class)->next();

    expect($candidate)->toBeInstanceOf(OrbitEligibleIssue::class);
    $claimed = app(SshOrbitIssueOwnershipClaimer::class)->claim($candidate);

    expect($claimed->issueKey)->toBe('ORB-200')
        ->and(array_column($claimed->payload['labels']['nodes'], 'name'))
        ->toContain('controller:commander');

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input)
            && is_string($input['document'] ?? null)
            && str_starts_with(trim($input['document']), 'mutation LoopOwnership')
            && ($input['variables'] ?? null) === [
                'id' => 'd0178b6c-9564-4c43-81b1-232505f404e2',
                'labelId' => '34651888-f68e-4bf2-b322-ec05c2a7fc64',
            ];
    });
});

it('does not repeat the label mutation when Commander already owns the issue', function () {
    Process::fake(['*' => Process::sequence()
        ->push(json_encode(admissionQueueResponse(true), JSON_THROW_ON_ERROR))
        ->push(json_encode(admissionReadBack(), JSON_THROW_ON_ERROR))])
        ->preventStrayProcesses();

    $candidate = app(OrbitEligibleIssueProvider::class)->next();

    expect($candidate)->toBeInstanceOf(OrbitEligibleIssue::class);
    app(SshOrbitIssueOwnershipClaimer::class)->claim($candidate);

    Process::assertNotRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input)
            && is_string($input['document'] ?? null)
            && str_starts_with(trim($input['document']), 'mutation');
    });
});

it('accepts a lost ownership mutation response only after exact read-back', function () {
    Process::fake(['*' => Process::sequence()
        ->push(json_encode(admissionQueueResponse(), JSON_THROW_ON_ERROR))
        ->push(Process::result(exitCode: 1, errorOutput: 'response lost'))
        ->push(json_encode(admissionReadBack(), JSON_THROW_ON_ERROR))])
        ->preventStrayProcesses();

    $candidate = app(OrbitEligibleIssueProvider::class)->next();

    expect($candidate)->toBeInstanceOf(OrbitEligibleIssue::class)
        ->and(app(SshOrbitIssueOwnershipClaimer::class)->claim($candidate)->issueKey)
        ->toBe('ORB-200');
});

it('starts Commander directly in one deterministic transient service', function () {
    Process::fake(function ($process) {
        return match ($process->command[0] ?? null) {
            'systemctl' => Process::result(exitCode: 1),
            'systemd-run' => Process::result(output: "Running as unit.\n"),
            default => throw new RuntimeException('Unexpected process.'),
        };
    })->preventStrayProcesses();

    app(ProcessOrbitDeliveryLoopStarter::class)->start('orbit', 'ORB-200');

    $php = realpath(PHP_BINARY);

    expect($php)->not->toBeFalse();
    Process::assertRan(fn ($process): bool => $process->command === [
        'systemd-run', '--user', '--collect', '--unit=commander-orbit-admission-orb-200.service',
        '--property=WorkingDirectory='.base_path(),
        '--property=UnsetEnvironment=SSH_AUTH_SOCK',
        'env', '-u', 'SSH_AUTH_SOCK', $php, base_path('artisan'),
        'delivery:start-orbit', 'orbit', 'ORB-200', '--idempotent', '--force',
    ] && $process->path === base_path());
});

it('uses one bounded unique admission job and stays disabled by default', function () {
    Process::fake()->preventStrayProcesses();
    $job = new AdmitNextOrbitDelivery;
    $job->handle(
        app(NextEligibleIssue::class),
        app(OrbitIssueOwnershipClaimer::class),
        app(OrbitDeliveryLoopStarter::class),
    );

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('commander-orbit-delivery-admission')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and($job->tries)->toBe(0)
        ->and($job->retryUntil() > now())->toBeTrue();
    Process::assertNothingRan();
});

it('selects, claims, and starts one issue when automatic admission is enabled', function () {
    config()->set('commander.delivery.orbit_auto_admission', true);
    $provider = new AdmissionEligibleProvider(admissionCandidate());
    $ownership = new AdmissionOwnershipClaimer;
    $loop = new AdmissionLoopStarter;
    app()->instance(OrbitEligibleIssueProvider::class, $provider);

    (new AdmitNextOrbitDelivery)->handle(
        app(NextEligibleIssue::class),
        $ownership,
        $loop,
    );

    expect($provider->calls)->toBe(1)
        ->and($ownership->calls)->toBe(1)
        ->and($loop->starts)->toBe([['project' => 'orbit', 'issue' => 'ORB-200']]);
});
