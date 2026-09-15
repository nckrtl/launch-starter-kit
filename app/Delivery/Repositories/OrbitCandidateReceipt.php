<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use JsonException;

final readonly class OrbitCandidateReceipt
{
    private const array PROJECTS = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array COMMANDS = [
        ['composer', 'validate', '--strict'],
        ['composer', 'check'],
        ['composer', 'test:affected'],
    ];

    public function validate(string $reportedReceipt, PreparedWorktree $worktree, string $treeSha, string $path, string $common): string
    {
        $this->contents($reportedReceipt, $worktree, $treeSha, $path, $common);

        return $reportedReceipt;
    }

    public function contents(string $reportedReceipt, PreparedWorktree $worktree, string $treeSha, string $path, string $common): string
    {
        $receiptPath = realpath($reportedReceipt);
        $expectedDirectory = $common.'/orbit-checks/'.$worktree->headSha;
        if ($receiptPath === false || $receiptPath !== $reportedReceipt
            || is_link($reportedReceipt) || ! is_file($receiptPath)
            || basename($receiptPath) !== 'result.json'
            || dirname(dirname($receiptPath)) !== $expectedDirectory) {
            throw new OrbitRepositoryFailed('Orbit candidate check returned an invalid receipt path.');
        }
        $contents = file_get_contents($receiptPath);
        try {
            $receipt = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $receipt = null;
        }
        if ($contents === false || ! is_array($receipt) || ! $this->valid($receipt, $worktree, $treeSha, $path)) {
            throw new OrbitRepositoryFailed('Orbit candidate check returned an invalid receipt.');
        }

        return $contents;
    }

    /** @param array<mixed, mixed> $receipt */
    private function valid(array $receipt, PreparedWorktree $worktree, string $treeSha, string $path): bool
    {
        $checks = $receipt['checks'] ?? null;
        if (($receipt['schema'] ?? null) !== 1 || ($receipt['role'] ?? null) !== 'builder'
            || ($receipt['candidate'] ?? null) !== $worktree->headSha
            || ($receipt['tree'] ?? null) !== $treeSha
            || realpath(is_string($receipt['worktree'] ?? null) ? $receipt['worktree'] : '') !== $path
            || ($receipt['passed'] ?? null) !== true || ($receipt['unchanged'] ?? null) !== true
            || ! is_array($checks) || count($checks) !== count(self::PROJECTS) * count(self::COMMANDS)) {
            return false;
        }
        $actual = [];
        foreach ($checks as $check) {
            if (! is_array($check) || ! is_string($check['project'] ?? null)
                || ! is_array($check['command'] ?? null) || ($check['exit_code'] ?? null) !== 0) {
                return false;
            }
            $command = [];
            foreach ($check['command'] as $part) {
                if (! is_string($part)) {
                    return false;
                }
                $command[] = $part;
            }
            $actual[] = $check['project'].'|'.implode("\0", $command);
        }
        $expected = [];
        foreach (self::PROJECTS as $project) {
            foreach (self::COMMANDS as $command) {
                $expected[] = $project.'|'.implode("\0", $command);
            }
        }
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
