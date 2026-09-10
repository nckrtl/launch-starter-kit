<?php

use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use Illuminate\Support\Facades\Process;

function providerIssueId(): string
{
    return '11111111-2222-4333-8444-555555555555';
}

function providerViewerId(): string
{
    return '4fa61558-9052-45f7-8a7c-49e0b891d4bf';
}

/** @return array<string, mixed> */
function providerIssue(array $overrides = []): array
{
    return [
        'id' => providerIssueId(),
        'identifier' => 'ORB-234',
        'title' => 'Build the delivery boundary',
        'url' => 'https://linear.app/orbit/issue/ORB-234',
        'description' => "## Outcome\n\nDeliver it.",
        'updatedAt' => '2026-09-11T09:00:00.000Z',
        'state' => [
            'id' => '22222222-3333-4444-8555-666666666666',
            'name' => 'Todo',
            'type' => 'unstarted',
        ],
        'assignee' => null,
        'delegate' => ['id' => providerViewerId()],
        'team' => [
            'id' => '33333333-4444-4555-8666-777777777777',
            'states' => ['nodes' => [
                ['id' => '55555555-6666-4777-8888-999999999999', 'name' => 'Todo'],
                ['id' => '44444444-5555-4666-8777-888888888888', 'name' => 'In Progress'],
            ]],
        ],
        'labels' => [
            'nodes' => [
                ['name' => 'proof:incus'],
                ['name' => 'apps:cli'],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
        'attachments' => [
            'nodes' => [
                ['title' => 'Zeta', 'url' => 'https://example.test/zeta'],
                ['title' => 'Alpha', 'url' => 'https://example.test/alpha'],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => [
            'nodes' => [
                ['type' => 'related', 'issue' => ['identifier' => 'ORB-8', 'state' => ['type' => 'started']]],
                ['type' => 'blocks', 'issue' => ['identifier' => 'ORB-7', 'state' => ['type' => 'completed']]],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function providerResponse(array $issueOverrides = [], ?string $viewerId = null): array
{
    return ['data' => [
        'viewer' => ['id' => $viewerId ?? providerViewerId()],
        'issue' => providerIssue($issueOverrides),
    ]];
}

function fakeProviderResponse(array $response): void
{
    Process::fake(['*' => Process::result(output: json_encode($response, JSON_THROW_ON_ERROR))])
        ->preventStrayProcesses();
}

beforeEach(function () {
    config()->set('commander.hermes.ssh_target', 'tom@mini');
    config()->set('commander.hermes.profiles.tom', '/Users/tom/.hermes/profiles/tom');
    config()->set('commander.hermes.tom_linear_viewer_id', providerViewerId());
});

it('fetches one normalized issue through the fixed read-only Hermes RPC boundary', function () {
    fakeProviderResponse(providerResponse());

    $snapshot = app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234');

    expect($snapshot->issueId)->toBe(providerIssueId())
        ->and($snapshot->issueKey)->toBe('ORB-234')
        ->and($snapshot->payload['labels']['nodes'])->toBe([
            ['name' => 'apps:cli'],
            ['name' => 'proof:incus'],
        ])
        ->and($snapshot->payload['attachments']['nodes'])->toBe([
            ['title' => 'Alpha', 'url' => 'https://example.test/alpha'],
            ['title' => 'Zeta', 'url' => 'https://example.test/zeta'],
        ])
        ->and($snapshot->contractHash)->toBe('602a5f869b36c0bd223360d68fa4c7e15be4688acefc178ab200f123ea45ff06');

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
            && ($input['variables'] ?? null) === ['id' => providerIssueId()]
            && is_string($input['document'] ?? null)
            && str_starts_with(trim($input['document']), 'query LoopIssue')
            && ! str_contains($input['document'], 'mutation');
    });
});

it('rejects an issue response with different stable identity', function (array $overrides) {
    fakeProviderResponse(providerResponse($overrides));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'does not match the requested Orbit issue');
})->with([
    'different UUID' => [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']],
    'different key' => [['identifier' => 'ORB-235']],
]);

it('requires the configured Tom viewer and sole delegation', function (array $overrides, ?string $viewerId = null) {
    fakeProviderResponse(providerResponse($overrides, $viewerId));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class);
})->with([
    'different viewer' => [[], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
    'missing delegate' => [['delegate' => null]],
    'different delegate' => [['delegate' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]],
    'assignee present' => [['assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]],
]);

it('requires Todo or In Progress state', function () {
    fakeProviderResponse(providerResponse(['state' => [
        'id' => '22222222-3333-4444-8555-666666666666',
        'name' => 'Backlog',
        'type' => 'backlog',
    ]]));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'The Orbit issue is not eligible');
});

it('rejects a readiness hold', function () {
    fakeProviderResponse(providerResponse(['description' => "## Outcome\n\nNo.\n\n## Readiness\n\nDecision needed."]));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'The Orbit issue is not eligible');
});

it('requires complete child and relation pages without children', function (array $overrides) {
    fakeProviderResponse(providerResponse($overrides));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'The Orbit issue is not eligible');
})->with([
    'child present' => [['children' => [
        'nodes' => [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']],
        'pageInfo' => ['hasNextPage' => false],
    ]]],
    'more children' => [['children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]],
    'more relations' => [['inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]],
    'more labels' => [['labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]],
    'more attachments' => [['attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true]]]],
]);

it('rejects an unfinished blocking relation', function () {
    fakeProviderResponse(providerResponse(['inverseRelations' => [
        'nodes' => [[
            'type' => 'blocks',
            'issue' => ['identifier' => 'ORB-7', 'state' => ['type' => 'started']],
        ]],
        'pageInfo' => ['hasNextPage' => false],
    ]]));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'The Orbit issue is not eligible');
});

it('normalizes failed and thrown SSH processes', function (bool $throws) {
    if ($throws) {
        Process::fake(fn () => throw new RuntimeException('SSH unavailable'))->preventStrayProcesses();
    } else {
        Process::fake(['*' => Process::result(errorOutput: 'denied', exitCode: 1)])->preventStrayProcesses();
    }

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(
            OrbitIssueProviderFailed::class,
            $throws ? 'could not run' : 'provider failed',
        );
})->with([
    'failed process' => [false],
    'thrown process' => [true],
]);

it('rejects invalid JSON and malformed GraphQL responses', function (string $output) {
    Process::fake(['*' => Process::result(output: $output)])->preventStrayProcesses();

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class);
})->with([
    'invalid JSON' => ['not-json'],
    'missing data' => ['{"unexpected":true}'],
    'GraphQL errors' => [json_encode([
        ...providerResponse(),
        'errors' => [['message' => 'denied']],
    ], JSON_THROW_ON_ERROR)],
]);

it('rejects unsafe provider configuration and invalid identifiers before SSH', function (string $issueId, string $issueKey) {
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch($issueId, $issueKey))
        ->toThrow(OrbitIssueProviderFailed::class, 'not configured');

    Process::assertNothingRan();
})->with([
    'invalid UUID' => ['not-a-uuid', 'ORB-234'],
    'wrong project key' => [providerIssueId(), 'ABC-234'],
]);
