<?php

use Symfony\Component\Process\Process;

it('selects the SSR endpoint for this instance', function (string|false $port, string|false $url, string $expected) {
    $process = new Process([
        PHP_BINARY,
        '-r',
        'require "vendor/autoload.php"; require "bootstrap/app.php"; echo (require "config/inertia.php")["ssr"]["url"];',
    ], base_path(), [
        'INERTIA_SSR_PORT' => $port,
        'INERTIA_SSR_URL' => $url,
    ]);
    $process->setTimeout(10)->mustRun();

    expect($process->getOutput())->toBe($expected);
})->with([
    'main default' => [false, false, (file_exists('/.dockerenv') ? 'http://host.docker.internal:' : 'http://127.0.0.1:').'13719'],
    'tasks port' => ['13729', false, (file_exists('/.dockerenv') ? 'http://host.docker.internal:' : 'http://127.0.0.1:').'13729'],
    'explicit endpoint' => ['13729', 'http://ssr.example.test:14000', 'http://ssr.example.test:14000'],
]);
