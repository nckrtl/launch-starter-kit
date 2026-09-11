<?php

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
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
    config()->set('commander.hermes.nick_linear_user_id', '691cb14c-60d5-415a-a5c7-a7c19fe83424');
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

it('matches the installed controller contract for equivalent issue collections', function () {
    $factory = app(OrbitIssueSnapshotFactory::class);
    $baseline = $factory->make(providerResponse(), providerIssueId(), 'ORB-234', providerViewerId());
    $equivalent = $factory->make(providerResponse([
        'url' => 'https://linear.app/orbit/issue/ORB-234/current',
        'updatedAt' => '2026-09-11T12:00:00.000Z',
        'state' => [
            'id' => '44444444-5555-4666-8777-888888888888',
            'name' => 'In Progress',
            'type' => 'started',
        ],
        'labels' => [
            'nodes' => [
                ['name' => 'apps:cli'],
                ['name' => 'incus'],
                ['name' => 'apps:cli'],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
        'attachments' => [
            'nodes' => [
                ['title' => 'Alpha', 'url' => 'https://example.test/alpha'],
                ['title' => 'Zeta', 'url' => 'https://example.test/zeta'],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ],
        'inverseRelations' => [
            'nodes' => [[
                'type' => 'blocks',
                'issue' => ['identifier' => 'ORB-9', 'state' => ['type' => 'completed']],
            ]],
            'pageInfo' => ['hasNextPage' => false],
        ],
    ]), providerIssueId(), 'ORB-234', providerViewerId());

    $substring = $factory->make(providerResponse([
        'labels' => [
            'nodes' => [['name' => 'scope:proof:incus:required']],
            'pageInfo' => ['hasNextPage' => false],
        ],
    ]), providerIssueId(), 'ORB-234', providerViewerId());
    $normalizedSubstring = $factory->make(providerResponse([
        'labels' => [
            'nodes' => [['name' => 'scope:incus:required']],
            'pageInfo' => ['hasNextPage' => false],
        ],
    ]), providerIssueId(), 'ORB-234', providerViewerId());

    expect($equivalent->contractHash)->toBe($baseline->contractHash)
        ->and($substring->contractHash)->toBe($normalizedSubstring->contractHash);
});

it('changes the controller contract hash for planning-relevant issue fields', function (array $overrides) {
    $factory = app(OrbitIssueSnapshotFactory::class);
    $baseline = $factory->make(providerResponse(), providerIssueId(), 'ORB-234', providerViewerId());
    $changed = $factory->make(providerResponse($overrides), providerIssueId(), 'ORB-234', providerViewerId());

    expect($changed->contractHash)->not->toBe($baseline->contractHash);
})->with([
    'title' => [['title' => 'A changed title']],
    'description' => [['description' => "## Outcome\n\nA changed outcome."]],
    'labels' => [['labels' => [
        'nodes' => [['name' => 'apps:gateway']],
        'pageInfo' => ['hasNextPage' => false],
    ]]],
    'attachments' => [['attachments' => [
        'nodes' => [['title' => 'Changed', 'url' => 'https://example.test/changed']],
        'pageInfo' => ['hasNextPage' => false],
    ]]],
]);

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

it('reads an active In Review issue with the exact temporary PR-author assignment', function () {
    fakeProviderResponse(providerResponse([
        'state' => [
            'id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
            'name' => 'In Review',
            'type' => 'started',
        ],
        'assignee' => ['id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424'],
    ]));

    $snapshot = app(OrbitActiveIssueProvider::class)->fetchActive(providerIssueId(), 'ORB-234');

    expect($snapshot->payload['state']['name'])->toBe('In Review')
        ->and($snapshot->payload['assignee'])->toBe([
            'id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424',
        ]);
});

it('keeps new-delivery issue reads restricted while active reads reject unexpected ownership', function () {
    $inReview = [
        'state' => [
            'id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
            'name' => 'In Review',
            'type' => 'started',
        ],
        'assignee' => ['id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424'],
    ];
    fakeProviderResponse(providerResponse($inReview));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'not eligible');

    fakeProviderResponse(providerResponse([
        ...$inReview,
        'assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
    ]));

    expect(fn () => app(OrbitActiveIssueProvider::class)->fetchActive(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'active Orbit issue');
});

it('reads only known In Review or Done ownership through the closeout boundary', function (array $overrides) {
    fakeProviderResponse(providerResponse($overrides));

    $snapshot = app(OrbitCloseoutIssueProvider::class)->fetchForCloseout(providerIssueId(), 'ORB-234');

    expect($snapshot->payload['state']['name'])->toBeIn(['In Review', 'Done']);
})->with([
    'active landing ownership' => [[
        'state' => [
            'id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
            'name' => 'In Review',
            'type' => 'started',
        ],
    ]],
    'completed and cleared' => [[
        'state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ],
        'delegate' => null,
    ]],
    'known partial completion ownership' => [[
        'state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ],
        'assignee' => ['id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424'],
    ]],
]);

it('does not broaden new-delivery reads to completed issues', function () {
    fakeProviderResponse(providerResponse([
        'state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ],
        'delegate' => null,
    ]));

    expect(fn () => app(SshOrbitIssueProvider::class)->fetch(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'not eligible');
});

it('rejects unexpected closeout state or ownership', function (array $overrides) {
    fakeProviderResponse(providerResponse($overrides));

    expect(fn () => app(OrbitCloseoutIssueProvider::class)->fetchForCloseout(providerIssueId(), 'ORB-234'))
        ->toThrow(OrbitIssueProviderFailed::class, 'closeout issue');
})->with([
    'wrong state' => [[
        'state' => [
            'id' => '44444444-5555-4666-8777-888888888888',
            'name' => 'In Progress',
            'type' => 'started',
        ],
    ]],
    'unexpected delegate' => [[
        'state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ],
        'delegate' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
    ]],
    'unexpected assignee' => [[
        'state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'completed',
        ],
        'assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
    ]],
]);

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
