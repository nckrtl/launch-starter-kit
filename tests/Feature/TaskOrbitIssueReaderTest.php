<?php

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Delivery\IssueProviders\SshOrbitIssueProvider;
use App\Tasks\Orbit\ReadTaskOrbitIssue;
use Illuminate\Support\Facades\Process;

function taskReaderId(): string
{
    return '11111111-2222-4333-8444-555555555555';
}

/** @return array<string,mixed> */
function taskReaderPayload(array $overrides = []): array
{
    return ['id' => taskReaderId(), 'identifier' => 'ORB-248', 'title' => 'Tasks package',
        'url' => 'https://linear.app/orbit/issue/ORB-248', 'description' => 'Deliver the reviewed acceptance outcomes.',
        'updatedAt' => '2026-09-12T22:00:00Z', 'state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'In Review', 'type' => 'started'],
        'assignee' => null, 'delegate' => null, 'team' => ['id' => '33333333-4444-4555-8666-777777777777',
            'states' => ['nodes' => [['id' => '22222222-3333-4444-8555-666666666666', 'name' => 'In Review']]]],
        'labels' => ['nodes' => [['name' => 'incus'], ['name' => 'docs']], 'pageInfo' => ['hasNextPage' => false]],
        'attachments' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'children' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
        'inverseRelations' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]], ...$overrides];
}

function fakeTaskReader(array $payload, array $root = [], ?string $viewer = null): void
{
    $response = ['data' => ['viewer' => ['id' => $viewer ?? config('commander.hermes.tom_linear_viewer_id')], 'issue' => $payload], ...$root];
    Process::fake(['*' => Process::result(output: json_encode($response, JSON_THROW_ON_ERROR))])->preventStrayProcesses();
}

beforeEach(function () {
    config(['commander.hermes.ssh_target' => 'tom@mini', 'commander.hermes.profiles.tom' => '/Users/tom/.hermes/profiles/tom',
        'commander.hermes.tom_linear_viewer_id' => '4fa61558-9052-45f7-8a7c-49e0b891d4bf',
        'commander.hermes.nick_linear_user_id' => '691cb14c-60d5-415a-a5c7-a7c19fe83424',
        'commander.hermes.orbit_linear_team_id' => '33333333-4444-4555-8666-777777777777']);
});

it('reads undelegated active Tasks issues through the real shared SSH provider and normalizer', function (string $state, bool $assigned) {
    $payload = taskReaderPayload(['state' => ['id' => '22222222-3333-4444-8555-666666666666', 'name' => $state, 'type' => 'started'],
        'assignee' => $assigned ? ['id' => config('commander.hermes.nick_linear_user_id')] : null]);
    fakeTaskReader($payload);
    $snapshot = app(ReadTaskOrbitIssue::class)->read(taskReaderId(), 'ORB-248');
    expect(app(OrbitIssueReader::class))->toBeInstanceOf(SshOrbitIssueProvider::class)
        ->and($snapshot->issueId)->toBe(taskReaderId())->and($snapshot->payload['delegate'])->toBeNull()
        ->and($snapshot->payload['assignee'])->toBe($payload['assignee'])
        ->and($snapshot->contractHash)->toBe(app(OrbitIssueSnapshotFactory::class)->contractHash($payload))
        ->and($snapshot->payload['labels']['nodes'])->toBe([['name' => 'docs'], ['name' => 'incus']]);
    Process::assertRan(function ($process): bool {
        $request = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

        return $process->command === ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', 'tom@mini',
            "'/Users/tom/.hermes/profiles/tom/scripts/orbit_delivery_loop.py' --rpc"]
            && $request['service'] === 'linear' && str_starts_with($request['document'], 'query LoopIssue(')
            && $request['variables'] === ['id' => taskReaderId()];
    });
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['In Progress', 'In Review'])->with([false, true]);

it('keeps policy-neutral reads separate from legacy Tom and Tasks eligibility', function () {
    fakeTaskReader(taskReaderPayload());
    expect(app(SshOrbitIssueProvider::class)->read(taskReaderId(), 'ORB-248')->payload['delegate'])->toBeNull()
        ->and(fn () => app(SshOrbitIssueProvider::class)->fetchActive(taskReaderId(), 'ORB-248'))
        ->toThrow(OrbitIssueProviderFailed::class, 'delegated to Tom');
    fakeTaskReader(taskReaderPayload(['delegate' => ['id' => config('commander.hermes.tom_linear_viewer_id')]]));
    expect(app(SshOrbitIssueProvider::class)->fetchActive(taskReaderId(), 'ORB-248')->payload['delegate'])
        ->toBe(['id' => config('commander.hermes.tom_linear_viewer_id')])
        ->and(fn () => app(ReadTaskOrbitIssue::class)->read(taskReaderId(), 'ORB-248'))->toThrow(LogicException::class, 'undelegated');
});

it('rejects ownership lifecycle and readiness conflicts at the Tasks boundary', function (string $defect) {
    $payload = taskReaderPayload();
    match ($defect) {
        'delegate' => $payload['delegate'] = ['id' => config('commander.hermes.tom_linear_viewer_id')],
        'foreign delegate' => $payload['delegate'] = ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        'foreign assignee' => $payload['assignee'] = ['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        'wrong team' => $payload['team']['id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'Todo' => $payload['state'] = [...$payload['state'], 'name' => 'Todo', 'type' => 'unstarted'],
        'Done' => $payload['state'] = [...$payload['state'], 'name' => 'Done', 'type' => 'completed'],
        'wrong type' => $payload['state']['type'] = 'unstarted',
        'child' => $payload['children']['nodes'] = [['id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']],
        'readiness' => $payload['description'] .= "\n## Readiness\nBlocked.",
        'blocker' => $payload['inverseRelations']['nodes'] = [['type' => 'blocks', 'issue' => ['identifier' => 'ORB-7', 'state' => ['type' => 'started']]]],
        'legacy controller' => $payload['labels']['nodes'][] = ['name' => 'controller:commander'],
        'foreign controller' => $payload['labels']['nodes'][] = ['name' => 'controller:other'],
        'maintenance' => $payload['labels']['nodes'][] = ['name' => 'maintenance:monorepo'],
    };
    fakeTaskReader($payload);
    expect(fn () => app(ReadTaskOrbitIssue::class)->read(taskReaderId(), 'ORB-248'))->toThrow(LogicException::class);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['delegate', 'foreign delegate', 'foreign assignee', 'wrong team', 'Todo', 'Done', 'wrong type',
    'child', 'readiness', 'blocker', 'legacy controller', 'foreign controller', 'maintenance']);

it('rejects incomplete paginated issue collections', function (string $collection) {
    $payload = taskReaderPayload();
    $payload[$collection]['pageInfo']['hasNextPage'] = true;
    fakeTaskReader($payload);
    expect(fn () => app(ReadTaskOrbitIssue::class)->read(taskReaderId(), 'ORB-248'))->toThrow(LogicException::class, 'incomplete');
})->with(['labels', 'attachments', 'children', 'inverseRelations']);

it('accepts explicit Tasks labeling and settled blockers without changing the source issue', function (string $state) {
    $payload = taskReaderPayload();
    $payload['labels']['nodes'][] = ['name' => 'controller:tasks'];
    $payload['inverseRelations']['nodes'] = [['type' => 'blocks', 'issue' => ['identifier' => 'ORB-7', 'state' => ['type' => $state]]]];
    fakeTaskReader($payload);
    expect(app(ReadTaskOrbitIssue::class)->read(taskReaderId(), 'ORB-248')->payload['inverseRelations']['nodes'])
        ->toBe($payload['inverseRelations']['nodes']);
    Process::assertRanTimes(fn (): bool => true, 1);
})->with(['completed', 'canceled']);

it('validates shared reader identity and complete ownership shape before Tasks policy', function (string $defect) {
    $payload = taskReaderPayload();
    $root = [];
    $viewer = null;
    match ($defect) {
        'issue id' => $payload['id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'issue key' => $payload['identifier'] = 'ORB-999',
        'viewer' => $viewer = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'graphql errors' => $root = ['errors' => [['message' => 'Partial response']]],
        'malformed labels' => $payload['labels']['nodes'] = [['name' => null]],
        'omitted delegate' => $payload = array_diff_key($payload, ['delegate' => true]),
        'omitted assignee' => $payload = array_diff_key($payload, ['assignee' => true]),
    };
    fakeTaskReader($payload, $root, $viewer);
    expect(fn () => app(OrbitIssueReader::class)->read(taskReaderId(), 'ORB-248'))->toThrow(OrbitIssueProviderFailed::class);
})->with(['issue id', 'issue key', 'viewer', 'graphql errors', 'malformed labels', 'omitted delegate', 'omitted assignee']);
