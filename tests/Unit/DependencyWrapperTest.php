<?php

use Symfony\Component\Process\Process;

it('runs detect through the maintainer wrapper without changing lockfiles', function () {
    $root = dirname(__DIR__, 2);
    $watched = ['composer.json', 'composer.lock', 'package.json', 'bun.lock'];
    $before = [];
    foreach ($watched as $path) {
        $before[$path] = hash_file('sha256', $root.'/'.$path);
    }

    $process = new Process([$root.'/scripts/dependencies', 'detect'], $root);
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0);
    $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['schema'])->toBe(1)->and($decoded['candidates'])->toBeArray();
    foreach ($watched as $path) {
        expect(hash_file('sha256', $root.'/'.$path))->toBe($before[$path]);
    }
});
