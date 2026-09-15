<?php

use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\SQLiteConnection;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->sqlitePath = tempnam(sys_get_temp_dir(), 'commander-sqlite-lock-');
    $settings = require dirname(__DIR__, 2).'/config/database.php';
    $this->sqliteSettings = [...$settings['connections']['sqlite'], 'database' => $this->sqlitePath,
        'url' => null, 'journal_mode' => 'DELETE'];
    $pdo = new SQLiteConnector()->connect($this->sqliteSettings);
    $this->sqlite = new SQLiteConnection($pdo, $this->sqlitePath, '', $this->sqliteSettings);
    $this->sqlite->statement('CREATE TABLE observations (value INTEGER NOT NULL)');
    $this->sqlite->statement('INSERT INTO observations VALUES (0)');
    $this->contender = null;
});

afterEach(function () {
    $this->contender?->stop();
    $this->sqlite->disconnect();
    unlink($this->sqlitePath);
});

it('waits for the writer before entering the callback and reserves it before reading', function () {
    expect($this->sqlite->getConfig('transaction_mode'))->toBe('IMMEDIATE')
        ->and((int) $this->sqlite->selectOne('PRAGMA busy_timeout')->timeout)->toBe(5000);
    $this->contender = new Process([PHP_BINARY, '-r',
        '$pdo=new PDO("sqlite:".$argv[1]); $pdo->exec("BEGIN IMMEDIATE"); echo "locked\n"; flush(); usleep(200000); $pdo->exec("COMMIT");',
        $this->sqlitePath]);
    $this->contender->setTimeout(3);
    $this->contender->start();
    expect($this->contender->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue();
    $calls = 0;
    $this->sqlite->transaction(function () use (&$calls) {
        $calls++;
        expect((int) $this->sqlite->selectOne('SELECT value FROM observations')->value)->toBe(0);
        $other = new PDO('sqlite:'.$this->sqlitePath);
        $other->exec('PRAGMA busy_timeout=0');
        expect(fn () => $other->exec('BEGIN IMMEDIATE'))->toThrow(PDOException::class);
        $this->sqlite->update('UPDATE observations SET value=1');
    });
    expect($calls)->toBe(1)
        ->and((int) $this->sqlite->selectOne('SELECT value FROM observations')->value)->toBe(1)
        ->and($this->sqlite->transactionLevel())->toBe(0)
        ->and($this->contender->wait())->toBe(0);
});

it('times out before executing a callback when the writer stays occupied', function () {
    $other = new PDO('sqlite:'.$this->sqlitePath);
    $other->exec('BEGIN IMMEDIATE');
    $calls = 0;
    try {
        expect(function () use (&$calls) {
            $this->sqlite->transaction(function () use (&$calls) {
                $calls++;
                $this->sqlite->update('UPDATE observations SET value=1');
            });
        })->toThrow(PDOException::class);
    } finally {
        $other->exec('ROLLBACK');
    }
    expect($calls)->toBe(0)
        ->and((int) $this->sqlite->selectOne('SELECT value FROM observations')->value)->toBe(0)
        ->and($this->sqlite->transactionLevel())->toBe(0);
});
