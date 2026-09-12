<?php

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitResolutionPublicationFailed;
use App\Delivery\IssueProviders\SshOrbitResolutionPublisher;
use App\Jobs\AdvanceOrbitResolution;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->issueId = '11111111-2222-4333-8444-555555555555';
    $this->commentId = '22222222-3333-4444-8555-666666666666';
    $this->viewerId = config('commander.hermes.tom_linear_viewer_id');
    $this->issue = new OrbitIssueSnapshot(
        $this->issueId,
        'ORB-234',
        [
            'id' => $this->issueId,
            'identifier' => 'ORB-234',
            'state' => ['name' => 'In Review', 'type' => 'started'],
            'assignee' => null,
            'delegate' => ['id' => $this->viewerId],
        ],
        str_repeat('a', 64),
    );
});

it('recovers a lost resolution comment response through exact Linear read back', function () {
    $calls = 0;
    $expectedBody = "ORBIT-LOOP-RESOLUTION:25\n\nComplete proposal.\n\nCommander routing: Adopted for implementing; verification remains required.";

    Process::fake(function ($process) use (&$calls, $expectedBody) {
        $calls++;
        $input = json_decode((string) $process->input, true, flags: JSON_THROW_ON_ERROR);

        expect($process->command)->toBe([
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10',
            config('commander.hermes.ssh_target'),
            escapeshellarg(config('commander.hermes.profiles.tom').'/scripts/orbit_delivery_loop.py').' --rpc',
        ])->and($input['service'])->toBe('linear');

        if ($calls === 2) {
            expect($input['variables']['input'])->toBe([
                'issueId' => $this->issueId,
                'body' => $expectedBody,
            ]);

            return Process::result(errorOutput: 'connection lost', exitCode: 1);
        }

        $comments = $calls === 1 ? [] : [[
            'id' => $this->commentId,
            'body' => $expectedBody,
            'user' => ['id' => $this->viewerId],
        ]];

        return Process::result(output: json_encode([
            'data' => [
                'viewer' => ['id' => $this->viewerId],
                'issue' => [
                    'id' => $this->issueId,
                    'identifier' => 'ORB-234',
                    'state' => ['name' => 'In Review', 'type' => 'started'],
                    'assignee' => null,
                    'delegate' => ['id' => $this->viewerId],
                    'comments' => [
                        'nodes' => $comments,
                        'pageInfo' => ['hasNextPage' => false],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    })->preventStrayProcesses();

    $published = app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        true,
        'implementing',
    );

    expect($published->commentId)->toBe($this->commentId)
        ->and($published->marker)->toBe('ORBIT-LOOP-RESOLUTION:25')
        ->and($published->bodyHash)->toBe(hash('sha256', $expectedBody))
        ->and($calls)->toBe(3);
});

it('does not create a second copy of an existing exact resolution publication', function () {
    $expectedBody = "ORBIT-LOOP-RESOLUTION:25\n\nComplete proposal.\n\nCommander routing: Needs an explicit decision or recovery action.";

    Process::fake(function ($process) use ($expectedBody) {
        $input = json_decode((string) $process->input, true, flags: JSON_THROW_ON_ERROR);
        expect($input['document'])->toContain('query LoopResolutionComments');

        return Process::result(output: json_encode([
            'data' => [
                'viewer' => ['id' => $this->viewerId],
                'issue' => [
                    'id' => $this->issueId,
                    'identifier' => 'ORB-234',
                    'state' => ['name' => 'In Review', 'type' => 'started'],
                    'assignee' => null,
                    'delegate' => ['id' => $this->viewerId],
                    'comments' => [
                        'nodes' => [[
                            'id' => $this->commentId,
                            'body' => $expectedBody,
                            'user' => ['id' => $this->viewerId],
                        ]],
                        'pageInfo' => ['hasNextPage' => false],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    })->preventStrayProcesses();

    app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        false,
        'implementing',
    );

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('publishes a planning resolution while Linear remains in progress', function () {
    $expectedBody = "ORBIT-LOOP-RESOLUTION:25\n\nComplete proposal.\n\nCommander routing: Needs an explicit decision or recovery action.";
    $this->issue = new OrbitIssueSnapshot(
        $this->issueId,
        'ORB-234',
        [
            'id' => $this->issueId,
            'identifier' => 'ORB-234',
            'state' => ['name' => 'In Progress', 'type' => 'started'],
            'assignee' => null,
            'delegate' => ['id' => $this->viewerId],
        ],
        str_repeat('a', 64),
    );

    Process::fake(['*' => Process::result(output: json_encode([
        'data' => [
            'viewer' => ['id' => $this->viewerId],
            'issue' => [
                'id' => $this->issueId,
                'identifier' => 'ORB-234',
                'state' => ['name' => 'In Progress', 'type' => 'started'],
                'assignee' => null,
                'delegate' => ['id' => $this->viewerId],
                'comments' => [
                    'nodes' => [[
                        'id' => $this->commentId,
                        'body' => $expectedBody,
                        'user' => ['id' => $this->viewerId],
                    ]],
                    'pageInfo' => ['hasNextPage' => false],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR))])->preventStrayProcesses();

    $published = app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        false,
        'planning',
    );

    expect($published->commentId)->toBe($this->commentId);
    Process::assertRanTimes(fn ($process) => true, 1);
});

it('rejects a resolution whose Linear state does not match its origin', function (
    string $issueState,
    string $resumePhase,
) {
    $this->issue = new OrbitIssueSnapshot(
        $this->issueId,
        'ORB-234',
        [
            'id' => $this->issueId,
            'identifier' => 'ORB-234',
            'state' => ['name' => $issueState, 'type' => 'started'],
            'assignee' => null,
            'delegate' => ['id' => $this->viewerId],
        ],
        str_repeat('a', 64),
    );
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        false,
        $resumePhase,
    ))->toThrow(
        OrbitResolutionPublicationFailed::class,
        'The resolution publication input or Hermes configuration is invalid.',
    );

    Process::assertDidntRun(fn ($process) => true);
})->with([
    'planning resolution after an In Review transition' => ['In Review', 'planning'],
    'pull request resolution before an In Review transition' => ['In Progress', 'implementing'],
]);

it('rejects an unsupported resolution resume phase before publication', function () {
    Process::fake()->preventStrayProcesses();

    expect(fn () => app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        false,
        'landing',
    ))->toThrow(
        OrbitResolutionPublicationFailed::class,
        'The resolution resume phase is invalid.',
    );

    Process::assertDidntRun(fn ($process) => true);
});

it('rejects a same-dispatch marker with different publication content', function () {
    $existingBody = "ORBIT-LOOP-RESOLUTION:25\n\nComplete proposal.\n\nCommander routing: Needs an explicit decision or recovery action.";

    Process::fake(['*' => Process::result(output: json_encode([
        'data' => [
            'viewer' => ['id' => $this->viewerId],
            'issue' => [
                'id' => $this->issueId,
                'identifier' => 'ORB-234',
                'state' => ['name' => 'In Review', 'type' => 'started'],
                'assignee' => null,
                'delegate' => ['id' => $this->viewerId],
                'comments' => [
                    'nodes' => [[
                        'id' => $this->commentId,
                        'body' => $existingBody,
                        'user' => ['id' => $this->viewerId],
                    ]],
                    'pageInfo' => ['hasNextPage' => false],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR))])->preventStrayProcesses();

    expect(fn () => app(SshOrbitResolutionPublisher::class)->publish(
        $this->issue,
        25,
        'Complete proposal.',
        true,
        'implementing',
    ))->toThrow(
        OrbitResolutionPublicationFailed::class,
        'A resolution publication already exists for this dispatch with different content.',
    );

    Process::assertRanTimes(fn ($process) => true, 1);
});

it('bounds resolution publication below the queue retry window', function () {
    $job = new AdvanceOrbitResolution(7, 28);

    expect($job->timeout)->toBe(AdvanceOrbitResolution::TIMEOUT_SECONDS)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and(AdvanceOrbitResolution::LOCK_SECONDS)->toBeGreaterThan($job->timeout)
        ->and($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe('delivery:resolution-advance:7:28');
});
