<?php

use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->base = storage_path('framework/testing/orbit-main-cache-refresh-'.bin2hex(random_bytes(4)));
    $this->repository = $this->base.'/repository';
    File::makeDirectory($this->repository.'/bin', 0755, true);
    File::put($this->repository.'/bin/tia-cache', "#!/bin/sh\n");
    chmod($this->repository.'/bin/tia-cache', 0755);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('requests durable Orbit main cache maintenance through the trusted adapter', function (string $message, string $disposition) {
    Process::fake(function ($process) use ($message) {
        expect($process->path)->toBe($this->repository)
            ->and($process->command)->toBe([
                $this->repository.'/bin/tia-cache',
                'refresh',
                '--background',
                '--repository='.$this->repository,
            ])
            ->and($process->timeout)->toBe(30);

        return Process::result(output: $message."\n");
    })->preventStrayProcesses();

    $requested = app(OrbitMainCacheRefreshRequester::class)->request($this->repository);

    expect($requested->repository)->toBe($this->repository)
        ->and($requested->disposition)->toBe($disposition)
        ->and($requested->message)->toBe($message);
    Process::assertRanTimes(fn () => true, 1);
})->with([
    'queued' => [
        'Main cache refresh queued (pid 123); log: /tmp/orbit-tia/refresh.log',
        'queued',
    ],
    'coalesced' => [
        'Cache request retained by active worker; log: /tmp/orbit-tia/refresh.log',
        'coalesced',
    ],
    'already current' => [
        'Main caches already published; no refresh needed.',
        'already_current',
    ],
]);

it('rejects failed or incomplete Orbit main cache refresh evidence', function (string $output, string $error, int $exit) {
    Process::fake(['*' => Process::result(
        output: $output,
        errorOutput: $error,
        exitCode: $exit,
    )])->preventStrayProcesses();

    expect(fn () => app(OrbitMainCacheRefreshRequester::class)->request($this->repository))
        ->toThrow(OrbitRepositoryFailed::class);
})->with([
    'failed request' => ['', 'queue unavailable', 1],
    'unrecognized success' => ['refresh accepted', '', 0],
]);

it('does not run an unavailable or redirected cache adapter', function (string $path) {
    if ($path === 'redirected') {
        File::delete($this->repository.'/bin/tia-cache');
        symlink('/bin/true', $this->repository.'/bin/tia-cache');
    } else {
        chmod($this->repository.'/bin/tia-cache', 0644);
    }

    Process::fake()->preventStrayProcesses();

    expect(fn () => app(OrbitMainCacheRefreshRequester::class)->request($this->repository))
        ->toThrow(OrbitRepositoryFailed::class, 'adapter is unavailable');
    Process::assertRanTimes(fn () => true, 0);
})->with(['not executable', 'redirected']);
