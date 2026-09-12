<?php

use App\Delivery\Actions\ConfigureProjectOrchestration;
use App\Delivery\Contracts\OrbitEligibleIssueProvider;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Mcp\Servers\CommanderServer;
use App\Mcp\Tools\GetNextEligibleIssue;
use App\Models\Delivery;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function eligibleViewerId(): string
{
    return '4fa61558-9052-45f7-8a7c-49e0b891d4bf';
}

function eligibleTeamId(): string
{
    return 'adf44a8f-4b0c-46ae-a846-1414e7d919aa';
}

/** @return array<string, mixed> */
function eligibleIssue(int $number, int|float|null $sortOrder, array $overrides = []): array
{
    return [
        'id' => sprintf('11111111-2222-4333-8444-%012d', $number),
        'identifier' => 'ORB-'.$number,
        'title' => 'Issue '.$number,
        'url' => 'https://linear.app/nckrtl/issue/ORB-'.$number,
        'description' => "## Outcome\n\nDeliver it.",
        'updatedAt' => '2026-09-12T08:00:00.000Z',
        'sortOrder' => $sortOrder,
        'state' => [
            'id' => '22222222-3333-4444-8555-666666666666',
            'name' => 'Todo',
            'type' => 'unstarted',
        ],
        'assignee' => null,
        'delegate' => ['id' => eligibleViewerId()],
        'team' => [
            'id' => eligibleTeamId(),
            'states' => ['nodes' => [
                ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'Todo'],
                ['id' => '33333333-4444-4555-8666-777777777777', 'name' => 'In Progress'],
            ]],
        ],
        'labels' => [
            'nodes' => [['name' => 'Feature'], ['name' => 'docs']],
            'pageInfo' => ['hasNextPage' => false],
        ],
        'attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        ...$overrides,
    ];
}

/** @param list<array<string, mixed>> $issues */
function eligibleResponse(array $issues, bool $hasNextPage = false): array
{
    return ['data' => [
        'viewer' => ['id' => eligibleViewerId()],
        'team' => [
            'id' => eligibleTeamId(),
            'issues' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage],
                'nodes' => $issues,
            ],
        ],
    ]];
}

function fakeEligibleResponse(array $response): void
{
    Process::fake(['*' => Process::result(output: json_encode($response, JSON_THROW_ON_ERROR))])
        ->preventStrayProcesses();
}

beforeEach(function () {
    $this->projectsPath = storage_path('framework/testing/eligible-issue-projects-'.bin2hex(random_bytes(4)));
    File::makeDirectory($this->projectsPath, 0755, true);
    config()->set('commander.projects_path', $this->projectsPath);
    config()->set('commander.hermes.ssh_target', 'tom@mini');
    config()->set('commander.hermes.profiles.tom', '/Users/tom/.hermes/profiles/tom');
    config()->set('commander.hermes.tom_linear_viewer_id', eligibleViewerId());
    config()->set('commander.hermes.orbit_linear_team_id', eligibleTeamId());
    app(SharedKnowledgeProjectRepository::class)->create('orbit', ['name' => 'Orbit', 'status' => 'active']);
    $this->project = app(ConfigureProjectOrchestration::class)->handle('orbit', [
        'type' => 'orbit',
        'repository' => '/home/nckrtl/orbit',
        'worktreeRoot' => '/fast/worktrees/orbit',
        'herdrSession' => 'orbit',
        'concurrency' => 1,
        'defaultFlow' => 'discovery',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->projectsPath);
});

it('returns the first eligible Todo in Linear order without claiming or starting it', function () {
    fakeEligibleResponse(eligibleResponse([
        eligibleIssue(999, -400, ['inverseRelations' => [
            'nodes' => [[
                'type' => 'blocks',
                'issue' => ['identifier' => 'ORB-998', 'state' => ['type' => 'started']],
            ]],
            'pageInfo' => ['hasNextPage' => false],
        ]]),
        eligibleIssue(3, -100),
        eligibleIssue(200, -200),
    ]));

    CommanderServer::tool(GetNextEligibleIssue::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('project_id', 'orbit')
            ->where('capacity', ['active' => 0, 'limit' => 1, 'available' => true])
            ->where('issue.id', '11111111-2222-4333-8444-000000000200')
            ->where('issue.key', 'ORB-200')
            ->where('issue.title', 'Issue 200')
            ->where('issue.url', 'https://linear.app/nckrtl/issue/ORB-200')
            ->where('issue.labels', ['Feature', 'docs'])
            ->where('issue.controller_owned', false)
            ->where('issue.contract_sha256', fn ($hash) => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1));

    expect(Delivery::query()->count())->toBe(0);

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return $process->command === [
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'tom@mini',
            "'/Users/tom/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc",
        ]
            && $process->timeout === 30
            && is_array($input)
            && ($input['service'] ?? null) === 'linear'
            && ($input['variables'] ?? null) === ['teamId' => eligibleTeamId()]
            && is_string($input['document'] ?? null)
            && str_starts_with(trim($input['document']), 'query OrbitEligibleIssues')
            && str_contains($input['document'], 'sortOrder')
            && ! str_contains($input['document'], 'mutation');
    });
});

it('returns no issue when every Todo has a deterministic eligibility hold', function () {
    fakeEligibleResponse(eligibleResponse([
        eligibleIssue(1, -500, ['delegate' => null]),
        eligibleIssue(2, -400, ['assignee' => ['id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424']]),
        eligibleIssue(3, -300, ['description' => "## Readiness\n\nNeeds a decision."]),
        eligibleIssue(4, -200, ['children' => [
            'nodes' => [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']],
            'pageInfo' => ['hasNextPage' => false],
        ]]),
        eligibleIssue(5, -100, ['labels' => [
            'nodes' => [['name' => 'Feature'], ['name' => 'maintenance:monorepo']],
            'pageInfo' => ['hasNextPage' => false],
        ]]),
    ]));

    CommanderServer::tool(GetNextEligibleIssue::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent([
            'project_id' => 'orbit',
            'capacity' => ['active' => 0, 'limit' => 1, 'available' => true],
            'issue' => null,
        ]);
});

it('does not read Linear when Commander project capacity is full', function () {
    Process::fake()->preventStrayProcesses();
    Delivery::query()->create([
        'project_orchestration_id' => $this->project->id,
        'external_issue_provider' => 'linear',
        'external_issue_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'external_issue_key' => 'ORB-42',
        'workflow_type' => 'orbit-feature',
        'workflow_version' => 1,
        'status' => DeliveryStatus::Preparing,
        'current_phase' => 'planning',
    ]);

    CommanderServer::tool(GetNextEligibleIssue::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent([
            'project_id' => 'orbit',
            'capacity' => ['active' => 1, 'limit' => 1, 'available' => false],
            'issue' => null,
        ]);

    Process::assertNothingRan();
});

it('fails closed when the Linear queue response cannot be trusted', function (array $response) {
    fakeEligibleResponse($response);

    expect(fn () => app(OrbitEligibleIssueProvider::class)->next())
        ->toThrow(OrbitIssueProviderFailed::class);
})->with([
    'paginated queue' => fn () => eligibleResponse([eligibleIssue(200, -200)], true),
    'GraphQL errors' => fn () => ['errors' => [['message' => 'Denied']]],
    'wrong viewer' => fn () => [
        'data' => [
            'viewer' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
            'team' => eligibleResponse([eligibleIssue(200, -200)])['data']['team'],
        ],
    ],
    'duplicate identity' => fn () => eligibleResponse([
        eligibleIssue(200, -200),
        eligibleIssue(200, -100),
    ]),
    'invalid sort order' => fn () => eligibleResponse([eligibleIssue(200, -200, ['sortOrder' => 'first'])]),
    'wrong team' => fn () => eligibleResponse([eligibleIssue(200, -200, ['team' => [
        'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'states' => ['nodes' => []],
    ]])]),
]);

it('holds an issue with an incomplete nested collection and selects the next candidate', function () {
    fakeEligibleResponse(eligibleResponse([
        eligibleIssue(200, -300, ['labels' => [
            'nodes' => [['name' => 'Feature']],
            'pageInfo' => ['hasNextPage' => true],
        ]]),
        eligibleIssue(201, -200),
    ]));

    CommanderServer::tool(GetNextEligibleIssue::class, ['id' => 'orbit'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('issue.key', 'ORB-201')
            ->etc());
});

it('registers the selection tool as read only with explicit schemas', function () {
    $tool = app(GetNextEligibleIssue::class)->toArray();

    expect($tool['annotations'])->toMatchArray(['readOnlyHint' => true])
        ->and($tool['inputSchema']['properties'])->toHaveKey('id')
        ->and($tool['inputSchema']['required'])->toContain('id')
        ->and($tool['outputSchema']['properties'])->toHaveKeys(['project_id', 'capacity', 'issue'])
        ->and($tool['outputSchema']['required'])->toContain('project_id', 'capacity', 'issue');
});
