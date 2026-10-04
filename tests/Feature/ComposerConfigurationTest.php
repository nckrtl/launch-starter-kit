<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('does not ship a root package version that would stale path create-project locks', function (): void {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)->not->toHaveKey('version');
});

it('runs Pest through the git-aware runner', function (): void {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['test'])->toBe('@php scripts/run-pest.php')
        ->and($composer['scripts']['test:browser'])->toBe('@php scripts/run-browser-tests.php')
        ->and($composer['scripts']['dev'][1] ?? '')->toContain('bunx concurrently')
        ->and($composer['scripts']['dev'][1] ?? '')->toContain('bun run dev')
        ->and($composer['scripts']['dev'][1] ?? '')->not->toContain('npx ')
        ->and($composer['scripts']['dev'][1] ?? '')->not->toContain('npm run dev');
});

it('lets composer scripts run longer than the default process timeout', function (): void {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    // The full suite takes longer than Composer's default 300-second limit.
    expect($composer['config']['process-timeout'] ?? null)->toBe(0);
});

it('ships a committed git hook that installs itself', function (): void {
    // `vp config` (run by `bun install` via the prepare script) points
    // core.hooksPath at VitePlus's dispatcher, which sources this file. It
    // must be committed and executable or the dispatcher silently exits 0 and
    // the hook does not run.
    $path = base_path('.vite-hooks/pre-commit');

    expect(File::exists($path))->toBeTrue('.vite-hooks/pre-commit must be committed')
        ->and(is_executable($path))->toBeTrue('.vite-hooks/pre-commit must be executable')
        ->and(File::get($path))->toContain('vp staged');
});

it('forwards composer test arguments to pest instead of artisan', function (): void {
    $filter = new Process(
        ['composer', 'test', '--', '--filter=it asserts true is true', '--compact'],
        base_path(),
    );
    $filter->setTimeout(60);
    $filter->mustRun();

    $filterOutput = $filter->getOutput().$filter->getErrorOutput();

    expect($filterOutput)
        ->toContain('Tests:')
        ->toContain('1 passed')
        ->not->toContain('The "--filter" option does not exist');

    $path = new Process(
        ['composer', 'test', '--', 'tests/Unit/ExampleTest.php', '--compact'],
        base_path(),
    );
    $path->setTimeout(60);
    $path->mustRun();

    $pathOutput = $path->getOutput().$path->getErrorOutput();

    expect($pathOutput)
        ->toContain('Tests:')
        ->toContain('1 passed')
        ->not->toContain('The "--filter" option does not exist');
});

it('does not reintroduce git config hooks, which cannot ship configured', function (): void {
    // hook.<name>.event needs Git 2.54+ and lives in per-clone git config, so it
    // can never be committed -- that is why it required eight manual commands.
    // setup.php also used to unset core.hooksPath, disabling the dispatcher.
    foreach (['README.md', 'setup.php', 'resources/markdown/create.md'] as $file) {
        expect(File::get(base_path($file)))
            ->not->toContain('hook.launch-')
            ->not->toContain('--unset core.hooksPath');
    }
});
