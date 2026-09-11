<?php

use App\Delivery\Contracts\OrbitPullRequestLandingGateway;
use App\Delivery\Exceptions\OrbitPullRequestLandingFailed;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('commander.hermes.ssh_target', 'nckrtl@10.44.0.9');
    config()->set('commander.hermes.profiles.tom', '/Users/nckrtl/.hermes/profiles/tom');
    $this->issueId = '11111111-2222-4333-8444-555555555555';
    $this->pullRequestUrl = 'https://github.com/nckrtl/orbit/pull/42';
    $this->candidate = str_repeat('a', 40);
    $this->merge = str_repeat('b', 40);
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidate,
        'Artifact: '.str_repeat('c', 40),
        'Flow: discovery',
    ]);
});

/**
 * @param  list<array{payload: array<string, mixed>, output?: mixed, exit?: int}>  $responses
 */
function fakeOrbitLandingGateway(array $responses): void
{
    Process::fake(function ($process) use (&$responses) {
        $expected = array_shift($responses);
        $input = is_string($process->input)
            ? json_decode($process->input, true, flags: JSON_THROW_ON_ERROR)
            : null;

        expect($expected)->not->toBeNull()
            ->and($process->command)->toBe([
                'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'nckrtl@10.44.0.9',
                "'/Users/nckrtl/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc",
            ])
            ->and($input)->toBe($expected['payload']);

        $output = $expected['output'] ?? [];

        return Process::result(
            output: is_string($output) ? $output : json_encode($output, JSON_THROW_ON_ERROR),
            exitCode: $expected['exit'] ?? 0,
        );
    })->preventStrayProcesses();
}

/** @return array{version: int, status: string, issue_id: ?string, pr_url: ?string, reserved_at: ?string} */
function orbitLandingReservation(
    string $status = 'idle',
    ?string $issueId = null,
    ?string $pullRequestUrl = null,
): array {
    return [
        'version' => 1,
        'status' => $status,
        'issue_id' => $issueId,
        'pr_url' => $pullRequestUrl,
        'reserved_at' => $status === 'active' ? '2026-09-11T12:00:00+00:00' : null,
    ];
}

/** @return array<string, mixed> */
function orbitLandingPullRequest(
    string $candidate,
    string $body,
    ?bool $mergeable = true,
    array $overrides = [],
): array {
    return [
        'number' => 42,
        'html_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'state' => 'open',
        'merged' => false,
        'head' => ['sha' => $candidate, 'ref' => 'orb-234', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'user' => ['login' => 'nckrtl'],
        'body' => $body,
        'draft' => false,
        'mergeable' => $mergeable,
        ...$overrides,
    ];
}

/** @return array{id: int, user: array{login: string}, commit_id: string, body: string, state: string} */
function orbitLandingReview(
    int $id,
    string $candidate,
    string $state = 'APPROVED',
    string $body = 'Approved.',
    string $login = 'tom-nckrtl[bot]',
): array {
    return [
        'id' => $id,
        'user' => ['login' => $login],
        'commit_id' => $candidate,
        'body' => $body,
        'state' => $state,
    ];
}

it('acquires and reads back the shared Orbit merge reservation', function () {
    fakeOrbitLandingGateway([
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'reserve',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
        ],
        [
            'payload' => ['service' => 'reservation', 'action' => 'status'],
            'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
        ],
    ]);

    $reservation = app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    );

    expect($reservation->owned)->toBeTrue()
        ->and($reservation->issueId)->toBe($this->issueId)
        ->and($reservation->pullRequestUrl)->toBe($this->pullRequestUrl)
        ->and($reservation->reservedAt)->toBe('2026-09-11T12:00:00+00:00');
    Process::assertRanTimes(fn () => true, 3);
});

it('reuses its existing shared Orbit merge reservation without another mutation', function () {
    fakeOrbitLandingGateway([[
        'payload' => ['service' => 'reservation', 'action' => 'status'],
        'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
    ]]);

    $reservation = app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    );

    expect($reservation->owned)->toBeTrue();
    Process::assertRanTimes(fn () => true, 1);
});

it('reports another delivery that owns the shared Orbit merge reservation', function () {
    $owner = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $url = 'https://github.com/nckrtl/orbit/pull/41';
    fakeOrbitLandingGateway([[
        'payload' => ['service' => 'reservation', 'action' => 'status'],
        'output' => orbitLandingReservation('active', $owner, $url),
    ]]);

    $reservation = app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    );

    expect($reservation->owned)->toBeFalse()
        ->and($reservation->issueId)->toBe($owner)
        ->and($reservation->pullRequestUrl)->toBe($url);
    Process::assertRanTimes(fn () => true, 1);
});

it('recovers a lost reservation response through authoritative read back', function () {
    fakeOrbitLandingGateway([
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'reserve',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'exit' => 1,
        ],
        [
            'payload' => ['service' => 'reservation', 'action' => 'status'],
            'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
        ],
    ]);

    $reservation = app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    );

    expect($reservation->owned)->toBeTrue();
});

it('reports a reservation race after a failed acquisition', function () {
    $owner = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $url = 'https://github.com/nckrtl/orbit/pull/41';
    fakeOrbitLandingGateway([
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'reserve',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'exit' => 1,
        ],
        [
            'payload' => ['service' => 'reservation', 'action' => 'status'],
            'output' => orbitLandingReservation('active', $owner, $url),
        ],
    ]);

    $reservation = app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    );

    expect($reservation->owned)->toBeFalse()
        ->and($reservation->issueId)->toBe($owner);
});

it('rejects an unresolved or malformed reservation outcome', function (string $failure) {
    $status = $failure === 'unresolved'
        ? orbitLandingReservation()
        : ['version' => 1, 'status' => 'active', 'issue_id' => 'ORB-234'];
    $responses = [
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'reserve',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'exit' => 1,
        ],
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => $status],
    ];
    fakeOrbitLandingGateway($responses);

    expect(fn () => app(OrbitPullRequestLandingGateway::class)->reserve(
        $this->issueId,
        $this->pullRequestUrl,
    ))->toThrow(OrbitPullRequestLandingFailed::class);
})->with(['unresolved', 'malformed']);

it('verifies the exact independent approval and main protection contract', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body),
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42/reviews?per_page=100'],
            'output' => [orbitLandingReview(901, $this->candidate)],
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/rules/branches/main'],
            'output' => [['type' => 'deletion'], ['type' => 'pull_request'], ['type' => 'non_fast_forward']],
        ],
    ]);

    $approved = app(OrbitPullRequestLandingGateway::class)->inspectApproved(
        42,
        'ORB-234',
        $this->candidate,
        $this->body,
        901,
    );

    expect($approved->number)->toBe(42)
        ->and($approved->url)->toBe($this->pullRequestUrl)
        ->and($approved->candidateSha)->toBe($this->candidate)
        ->and($approved->bodyHash)->toBe(hash('sha256', $this->body))
        ->and($approved->reviewId)->toBe(901)
        ->and($approved->reviewerLogin)->toBe('tom-nckrtl[bot]')
        ->and($approved->mergeable)->toBeTrue();
});

it('rejects a later changes requested review for the approved candidate', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body),
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42/reviews?per_page=100'],
            'output' => [
                orbitLandingReview(901, $this->candidate),
                orbitLandingReview(902, $this->candidate, 'CHANGES_REQUESTED', 'New finding.', 'nckrtl'),
            ],
        ],
    ]);

    expect(fn () => app(OrbitPullRequestLandingGateway::class)->inspectApproved(
        42,
        'ORB-234',
        $this->candidate,
        $this->body,
        901,
    ))->toThrow(OrbitPullRequestLandingFailed::class, 'later Orbit pull request review requests changes');

    Process::assertRanTimes(fn () => true, 2);
});

it('rejects an invalid approval or main protection contract', function (string $failure) {
    $reviews = $failure === 'approval'
        ? [orbitLandingReview(901, $this->candidate, 'COMMENTED')]
        : [orbitLandingReview(901, $this->candidate)];
    $responses = [
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body),
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42/reviews?per_page=100'],
            'output' => $reviews,
        ],
    ];

    if ($failure === 'protection') {
        $responses[] = [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/rules/branches/main'],
            'output' => [['type' => 'deletion']],
        ];
    }

    fakeOrbitLandingGateway($responses);

    expect(fn () => app(OrbitPullRequestLandingGateway::class)->inspectApproved(
        42,
        'ORB-234',
        $this->candidate,
        $this->body,
        901,
    ))->toThrow(OrbitPullRequestLandingFailed::class);
})->with(['approval', 'protection']);

it('merges only the exact approved head and verifies the merge commit by read back', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => [
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/pulls/42/merge',
                'method' => 'PUT',
                'body' => ['sha' => $this->candidate, 'merge_method' => 'merge'],
            ],
            'output' => ['merged' => true, 'sha' => $this->merge],
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body, overrides: [
                'state' => 'closed',
                'merged' => true,
                'merge_commit_sha' => $this->merge,
            ]),
        ],
    ]);

    $merged = app(OrbitPullRequestLandingGateway::class)->merge(42, $this->candidate);

    expect($merged->number)->toBe(42)
        ->and($merged->url)->toBe($this->pullRequestUrl)
        ->and($merged->candidateSha)->toBe($this->candidate)
        ->and($merged->mergeCommitSha)->toBe($this->merge);
});

it('recovers a lost merge response only through exact merged pull request read back', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => [
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/pulls/42/merge',
                'method' => 'PUT',
                'body' => ['sha' => $this->candidate, 'merge_method' => 'merge'],
            ],
            'exit' => 1,
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body, overrides: [
                'state' => 'closed',
                'merged' => true,
                'merge_commit_sha' => $this->merge,
            ]),
        ],
    ]);

    $merged = app(OrbitPullRequestLandingGateway::class)->merge(42, $this->candidate);

    expect($merged->mergeCommitSha)->toBe($this->merge);
});

it('keeps an unresolved merge behind its reservation', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => [
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/pulls/42/merge',
                'method' => 'PUT',
                'body' => ['sha' => $this->candidate, 'merge_method' => 'merge'],
            ],
            'exit' => 1,
        ],
        [
            'payload' => ['service' => 'github', 'path' => 'repos/nckrtl/orbit/pulls/42'],
            'output' => orbitLandingPullRequest($this->candidate, $this->body),
        ],
    ]);

    expect(fn () => app(OrbitPullRequestLandingGateway::class)->merge(
        42,
        $this->candidate,
    ))->toThrow(OrbitPullRequestLandingFailed::class, 'retain the merge reservation');
});

it('releases only its own reservation and verifies idle state', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => ['service' => 'reservation', 'action' => 'status'],
            'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
        ],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'release',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'output' => orbitLandingReservation(),
        ],
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
    ]);

    app(OrbitPullRequestLandingGateway::class)->release($this->issueId, $this->pullRequestUrl);

    Process::assertRanTimes(fn () => true, 3);
});

it('recovers a lost release response and treats an idle replay as complete', function () {
    fakeOrbitLandingGateway([
        [
            'payload' => ['service' => 'reservation', 'action' => 'status'],
            'output' => orbitLandingReservation('active', $this->issueId, $this->pullRequestUrl),
        ],
        [
            'payload' => [
                'service' => 'reservation',
                'action' => 'release',
                'issue' => $this->issueId,
                'pr' => $this->pullRequestUrl,
            ],
            'exit' => 1,
        ],
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
        ['payload' => ['service' => 'reservation', 'action' => 'status'], 'output' => orbitLandingReservation()],
    ]);

    $gateway = app(OrbitPullRequestLandingGateway::class);
    $gateway->release($this->issueId, $this->pullRequestUrl);
    $gateway->release($this->issueId, $this->pullRequestUrl);

    Process::assertRanTimes(fn () => true, 4);
});

it('rejects invalid landing inputs before starting a process', function () {
    Process::fake()->preventStrayProcesses();
    $gateway = app(OrbitPullRequestLandingGateway::class);

    expect(fn () => $gateway->reserve('ORB-234', $this->pullRequestUrl))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'reservation input is invalid')
        ->and(fn () => $gateway->inspectApproved(0, 'ORB-234', $this->candidate, $this->body, 901))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'inspection input is invalid')
        ->and(fn () => $gateway->merge(42, 'main'))
        ->toThrow(OrbitPullRequestLandingFailed::class, 'merge input is invalid');

    Process::assertNothingRan();
});
