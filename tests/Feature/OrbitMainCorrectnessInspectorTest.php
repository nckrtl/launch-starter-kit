<?php

use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-main-correctness-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    $this->worktrees = $this->base.'/worktrees';
    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::makeDirectory($this->worktrees, 0755, true);
    File::put($this->repository.'/bin/tia-cache', "#!/bin/sh\n");
    chmod($this->repository.'/bin/tia-cache', 0755);
    $this->config = new OrbitProjectConfig(
        type: OrbitProjectConfig::TYPE,
        repository: $this->repository,
        worktreeRoot: $this->worktrees,
        herdrSession: 'orbit',
        concurrency: 1,
        defaultFlow: 'discovery',
    );
});

afterEach(fn () => File::deleteDirectory($this->base));

it('reads Orbit main correctness through the trusted cache adapter', function () {
    $main = str_repeat('a', 40);
    Process::fake(function ($process) use ($main) {
        expect($process->path)->toBe($this->repository)
            ->and($process->command)->toBe([
                $this->repository.'/bin/tia-cache',
                'status',
                '--json',
                '--remote',
            ]);

        return Process::result(output: json_encode([
            'schema' => 1,
            'main' => $main,
            'correctness_failures' => [],
            'needed' => false,
        ], JSON_THROW_ON_ERROR));
    })->preventStrayProcesses();

    $status = app(OrbitMainCorrectnessInspector::class)->inspectMainCorrectness($this->config);

    expect($status->mainSha)->toBe($main)
        ->and($status->failures)->toBe([])
        ->and($status->passed())->toBeTrue();
    Process::assertRanTimes(fn () => true, 1);
});

it('retains named correctness failures as a merge hold', function () {
    Process::fake(['*' => Process::result(output: json_encode([
        'schema' => 1,
        'main' => str_repeat('a', 40),
        'correctness_failures' => ['apps/gateway' => ['tool' => 'phpstan', 'exit_code' => 1]],
    ], JSON_THROW_ON_ERROR))])->preventStrayProcesses();

    $status = app(OrbitMainCorrectnessInspector::class)->inspectMainCorrectness($this->config);

    expect($status->passed())->toBeFalse()
        ->and($status->failures)->toBe([
            'apps/gateway' => ['tool' => 'phpstan', 'exit_code' => 1],
        ]);
});

it('rejects failed, malformed, or incomplete main correctness results', function (mixed $output, int $exit) {
    Process::fake(['*' => Process::result(
        output: is_string($output) ? $output : json_encode($output, JSON_THROW_ON_ERROR),
        errorOutput: $exit === 0 ? '' : 'remote status failed',
        exitCode: $exit,
    )])->preventStrayProcesses();

    expect(fn () => app(OrbitMainCorrectnessInspector::class)->inspectMainCorrectness($this->config))
        ->toThrow(OrbitRepositoryFailed::class);
})->with([
    'failed command' => [[], 1],
    'invalid JSON' => ['{', 0],
    'missing failures' => [['schema' => 1, 'main' => str_repeat('a', 40)], 0],
    'invalid main' => [['schema' => 1, 'main' => 'main', 'correctness_failures' => []], 0],
    'list failures' => [[
        'schema' => 1,
        'main' => str_repeat('a', 40),
        'correctness_failures' => [['project' => 'apps/gateway']],
    ], 0],
]);
