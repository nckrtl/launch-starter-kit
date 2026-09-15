<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TaskResumptionDatabase
{
    public function assertConfigured(string $path): void
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path)
            || is_link($path) || (stat($path)['nlink'] ?? 0) !== 1) {
            throw new LogicException('Resume requires the canonical configured SQLite database path without links.');
        }
        $name = config('database.default');
        if (! is_string($name)) {
            throw new LogicException('The configured database connection is missing.');
        }
        $configuration = TaskRecoveryEvidence::object(config('database.connections.'.$name));
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($configuration);
        if (($parsed['driver'] ?? null) !== 'sqlite' || ($parsed['database'] ?? null) !== $path
            || isset($parsed['read']) || isset($parsed['write'])) {
            throw new LogicException('The resume database pin must equal the effective configured SQLite database.');
        }
    }

    public function assertRuntimeConnection(string $path): void
    {
        $this->assertConfigured($path);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== $path
            || $connection->scalar("SELECT file FROM pragma_database_list WHERE name = 'main'") !== $path) {
            throw new LogicException('The active runtime connection differs from the pinned database.');
        }
    }
}
