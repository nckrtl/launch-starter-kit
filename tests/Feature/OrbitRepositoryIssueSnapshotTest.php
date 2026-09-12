<?php

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Repositories\ProcessOrbitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-snapshot-'.bin2hex(random_bytes(4)));
    $this->repositoryPath = $this->base.'/repository';
    $this->worktreeRoot = $this->base.'/worktrees';
    $this->worktreePath = $this->worktreeRoot.'/orb-234';
    File::makeDirectory($this->repositoryPath, 0755, true);
    File::makeDirectory($this->repositoryPath.'/.git', 0755, true);
    File::makeDirectory($this->worktreePath.'/.loop', 0755, true);
    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: $this->repositoryPath,
        worktreeRoot: $this->worktreeRoot,
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
    $this->worktree = new PreparedWorktree(realpath($this->worktreePath), str_repeat('a', 40));
    $this->snapshot = repositoryIssueSnapshot();
});

afterEach(fn () => File::deleteDirectory($this->base));

function repositoryIssueSnapshot(): OrbitIssueSnapshot
{
    $issueId = '11111111-2222-4333-8444-555555555555';

    return new OrbitIssueSnapshot(
        issueId: $issueId,
        issueKey: 'ORB-234',
        payload: [
            'id' => $issueId,
            'identifier' => 'ORB-234',
            'title' => 'Trusted issue',
            'labels' => ['nodes' => [
                ['name' => 'apps:cli'],
                ['name' => 'proof:incus'],
            ]],
        ],
        contractHash: str_repeat('b', 64),
    );
}

it('acquires and releases the private legacy controller lock', function () {
    $repository = app(ProcessOrbitRepository::class);
    $reservation = $repository->reserveDelivery($this->config, 'ORB-234');

    expect($reservation->path)->toBe($this->repositoryPath.'/.git/orbit-delivery/v1/orb-234/controller.lock')
        ->and(is_file($reservation->path))->toBeTrue()
        ->and(fileperms($reservation->path) & 0777)->toBe(0600);

    expect(fn () => $repository->reserveDelivery($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'Another Orbit delivery controller currently owns this issue');

    $reservation->release();
    $next = $repository->reserveDelivery($this->config, 'ORB-234');
    $next->release();

    expect(File::exists($reservation->path))->toBeTrue();
});

it('rejects existing legacy controller journals', function (string $journal) {
    $directory = $this->repositoryPath.'/.git/orbit-delivery/v1/orb-234';
    $lockPath = $directory.'/controller.lock';
    File::makeDirectory($directory, 0700, true);
    File::put($lockPath, 'legacy-lock');
    chmod($lockPath, 0664);
    File::put($directory.'/'.$journal, '{}');

    expect(fn () => app(ProcessOrbitRepository::class)->reserveDelivery($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'already has a legacy Orbit controller journal')
        ->and(File::get($lockPath))->toBe('legacy-lock')
        ->and(fileperms($lockPath) & 0777)->toBe(0664);
})->with([
    'state journal' => 'state.json',
    'orphan worker marker' => 'worker.json',
]);

it('acquires the legacy lock for an inactive needs-attention handoff', function () {
    $directory = $this->repositoryPath.'/.git/orbit-delivery/v1/orb-234';
    File::makeDirectory($directory, 0700, true);
    File::put($directory.'/state.json', json_encode([
        'schema' => 1,
        'issue' => 'ORB-234',
        'status' => 'needs_attention',
    ], JSON_THROW_ON_ERROR));
    File::put($directory.'/worker.json', json_encode([
        'pid' => 999_999_999,
        'started_at' => 1_789_178_931.0,
    ], JSON_THROW_ON_ERROR));

    $reservation = app(ProcessOrbitRepository::class)->reserveDelivery($this->config, 'ORB-234');

    expect($reservation->path)->toBe($directory.'/controller.lock');
    $reservation->release();
});

it('refuses a needs-attention handoff while its legacy worker is alive', function () {
    $directory = $this->repositoryPath.'/.git/orbit-delivery/v1/orb-234';
    File::makeDirectory($directory, 0700, true);
    File::put($directory.'/state.json', json_encode([
        'schema' => 1,
        'issue' => 'ORB-234',
        'status' => 'needs_attention',
    ], JSON_THROW_ON_ERROR));
    File::put($directory.'/worker.json', json_encode([
        'pid' => getmypid(),
        'started_at' => 1_789_178_931.0,
    ], JSON_THROW_ON_ERROR));

    expect(fn () => app(ProcessOrbitRepository::class)->reserveDelivery($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'legacy Orbit controller journal');
});

it('rejects a symlinked legacy controller directory', function () {
    $outside = $this->base.'/outside-controller';
    File::makeDirectory($outside, 0700);
    symlink($outside, $this->repositoryPath.'/.git/orbit-delivery');

    expect(fn () => app(ProcessOrbitRepository::class)->reserveDelivery($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'reservation directory is unsafe');
});

it('rejects a symlinked legacy controller lock', function () {
    $directory = $this->repositoryPath.'/.git/orbit-delivery/v1/orb-234';
    $outside = $this->base.'/outside.lock';
    File::makeDirectory($directory, 0700, true);
    File::put($outside, '');
    symlink($outside, $directory.'/controller.lock');

    expect(fn () => app(ProcessOrbitRepository::class)->reserveDelivery($this->config, 'ORB-234'))
        ->toThrow(OrbitRepositoryFailed::class, 'reservation lock is unsafe');
});

it('atomically publishes a private normalized issue snapshot', function () {
    $prepared = app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $this->worktree,
        $this->snapshot,
    );

    $contents = File::get($prepared->path);

    expect($prepared->schema)->toBe(1)
        ->and($prepared->provider)->toBe('linear')
        ->and($prepared->path)->toBe($this->worktreePath.'/.loop/issue.json')
        ->and($prepared->contentsHash)->toBe(hash('sha256', $contents))
        ->and($prepared->contractSchema)->toBe(2)
        ->and($prepared->contractHash)->toBe(str_repeat('b', 64))
        ->and($prepared->issueId)->toBe($this->snapshot->issueId)
        ->and($prepared->issueKey)->toBe('ORB-234')
        ->and(json_decode($contents, true, flags: JSON_THROW_ON_ERROR))->toBe($this->snapshot->payload)
        ->and(fileperms($prepared->path) & 0777)->toBe(0600);
});

it('verifies the exact retained bytes before dispatch', function () {
    $repository = app(ProcessOrbitRepository::class);
    $prepared = $repository->writeIssueSnapshot($this->config, $this->worktree, $this->snapshot);

    $repository->verifyIssueSnapshot($this->config, $this->worktree, $prepared);

    File::append($prepared->path, "\n");

    expect(fn () => $repository->verifyIssueSnapshot($this->config, $this->worktree, $prepared))
        ->toThrow(OrbitRepositoryFailed::class, 'no longer matches its ledger record');
});

it('reuses semantically identical retained bytes without rewriting them', function () {
    $path = $this->worktreePath.'/.loop/issue.json';
    $retained = <<<'JSON'
        {"labels":{"nodes":[{"name":"proof:incus"},{"name":"apps:cli"}]},"title":"Trusted issue","identifier":"ORB-234","id":"11111111-2222-4333-8444-555555555555"}
        JSON;
    File::put($path, $retained);
    chmod($path, 0600);

    $prepared = app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $this->worktree,
        $this->snapshot,
    );

    expect(File::get($path))->toBe($retained)
        ->and($prepared->contentsHash)->toBe(hash('sha256', $retained));
});

it('never overwrites a conflicting existing issue snapshot', function () {
    $path = $this->worktreePath.'/.loop/issue.json';
    $retained = '{"id":"different"}';
    File::put($path, $retained);
    chmod($path, 0600);

    expect(fn () => app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $this->worktree,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'conflicts with the fetched issue')
        ->and(File::get($path))->toBe($retained);
});

it('rejects an existing snapshot that is readable by other users', function () {
    $path = $this->worktreePath.'/.loop/issue.json';
    File::put($path, json_encode($this->snapshot->payload, JSON_THROW_ON_ERROR));
    chmod($path, 0644);

    expect(fn () => app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $this->worktree,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'snapshot path is unsafe');
});

it('rejects symlinked issue snapshot targets', function () {
    $outside = $this->base.'/outside.json';
    $path = $this->worktreePath.'/.loop/issue.json';
    File::put($outside, '{"safe":true}');
    symlink($outside, $path);

    expect(fn () => app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $this->worktree,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class, 'snapshot path is unsafe')
        ->and(File::get($outside))->toBe('{"safe":true}');
});

it('requires a regular loop directory inside the configured canonical worktree root', function (string $case) {
    if ($case === 'loop symlink') {
        File::deleteDirectory($this->worktreePath.'/.loop');
        File::makeDirectory($this->base.'/outside-loop');
        symlink($this->base.'/outside-loop', $this->worktreePath.'/.loop');
        $worktree = $this->worktree;
    } else {
        $outside = $this->base.'/outside-worktree';
        File::makeDirectory($outside.'/.loop', 0755, true);
        $worktree = new PreparedWorktree(realpath($outside), str_repeat('a', 40));
    }

    expect(fn () => app(ProcessOrbitRepository::class)->writeIssueSnapshot(
        $this->config,
        $worktree,
        $this->snapshot,
    ))->toThrow(OrbitRepositoryFailed::class);
})->with([
    'loop symlink',
    'outside worktree',
]);
