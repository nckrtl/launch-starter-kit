<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Isolate before Laravel boots. PHPUnit force=true updates putenv/$_ENV but can
        // leave stale $_SERVER from a Process EnvironmentFile; Laravel env() prefers $_SERVER.
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) config('database.connections.'.config('database.default').'.database');
        if ($database !== ':memory:') {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null,
            ]);
            DB::purge();
            DB::reconnect();
            $database = (string) config('database.connections.sqlite.database');
        }

        if ($database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against non-memory database [{$database}]."
            );
        }
    }
}
