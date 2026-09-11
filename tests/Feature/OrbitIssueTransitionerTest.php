<?php

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Contracts\OrbitIssueCompletionTransitioner;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Contracts\OrbitReviewIssueTransitioner;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use Illuminate\Support\Facades\Process;

final class TransitionReadBackIssueProvider implements OrbitActiveIssueProvider, OrbitCloseoutIssueProvider, OrbitIssueProvider
{
    /** @var list<array{issue_id: string, issue_key: string}> */
    public array $requests = [];

    public ?OrbitIssueProviderFailed $failure = null;

    public function __construct(public OrbitIssueSnapshot $snapshot) {}

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $this->requests[] = ['issue_id' => $issueId, 'issue_key' => $issueKey];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->snapshot;
    }

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        return $this->fetch($issueId, $issueKey);
    }

    public function fetchForCloseout(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        return $this->fetch($issueId, $issueKey);
    }
}

beforeEach(function () {
    config()->set('commander.hermes.ssh_target', 'tom@mini');
    config()->set('commander.hermes.profiles.tom', '/Users/tom/.hermes/profiles/tom');
    config()->set('commander.hermes.tom_linear_viewer_id', transitionViewerId());
    config()->set('commander.hermes.nick_linear_user_id', transitionNickId());
    $this->contractHash = str_repeat('a', 64);
});

function transitionIssueId(): string
{
    return '11111111-2222-4333-8444-555555555555';
}

function transitionViewerId(): string
{
    return '4fa61558-9052-45f7-8a7c-49e0b891d4bf';
}

function transitionNickId(): string
{
    return '691cb14c-60d5-415a-a5c7-a7c19fe83424';
}

/** @param array<string, mixed> $overrides */
function transitionSnapshot(string $state = 'Todo', ?string $contractHash = null, array $overrides = []): OrbitIssueSnapshot
{
    $payload = [
        'id' => transitionIssueId(),
        'identifier' => 'ORB-234',
        'title' => 'Move this issue',
        'url' => 'https://linear.app/orbit/issue/ORB-234',
        'description' => "## Outcome\n\nMove it.",
        'updatedAt' => '2026-09-11T10:00:00.000Z',
        'state' => match ($state) {
            'In Progress' => [
                'id' => '44444444-5555-4666-8777-888888888888',
                'name' => 'In Progress',
                'type' => 'started',
            ],
            'In Review' => [
                'id' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
                'name' => 'In Review',
                'type' => 'started',
            ],
            'Done' => [
                'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
                'name' => 'Done',
                'type' => 'completed',
            ],
            default => [
                'id' => '55555555-6666-4777-8888-999999999999',
                'name' => 'Todo',
                'type' => 'unstarted',
            ],
        },
        'assignee' => null,
        'delegate' => ['id' => transitionViewerId()],
        'team' => [
            'id' => '33333333-4444-4555-8666-777777777777',
            'states' => ['nodes' => [
                ['id' => '55555555-6666-4777-8888-999999999999', 'name' => 'Todo'],
                ['id' => '44444444-5555-4666-8777-888888888888', 'name' => 'In Progress'],
                ['id' => '66666666-7777-4888-8999-aaaaaaaaaaaa', 'name' => 'In Review'],
                ['id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb', 'name' => 'Done'],
            ]],
        ],
        'labels' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        ...$overrides,
    ];

    return new OrbitIssueSnapshot(
        issueId: transitionIssueId(),
        issueKey: 'ORB-234',
        payload: $payload,
        contractHash: $contractHash ?? str_repeat('a', 64),
    );
}

function bindTransitionReadBack(OrbitIssueSnapshot $snapshot): TransitionReadBackIssueProvider
{
    $provider = new TransitionReadBackIssueProvider($snapshot);
    app()->instance(OrbitIssueProvider::class, $provider);
    app()->instance(OrbitActiveIssueProvider::class, $provider);
    app()->instance(OrbitCloseoutIssueProvider::class, $provider);

    return $provider;
}

/** @param array<string, mixed> $overrides */
function completedTransitionSnapshot(?string $contractHash = null, array $overrides = []): OrbitIssueSnapshot
{
    return transitionSnapshot('Done', $contractHash, [
        'assignee' => null,
        'delegate' => null,
        ...$overrides,
    ]);
}

it('moves an In Review issue to Done and clears both owners', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    $completed = completedTransitionSnapshot();
    Process::fake(function () use ($provider, $completed) {
        $provider->snapshot = $completed;

        return Process::result(output: '{"data":{"issueUpdate":{"success":true}}}');
    })->preventStrayProcesses();

    $result = app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    );

    expect($result)->toBe($completed)
        ->and($provider->requests)->toHaveCount(2);

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input) && ($input['variables'] ?? null) === [
            'id' => transitionIssueId(),
            'input' => [
                'stateId' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
                'assigneeId' => null,
                'delegateId' => null,
            ],
        ];
    });
});

it('does not mutate an issue already in the exact Done state with cleared ownership', function () {
    $completed = completedTransitionSnapshot();
    $provider = bindTransitionReadBack($completed);
    Process::fake()->preventStrayProcesses();

    $result = app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    );

    expect($result)->toBe($completed)
        ->and($provider->requests)->toHaveCount(1);
    Process::assertNothingRan();
});

it('reconciles a lost Done mutation response through exact completed read-back', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    $completed = completedTransitionSnapshot();
    Process::fake(function () use ($provider, $completed) {
        $provider->snapshot = $completed;

        throw new RuntimeException('SSH response lost');
    })->preventStrayProcesses();

    $result = app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    );

    expect($result)->toBe($completed)
        ->and($provider->requests)->toHaveCount(2);
});

it('repairs known partial Done ownership without changing the target state', function () {
    $provider = bindTransitionReadBack(completedTransitionSnapshot(overrides: [
        'delegate' => ['id' => transitionViewerId()],
        'assignee' => ['id' => transitionNickId()],
    ]));
    $completed = completedTransitionSnapshot();
    Process::fake(function () use ($provider, $completed) {
        $provider->snapshot = $completed;

        return Process::result(output: '{"data":{"issueUpdate":{"success":true}}}');
    })->preventStrayProcesses();

    app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    );

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return ($input['variables']['input'] ?? null) === [
            'stateId' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'assigneeId' => null,
            'delegateId' => null,
        ];
    });
});

it('requires exactly one valid Done team state before closing the issue', function (array $states) {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review', overrides: ['team' => [
        'id' => '33333333-4444-4555-8666-777777777777',
        'states' => ['nodes' => $states],
    ]]));
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    ))->toThrow(OrbitIssueTransitionFailed::class, 'exactly one valid Done state');

    expect($provider->requests)->toHaveCount(1);
    Process::assertNothingRan();
})->with([
    'missing' => [[['id' => '66666666-7777-4888-8999-aaaaaaaaaaaa', 'name' => 'In Review']]],
    'duplicate' => [[
        ['id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb', 'name' => 'Done'],
        ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'name' => 'Done'],
    ]],
    'invalid UUID' => [[['id' => 'not-a-uuid', 'name' => 'Done']]],
]);

it('refuses completion identity, contract, state, or ownership drift before mutation', function (string $case) {
    $current = match ($case) {
        'identity' => new OrbitIssueSnapshot(
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'ORB-235',
            transitionSnapshot('In Review')->payload,
            $this->contractHash,
        ),
        'contract' => transitionSnapshot('In Review', str_repeat('b', 64)),
        'state' => transitionSnapshot('In Progress'),
        'delegate' => transitionSnapshot('In Review', overrides: [
            'delegate' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ]),
        'assignee' => transitionSnapshot('In Review', overrides: [
            'assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ]),
    };
    $provider = bindTransitionReadBack($current);
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
        transitionIssueId(),
        'ORB-234',
        $this->contractHash,
    ))->toThrow(OrbitIssueTransitionFailed::class, 'completion input or Hermes configuration is invalid');

    expect($provider->requests)->toHaveCount(1);
    Process::assertNothingRan();
})->with(['identity', 'contract', 'state', 'delegate', 'assignee']);

it('reports a failed initial closeout read as non-ambiguous', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    $provider->failure = new OrbitIssueProviderFailed('Linear unavailable');
    Process::fake()->preventStrayProcesses();

    try {
        app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
            transitionIssueId(),
            'ORB-234',
            $this->contractHash,
        );
        test()->fail('The completion should require its initial Linear read.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->getMessage())->toContain('before its Linear closeout')
            ->and($exception->ambiguous)->toBeFalse()
            ->and($exception->getPrevious())->toBe($provider->failure);
    }

    Process::assertNothingRan();
});

it('reports a failed completion read-back as ambiguous without repeating the mutation', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    $readBackFailure = new OrbitIssueProviderFailed('Linear read-back unavailable');
    Process::fake(function () use ($provider, $readBackFailure) {
        $provider->failure = $readBackFailure;

        return Process::result(output: '{"data":{"issueUpdate":{"success":true}}}');
    })->preventStrayProcesses();

    try {
        app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
            transitionIssueId(),
            'ORB-234',
            $this->contractHash,
        );
        test()->fail('The completion should require an authoritative read-back.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->getMessage())->toContain('could not be verified')
            ->and($exception->ambiguous)->toBeTrue()
            ->and($exception->getPrevious())->toBe($readBackFailure);
    }

    Process::assertRanTimes(fn () => true, 1);
    expect($provider->requests)->toHaveCount(2);
});

it('rejects an invalid Done read-back as ambiguous', function (string $case) {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    $readBack = match ($case) {
        'state id' => completedTransitionSnapshot(overrides: ['state' => [
            'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'name' => 'Done',
            'type' => 'completed',
        ]]),
        'state name' => completedTransitionSnapshot(overrides: ['state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Closed',
            'type' => 'completed',
        ]]),
        'state type' => completedTransitionSnapshot(overrides: ['state' => [
            'id' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb',
            'name' => 'Done',
            'type' => 'started',
        ]]),
        'contract' => completedTransitionSnapshot(str_repeat('b', 64)),
        'delegate' => completedTransitionSnapshot(overrides: ['delegate' => ['id' => transitionViewerId()]]),
        'assignee' => completedTransitionSnapshot(overrides: ['assignee' => ['id' => transitionNickId()]]),
    };
    Process::fake(function () use ($provider, $readBack) {
        $provider->snapshot = $readBack;

        return Process::result(output: '{"data":{"issueUpdate":{"success":true}}}');
    })->preventStrayProcesses();

    try {
        app(OrbitIssueCompletionTransitioner::class)->transitionToDone(
            transitionIssueId(),
            'ORB-234',
            $this->contractHash,
        );
        test()->fail('The completion should not pass an invalid Linear read-back.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->getMessage())->toContain('did not confirm')
            ->and($exception->ambiguous)->toBeTrue();
    }
})->with(['state id', 'state name', 'state type', 'contract', 'delegate', 'assignee']);

it('moves an active issue to In Review and clears the temporary Nick assignment', function () {
    $current = transitionSnapshot('In Progress', overrides: [
        'assignee' => ['id' => transitionNickId()],
    ]);
    $readBack = transitionSnapshot('In Review');
    $provider = bindTransitionReadBack($readBack);
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    $result = app(OrbitReviewIssueTransitioner::class)->transitionToInReview(
        $current,
        $this->contractHash,
    );

    expect($result)->toBe($readBack)
        ->and($provider->requests)->toHaveCount(1);

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input) && ($input['variables'] ?? null) === [
            'id' => transitionIssueId(),
            'input' => [
                'stateId' => '66666666-7777-4888-8999-aaaaaaaaaaaa',
                'assigneeId' => null,
            ],
        ];
    });
});

it('reconciles a lost In Review mutation response through exact active read-back', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    Process::fake(fn () => throw new RuntimeException('SSH response lost'))
        ->preventStrayProcesses();

    $result = app(OrbitReviewIssueTransitioner::class)->transitionToInReview(
        transitionSnapshot('In Progress'),
        $this->contractHash,
    );

    expect($result->payload['state']['name'])->toBe('In Review')
        ->and($result->payload['assignee'])->toBeNull()
        ->and($provider->requests)->toHaveCount(1);
});

it('repairs a Nick assignment without repeating an already completed In Review transition', function () {
    $current = transitionSnapshot('In Review', overrides: [
        'assignee' => ['id' => transitionNickId()],
    ]);
    bindTransitionReadBack(transitionSnapshot('In Review'));
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    app(OrbitReviewIssueTransitioner::class)->transitionToInReview($current, $this->contractHash);

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input)
            && ($input['variables']['input']['stateId'] ?? null) === '66666666-7777-4888-8999-aaaaaaaaaaaa'
            && array_key_exists('assigneeId', $input['variables']['input'] ?? [])
            && $input['variables']['input']['assigneeId'] === null;
    });
});

it('does not mutate an issue already in the exact In Review state and ownership', function () {
    $current = transitionSnapshot('In Review');
    $provider = bindTransitionReadBack($current);
    Process::fake()->preventStrayProcesses();

    $result = app(OrbitReviewIssueTransitioner::class)->transitionToInReview(
        $current,
        $this->contractHash,
    );

    expect($result)->toBe($current)
        ->and($provider->requests)->toHaveCount(1);
    Process::assertNothingRan();
});

it('refuses unexpected active issue ownership before an In Review mutation', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Review'));
    Process::fake()->preventStrayProcesses();
    $current = transitionSnapshot('In Progress', overrides: [
        'assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
    ]);

    expect(fn () => app(OrbitReviewIssueTransitioner::class)->transitionToInReview(
        $current,
        $this->contractHash,
    ))->toThrow(OrbitIssueTransitionFailed::class, 'input or Hermes configuration is invalid');

    expect($provider->requests)->toBe([]);
    Process::assertNothingRan();
});

it('transitions Todo to the exact In Progress state through Hermes and verifies Linear read-back', function () {
    $current = transitionSnapshot();
    $readBack = transitionSnapshot('In Progress');
    $provider = bindTransitionReadBack($readBack);
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    $result = app(OrbitIssueTransitioner::class)->transitionToInProgress($current, $this->contractHash);

    expect($result)->toBe($readBack)
        ->and($provider->requests)->toBe([[
            'issue_id' => transitionIssueId(),
            'issue_key' => 'ORB-234',
        ]]);

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
            && ($input['variables'] ?? null) === [
                'id' => transitionIssueId(),
                'input' => ['stateId' => '44444444-5555-4666-8777-888888888888'],
            ]
            && is_string($input['document'] ?? null)
            && str_starts_with(trim($input['document']), 'mutation LoopState')
            && ! str_contains($input['document'], 'query');
    });
});

it('returns an issue from In Review to In Progress before a correction', function () {
    $current = transitionSnapshot('In Review');
    $readBack = transitionSnapshot('In Progress');
    $provider = bindTransitionReadBack($readBack);
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    $result = app(OrbitIssueTransitioner::class)->transitionToInProgress(
        $current,
        $this->contractHash,
    );

    expect($result)->toBe($readBack)
        ->and($provider->requests)->toHaveCount(1);

    Process::assertRan(function ($process): bool {
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        return is_array($input)
            && ($input['variables']['input'] ?? null) === [
                'stateId' => '44444444-5555-4666-8777-888888888888',
            ];
    });
});

it('reconciles a lost mutation response when the exact Linear read-back succeeded', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Progress'));
    $attempts = 0;
    Process::fake(function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('SSH response lost');
    })->preventStrayProcesses();

    $result = app(OrbitIssueTransitioner::class)->transitionToInProgress(
        transitionSnapshot(),
        $this->contractHash,
    );

    expect($result->payload['state']['name'])->toBe('In Progress')
        ->and($provider->requests)->toHaveCount(1)
        ->and($attempts)->toBe(1);
});

it('does not repeat the mutation for an issue already in the exact In Progress state', function () {
    $current = transitionSnapshot('In Progress');
    $provider = bindTransitionReadBack($current);
    Process::fake()->preventStrayProcesses();

    $result = app(OrbitIssueTransitioner::class)->transitionToInProgress($current, $this->contractHash);

    expect($result)->toBe($current)
        ->and($provider->requests)->toHaveCount(1);
    Process::assertNothingRan();
});

it('refuses contract or ownership drift before mutating Linear', function (string $case) {
    $current = match ($case) {
        'contract' => transitionSnapshot(contractHash: str_repeat('b', 64)),
        'delegate' => transitionSnapshot(overrides: ['delegate' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]),
        'assignee' => transitionSnapshot(overrides: ['assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']]),
    };
    $provider = bindTransitionReadBack(transitionSnapshot('In Progress'));
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(OrbitIssueTransitioner::class)->transitionToInProgress($current, $this->contractHash))
        ->toThrow(OrbitIssueTransitionFailed::class, 'input or Hermes configuration is invalid');

    expect($provider->requests)->toBe([]);
    Process::assertNothingRan();
})->with(['contract', 'delegate', 'assignee']);

it('requires exactly one valid In Progress team state before mutating Linear', function (array $states) {
    $current = transitionSnapshot(overrides: ['team' => [
        'id' => '33333333-4444-4555-8666-777777777777',
        'states' => ['nodes' => $states],
    ]]);
    $provider = bindTransitionReadBack(transitionSnapshot('In Progress'));
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(OrbitIssueTransitioner::class)->transitionToInProgress($current, $this->contractHash))
        ->toThrow(OrbitIssueTransitionFailed::class, 'exactly one valid In Progress state');

    expect($provider->requests)->toBe([]);
    Process::assertNothingRan();
})->with([
    'missing' => [[['id' => '55555555-6666-4777-8888-999999999999', 'name' => 'Todo']]],
    'duplicate' => [[
        ['id' => '44444444-5555-4666-8777-888888888888', 'name' => 'In Progress'],
        ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'name' => 'In Progress'],
    ]],
    'invalid UUID' => [[['id' => 'not-a-uuid', 'name' => 'In Progress']]],
]);

it('reports an ambiguous transition when mutation was attempted but read-back does not prove it', function (string $case) {
    $readBack = match ($case) {
        'state' => transitionSnapshot(),
        'state type' => transitionSnapshot('In Progress', overrides: ['state' => [
            'id' => '44444444-5555-4666-8777-888888888888',
            'name' => 'In Progress',
            'type' => 'unstarted',
        ]]),
        'contract' => transitionSnapshot('In Progress', str_repeat('b', 64)),
        'identity' => new OrbitIssueSnapshot(
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'ORB-235',
            transitionSnapshot('In Progress')->payload,
            $this->contractHash,
        ),
        'delegate' => transitionSnapshot('In Progress', overrides: [
            'delegate' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ]),
        'assignee' => transitionSnapshot('In Progress', overrides: [
            'assignee' => ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ]),
    };
    bindTransitionReadBack($readBack);
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    try {
        app(OrbitIssueTransitioner::class)->transitionToInProgress(transitionSnapshot(), $this->contractHash);
        test()->fail('The transition should not pass an invalid Linear read-back.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->getMessage())->toContain('did not confirm')
            ->and($exception->ambiguous)->toBeTrue();
    }
})->with(['state', 'state type', 'contract', 'identity', 'delegate', 'assignee']);

it('reports read-back failure after an attempted transition as ambiguous without retrying the mutation', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Progress'));
    $provider->failure = new OrbitIssueProviderFailed('Linear unavailable');
    Process::fake(['*' => Process::result(output: '{"data":{"issueUpdate":{"success":true}}}')])
        ->preventStrayProcesses();

    try {
        app(OrbitIssueTransitioner::class)->transitionToInProgress(transitionSnapshot(), $this->contractHash);
        test()->fail('The transition should require an authoritative read-back.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->getMessage())->toContain('could not be verified')
            ->and($exception->ambiguous)->toBeTrue();
    }

    Process::assertRanTimes(fn () => true, 1);
    expect($provider->requests)->toHaveCount(1);
});

it('preserves both mutation and read-back failures without retrying either boundary', function () {
    $provider = bindTransitionReadBack(transitionSnapshot('In Progress'));
    $providerFailure = new OrbitIssueProviderFailed('Linear read-back unavailable');
    $provider->failure = $providerFailure;
    $attempts = 0;
    Process::fake(function () use (&$attempts) {
        $attempts++;

        throw new RuntimeException('SSH mutation response lost');
    })->preventStrayProcesses();

    try {
        app(OrbitIssueTransitioner::class)->transitionToInProgress(transitionSnapshot(), $this->contractHash);
        test()->fail('The transition should preserve both boundary failures.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->ambiguous)->toBeTrue()
            ->and($exception->getPrevious())->toBe($providerFailure)
            ->and($exception->mutationFailure)->toBeInstanceOf(OrbitIssueTransitionFailed::class)
            ->and($attempts)->toBe(1);
    }

    expect($provider->requests)->toHaveCount(1);
});

it('marks a failed read-back without a mutation attempt as non-ambiguous', function () {
    $current = transitionSnapshot('In Progress');
    $provider = bindTransitionReadBack($current);
    $provider->failure = new OrbitIssueProviderFailed('Linear read-back unavailable');
    Process::fake()->preventStrayProcesses();

    try {
        app(OrbitIssueTransitioner::class)->transitionToInProgress($current, $this->contractHash);
        test()->fail('The transition should require a read-back even without a mutation.');
    } catch (OrbitIssueTransitionFailed $exception) {
        expect($exception->ambiguous)->toBeFalse()
            ->and($exception->getPrevious())->toBe($provider->failure)
            ->and($exception->mutationFailure)->toBeNull();
    }

    Process::assertNothingRan();
    expect($provider->requests)->toHaveCount(1);
});
