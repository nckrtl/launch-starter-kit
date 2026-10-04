<?php

use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\SQLiteConnection;

it('defaults the sqlite journal mode to WAL', function () {
    $settings = require dirname(__DIR__, 2).'/config/database.php';

    expect($settings['connections']['sqlite']['journal_mode'])->toBe('WAL')
        ->and($settings['connections']['sqlite']['synchronous'])->toBe('NORMAL')
        ->and(config('database.connections.sqlite.journal_mode'))->toBe('WAL')
        ->and(config('database.connections.sqlite.synchronous'))->toBe('NORMAL');
});

it('opens sqlite connections in WAL journal mode', function () {
    $path = tempnam(sys_get_temp_dir(), 'launch-sqlite-wal-');
    $settings = [...(require dirname(__DIR__, 2).'/config/database.php')['connections']['sqlite'],
        'database' => $path, 'url' => null];
    $pdo = new SQLiteConnector()->connect($settings);
    $connection = new SQLiteConnection($pdo, $path, '', $settings);

    expect($connection->getConfig('journal_mode'))->toBe('WAL')
        ->and(strtolower((string) $connection->selectOne('PRAGMA journal_mode')->journal_mode))->toBe('wal')
        ->and((int) $connection->selectOne('PRAGMA synchronous')->synchronous)->toBe(1);

    $connection->disconnect();
    unlink($path);
    @unlink($path.'-wal');
    @unlink($path.'-shm');
});
