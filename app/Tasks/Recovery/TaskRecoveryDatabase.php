<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use Illuminate\Database\ConfigurationUrlParser;
use LogicException;
use PDO;
use PDOStatement;

final class TaskRecoveryDatabase
{
    public const string MIGRATION = '2026_09_12_193542_create_task_recoveries_table';

    public function assertTarget(string $path, string $backup): void
    {
        foreach ([$path, $backup] as $file) {
            $this->assertOfflineFile($file);
        }
        $denied = [$backup, database_path('database.sqlite'), base_path('database/database.sqlite')];
        $connections = config('database.connections');
        if (is_array($connections)) {
            foreach ($connections as $configuration) {
                if (is_array($configuration)) {
                    $parsed = (new ConfigurationUrlParser)->parseConfiguration(TaskRecoveryEvidence::object($configuration));
                    if (($parsed['driver'] ?? null) === 'sqlite' && is_string($parsed['database'] ?? null)) {
                        if (str_starts_with($parsed['database'], 'file:')) {
                            throw new LogicException('Recovery refuses the application database when its configured SQLite URI identity is unresolved.');
                        }
                        $denied[] = $parsed['database'];
                        if (! str_starts_with($parsed['database'], '/') && $parsed['database'] !== ':memory:') {
                            $denied[] = base_path($parsed['database']);
                        }
                    }
                }
            }
        }
        foreach ($denied as $protected) {
            if ($protected === $path || realpath($protected) === $path) {
                throw new LogicException('Recovery refuses the application database or the original backup as a target.');
            }
        }
    }

    public function assertBackupCopy(string $path, TaskRecoveryEvidence $evidence): bool
    {
        if (! hash_equals($evidence->backupSha256, (string) hash_file('sha256', $evidence->backupPath))) {
            throw new LogicException('The pre-admission backup digest does not match the approved evidence.');
        }
        $backup = $this->readOnly($evidence->backupPath);
        $target = $this->readOnly($path);
        foreach ([$backup, $target] as $database) {
            if ($this->query($database, 'PRAGMA integrity_check')->fetchColumn() !== 'ok'
                || $this->query($database, 'PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new LogicException('The recovery database failed SQLite integrity checks.');
            }
        }
        $originalSchema = $this->schema($backup);
        $targetSchema = $this->schema($target);
        $recoverySchema = array_values(array_filter($targetSchema, fn (array $row): bool => $row['tbl_name'] === 'task_recoveries'));
        $comparableSchema = array_values(array_filter($targetSchema, fn (array $row): bool => $row['tbl_name'] !== 'task_recoveries'));
        if ($comparableSchema !== array_values(array_filter($originalSchema, fn (array $row): bool => $row['tbl_name'] !== 'task_recoveries'))
            || array_any($recoverySchema, fn (array $row): bool => ! in_array($row['type'], ['table', 'index'], true))) {
            throw new LogicException('The quarantine schema differs from the pre-admission backup.');
        }
        foreach ($originalSchema as $row) {
            if ($row['type'] !== 'table' || in_array($row['name'], ['sqlite_sequence', 'task_recoveries'], true)) {
                continue;
            }
            $table = $row['name'];
            if ($this->rows($backup, $table) !== $this->rows($target, $table)) {
                throw new LogicException('The quarantine data differs from the pre-admission backup: '.$table);
            }
        }
        foreach (['task_runs', 'task_run_reviews', 'task_workspaces', 'task_agent_dispatches'] as $table) {
            if ($this->rows($target, $table) !== []) {
                throw new LogicException('Recovery requires empty pre-admission runtime history.');
            }
        }
        $migrated = $recoverySchema !== [];
        if ($migrated && $this->rows($target, 'task_recoveries') !== []) {
            throw new LogicException('This database already contains a task recovery.');
        }

        return $migrated;
    }

    public function readOnly(string $path): PDO
    {
        $this->assertOfflineFile($path);

        return new PDO('sqlite:file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function assertOfflineFile(string $path): void
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path) || is_link($path)
            || (stat($path)['nlink'] ?? 0) !== 1 || is_file($path.'-wal') || is_file($path.'-shm') || is_file($path.'-journal')) {
            throw new LogicException('Recovery requires separate canonical offline SQLite files without links or sidecars.');
        }
        $header = file_get_contents($path, false, null, 0, 20);
        if ($header === false || strlen($header) !== 20 || substr($header, 0, 16) !== "SQLite format 3\0"
            || substr($header, 18, 2) !== "\x01\x01") {
            throw new LogicException('Recovery requires rollback-journal SQLite format; WAL-format or invalid inputs are not opened.');
        }
    }

    /** @return list<array{name: string, tbl_name: string, type: string, sql: string|null}> */
    private function schema(PDO $database): array
    {
        $rows = $this->query($database, 'SELECT name, tbl_name, type, sql FROM sqlite_master ORDER BY name')->fetchAll();
        $schema = [];
        foreach ($rows as $row) {
            $value = TaskRecoveryEvidence::object($row);
            $schema[] = ['name' => TaskRecoveryEvidence::string($value, 'name'),
                'tbl_name' => TaskRecoveryEvidence::string($value, 'tbl_name'),
                'type' => TaskRecoveryEvidence::string($value, 'type'),
                'sql' => isset($value['sql']) ? TaskRecoveryEvidence::string($value, 'sql') : null];
        }

        return $schema;
    }

    /** @return list<string> */
    private function rows(PDO $database, string $table): array
    {
        $rows = $this->query($database, 'SELECT * FROM "'.str_replace('"', '""', $table).'"')->fetchAll();
        if ($table === 'migrations') {
            $rows = array_filter($rows, fn (array $row): bool => $row['migration'] !== self::MIGRATION);
        }
        $encoded = array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);

        return $encoded;
    }

    private function query(PDO $database, string $sql): PDOStatement
    {
        $statement = $database->query($sql);
        if ($statement === false) {
            throw new LogicException('Recovery database inspection failed.');
        }

        return $statement;
    }
}
