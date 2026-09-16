<?php

use Symfony\Component\Process\Process;

it('forces the shipped phpunit database env over a leftover process environment', function () {
    $xml = simplexml_load_file(base_path('phpunit.xml'));
    $forced = [];
    foreach ($xml->php->env as $env) {
        $forced[(string) $env['name']] = [
            'value' => (string) $env['value'],
            'force' => in_array((string) $env['force'], ['true', '1'], true),
        ];
    }

    expect($forced['APP_ENV'])->toMatchArray(['value' => 'testing', 'force' => true])
        ->and($forced['DB_CONNECTION'])->toMatchArray(['value' => 'sqlite', 'force' => true])
        ->and($forced['DB_DATABASE'])->toMatchArray(['value' => ':memory:', 'force' => true])
        ->and($forced['DB_URL'])->toMatchArray(['value' => '', 'force' => true]);
});

it('does not resolve the live sqlite path after phpunit applies a leftover environment file', function () {
    $live = tempnam(sys_get_temp_dir(), 'commander-live-sqlite-');
    $environment = getenv();
    $environment['APP_ENV'] = 'production';
    $environment['DB_CONNECTION'] = 'mysql';
    $environment['DB_DATABASE'] = $live;
    $environment['DB_URL'] = 'sqlite://'.$live;

    $process = new Process([
        PHP_BINARY,
        '-r',
        <<<'PHP'
        require 'vendor/autoload.php';
        $xml = (new PHPUnit\TextUI\XmlConfiguration\Loader)->load('phpunit.xml');
        (new PHPUnit\TextUI\Configuration\PhpHandler)->handle($xml->php());
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'database' => $app['config']->get('database.connections.sqlite.database'),
            'url' => $app['config']->get('database.connections.sqlite.url'),
            'connection' => $app['config']->get('database.default'),
            'env' => $app->environment(),
            'server' => $_SERVER['DB_DATABASE'] ?? null,
        ], JSON_THROW_ON_ERROR);
        PHP,
    ], base_path(), $environment);
    $process->setTimeout(20)->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'database' => ':memory:',
            'url' => '',
            'connection' => 'sqlite',
            'env' => 'testing',
            'server' => ':memory:',
        ]);

    unlink($live);
});
