<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/website-dependencies-'.bin2hex(random_bytes(6));
    mkdir($this->directory.'/repo with spaces/scripts', 0755, true);
    mkdir($this->directory.'/repo with spaces/node_modules/.bin', 0755, true);
    mkdir($this->directory.'/bin');
    symlink(PHP_BINARY, $this->directory.'/bin/php');
    symlink('/usr/bin/dirname', $this->directory.'/bin/dirname');
    copy(__DIR__.'/../../scripts/dependencies', $this->directory.'/repo with spaces/scripts/dependencies');
    chmod($this->directory.'/repo with spaces/scripts/dependencies', 0755);
    foreach ([$this->directory.'/bin/composer', $this->directory.'/repo with spaces/node_modules/.bin/vp'] as $binary) {
        copy(__DIR__.'/../Fixtures/dependencies/manager', $binary);
        chmod($binary, 0755);
    }
});

afterEach(function () {
    new Process(['rm', '-rf', '--', $this->directory])->mustRun();
});

it('updates in the repository root and propagates failures without running later commands', function (int $composerExit, int $vpExit) {
    $repo = $this->directory.'/repo with spaces';
    $process = new Process([$repo.'/scripts/dependencies', 'routine'], $this->directory, [
        'PATH' => $this->directory.'/bin',
        'DEPENDENCY_TEST_LOG' => $this->directory.'/commands',
        'DEPENDENCY_TEST_COMPOSER_EXIT' => (string) $composerExit,
        'DEPENDENCY_TEST_VP_EXIT' => (string) $vpExit,
    ]);
    $process->run();

    expect($process->getExitCode())->toBe($composerExit ?: $vpExit);
    $expected = 'composer|update --no-interaction|'.$repo."\n";
    if ($composerExit === 0) {
        $expected .= 'vp|update|'.$repo."\n";
    }
    expect(file_get_contents($this->directory.'/commands'))->toBe($expected);
})->with([[0, 0], [17, 0], [0, 23]]);

it('fails clearly before updates when local Vite+ is unavailable even with a global CLI', function (bool $missing) {
    $repo = $this->directory.'/repo with spaces';
    if ($missing) {
        unlink($repo.'/node_modules/.bin/vp');
    } else {
        chmod($repo.'/node_modules/.bin/vp', 0644);
    }
    copy(__DIR__.'/../Fixtures/dependencies/manager', $this->directory.'/bin/vp');
    chmod($this->directory.'/bin/vp', 0755);
    $process = new Process([$repo.'/scripts/dependencies', 'routine'], $this->directory, [
        'PATH' => $this->directory.'/bin',
        'DEPENDENCY_TEST_LOG' => $this->directory.'/commands',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(127)
        ->and($process->getErrorOutput())->toContain('Missing executable node_modules/.bin/vp; install the repository dependencies first.')
        ->and(file_exists($this->directory.'/commands'))->toBeFalse();
})->with([true, false]);

it('rejects unsupported modes and extra arguments without updating dependencies', function (array $arguments) {
    $process = new Process([$this->directory.'/repo with spaces/scripts/dependencies', ...$arguments], $this->directory, [
        'PATH' => $this->directory.'/bin', 'DEPENDENCY_TEST_LOG' => $this->directory.'/commands',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(64);
    expect($process->getErrorOutput())->toContain('Usage: scripts/dependencies routine');
    expect(file_exists($this->directory.'/commands'))->toBeFalse();
})->with([[[]], [['unsupported']], [['package', 'react']], [['routine', '--force']]]);
