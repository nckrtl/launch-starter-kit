<?php

use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Contracts\OrbitPullRequestPublisher;
use App\Delivery\Exceptions\OrbitPullRequestPublicationFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config()->set('commander.hermes.ssh_target', 'nckrtl@10.44.0.9');
    config()->set('commander.hermes.profiles.tom', '/Users/nckrtl/.hermes/profiles/tom');
    $this->candidate = str_repeat('a', 40);
    $this->body = implode("\n", [
        'Issue: ORB-234',
        'Candidate: '.$this->candidate,
        'Artifact: '.str_repeat('b', 40),
        'Flow: discovery',
    ]);
    Sleep::fake();
});

afterEach(fn () => Sleep::fake(false));

/** @return array<string, mixed> */
function orbitPullRequestDetails(
    string $candidate,
    string $body,
    ?bool $mergeable = true,
    array $overrides = [],
): array {
    return [
        'number' => 42,
        'html_url' => 'https://github.com/nckrtl/orbit/pull/42',
        'state' => 'open',
        'head' => ['sha' => $candidate, 'ref' => 'orb-234', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main', 'repo' => ['full_name' => 'nckrtl/orbit']],
        'user' => ['login' => 'nckrtl'],
        'body' => $body,
        'draft' => false,
        'mergeable' => $mergeable,
        ...$overrides,
    ];
}
/**
 * @param  list<array{path: string, method?: string, body?: array<string, mixed>, output?: mixed, exit?: int}>  $responses
 */
function fakeOrbitPullRequestPublisher(array $responses): void
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
            ->and($input)->toBe([
                'service' => 'github',
                'path' => 'repos/nckrtl/orbit/'.$expected['path'],
                ...(isset($expected['method']) ? ['method' => $expected['method']] : []),
                ...(array_key_exists('body', $expected) ? ['body' => $expected['body']] : []),
            ]);

        $output = $expected['output'] ?? [];

        return Process::result(
            output: is_string($output) ? $output : json_encode($output, JSON_THROW_ON_ERROR),
            exitCode: $expected['exit'] ?? 0,
        );
    })->preventStrayProcesses();

}

it('creates and reads back one exact Orbit pull request', function () {
    fakeOrbitPullRequestPublisher([
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => []],
        [
            'path' => 'pulls',
            'method' => 'POST',
            'body' => [
                'title' => 'ORB-234: Build the feature',
                'head' => 'orb-234',
                'base' => 'main',
                'body' => $this->body,
                'draft' => false,
            ],
            'output' => ['number' => 42],
        ],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body)],
    ]);

    $published = app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    );

    expect($published->number)->toBe(42)
        ->and($published->url)->toBe('https://github.com/nckrtl/orbit/pull/42')
        ->and($published->candidateSha)->toBe($this->candidate)
        ->and($published->bodyHash)->toBe(hash('sha256', $this->body))
        ->and($published->mergeable)->toBeTrue();
    Process::assertRanTimes(fn () => true, 3);
});

it('inspects one exact pull request without mutation or mergeability polling', function () {
    fakeOrbitPullRequestPublisher([[
        'path' => 'pulls/42',
        'output' => orbitPullRequestDetails($this->candidate, $this->body, null),
    ]]);

    $inspected = app(OrbitPullRequestInspector::class)->inspect(
        42,
        'ORB-234',
        $this->candidate,
        $this->body,
    );

    expect($inspected->number)->toBe(42)
        ->and($inspected->candidateSha)->toBe($this->candidate)
        ->and($inspected->mergeable)->toBeNull();
    Process::assertRanTimes(fn () => true, 1);
    Sleep::assertNeverSlept();
});

it('updates an existing pull request and accepts a lost patch response only after exact read-back', function () {
    fakeOrbitPullRequestPublisher([
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => 'Old body']],
        ],
        ['path' => 'pulls/42', 'method' => 'PATCH', 'body' => ['body' => $this->body], 'exit' => 1],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body)],
    ]);

    $published = app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    );

    expect($published->number)->toBe(42)->and($published->mergeable)->toBeTrue();
});

it('recovers an uncertain creation through exact branch read-back', function () {
    fakeOrbitPullRequestPublisher([
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => []],
        [
            'path' => 'pulls',
            'method' => 'POST',
            'body' => [
                'title' => 'ORB-234: Build the feature',
                'head' => 'orb-234',
                'base' => 'main',
                'body' => $this->body,
                'draft' => false,
            ],
            'exit' => 1,
        ],
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => $this->body]],
        ],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body)],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body)],
    ]);

    $published = app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    );

    expect($published->number)->toBe(42);
});

it('rejects an unresolved pull request creation outcome', function () {
    fakeOrbitPullRequestPublisher([
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => []],
        [
            'path' => 'pulls',
            'method' => 'POST',
            'body' => [
                'title' => 'ORB-234: Build the feature',
                'head' => 'orb-234',
                'base' => 'main',
                'body' => $this->body,
                'draft' => false,
            ],
            'exit' => 1,
        ],
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => []],
    ]);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, 'creation outcome is unresolved');
});

it('polls boundedly while GitHub computes mergeability', function () {
    fakeOrbitPullRequestPublisher([
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => $this->body]],
        ],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body, null)],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body, null)],
        ['path' => 'pulls/42', 'output' => orbitPullRequestDetails($this->candidate, $this->body, true)],
    ]);

    $published = app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    );

    expect($published->mergeable)->toBeTrue();
    Sleep::assertSleptTimes(2);
});

it('returns unresolved mergeability after four bounded read-back retries', function () {
    $responses = [[
        'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
        'output' => [['number' => 42, 'body' => $this->body]],
    ]];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $responses[] = [
            'path' => 'pulls/42',
            'output' => orbitPullRequestDetails($this->candidate, $this->body, null),
        ];
    }

    fakeOrbitPullRequestPublisher($responses);

    $published = app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    );

    expect($published->mergeable)->toBeNull();
    Sleep::assertSleptTimes(4);
});

it('rejects multiple pull requests for the exact issue branch', function () {
    fakeOrbitPullRequestPublisher([[
        'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
        'output' => [['number' => 41], ['number' => 42]],
    ]]);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, 'Multiple pull requests');
});

it('rejects malformed pull request publisher responses', function (array $responses, string $message) {
    fakeOrbitPullRequestPublisher($responses);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, $message);
})->with([
    'failed process' => [[
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'exit' => 1],
    ], 'publisher failed'],
    'invalid JSON' => [[
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => '{'],
    ], 'returned invalid JSON'],
    'invalid list schema' => [[
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => ['number' => 42]],
    ], 'invalid Orbit pull request list'],
    'invalid list item schema' => [[
        ['path' => 'pulls?state=open&head=nckrtl:orb-234&base=main', 'output' => [[42]]],
    ], 'invalid Orbit pull request list'],
]);

it('rejects an invalid pull request detail schema', function () {
    fakeOrbitPullRequestPublisher([
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => $this->body]],
        ],
        ['path' => 'pulls/42', 'output' => []],
    ]);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, 'invalid Orbit pull request details');
});

it('rejects invalid mergeability metadata', function () {
    fakeOrbitPullRequestPublisher([
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => $this->body]],
        ],
        [
            'path' => 'pulls/42',
            'output' => orbitPullRequestDetails($this->candidate, $this->body, true, ['mergeable' => 'yes']),
        ],
    ]);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, 'invalid Orbit mergeability metadata');
});

it('rejects pull request read-back that differs from the implementation handoff', function (
    array $overrides,
) {
    fakeOrbitPullRequestPublisher([
        [
            'path' => 'pulls?state=open&head=nckrtl:orb-234&base=main',
            'output' => [['number' => 42, 'body' => $this->body]],
        ],
        [
            'path' => 'pulls/42',
            'output' => orbitPullRequestDetails($this->candidate, $this->body, true, $overrides),
        ],
    ]);

    expect(fn () => app(OrbitPullRequestPublisher::class)->publish(
        'ORB-234',
        'Build the feature',
        $this->candidate,
        $this->body,
    ))->toThrow(OrbitPullRequestPublicationFailed::class, 'read-back differs');
})->with([
    'head' => [['head' => ['sha' => str_repeat('0', 40)]]],
    'head branch' => [['head' => [
        'sha' => str_repeat('a', 40),
        'ref' => 'other',
        'repo' => ['full_name' => 'nckrtl/orbit'],
    ]]],
    'head repository' => [['head' => [
        'sha' => str_repeat('a', 40),
        'ref' => 'orb-234',
        'repo' => ['full_name' => 'other/orbit'],
    ]]],
    'state' => [['state' => 'closed']],
    'base' => [['base' => ['ref' => 'develop']]],
    'base repository' => [['base' => [
        'ref' => 'main',
        'repo' => ['full_name' => 'other/orbit'],
    ]]],
    'owner' => [['user' => ['login' => 'other']]],
    'body' => [['body' => 'Changed body']],
    'draft' => [['draft' => true]],
    'url' => [['html_url' => 'https://github.com/nckrtl/orbit/pull/99']],
]);
