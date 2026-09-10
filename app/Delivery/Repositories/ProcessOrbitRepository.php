<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final readonly class ProcessOrbitRepository implements OrbitRepository
{
    private const array PROJECTS = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array COMMANDS = [
        ['composer', 'validate', '--strict'],
        ['composer', 'check'],
        ['composer', 'test:affected'],
    ];

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree
    {
        $repository = realpath($config->repository);
        $script = $repository === false ? false : realpath($repository.'/bin/worktree-create');

        if ($repository === false || ! is_dir($repository) || $script === false || ! is_executable($script)) {
            throw new OrbitRepositoryFailed('The configured Orbit worktree adapter is unavailable.');
        }

        try {
            $result = Process::path($repository)
                ->timeout(300)
                ->run([$script, $issueKey, '--flow='.$config->defaultFlow]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit worktree adapter could not run.', 0, $exception);
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit worktree preparation failed: '.$details);
        }

        $lines = preg_split('/\R/', trim($result->output())) ?: [];
        $reportedPath = end($lines);
        $worktree = is_string($reportedPath) ? realpath($reportedPath) : false;
        $root = realpath($config->worktreeRoot);

        if ($root === false || $worktree === false || ! is_dir($worktree)
            || ($worktree !== $root && ! str_starts_with($worktree, $root.'/'))) {
            throw new OrbitRepositoryFailed('Orbit returned a worktree outside the configured worktree root.');
        }

        try {
            $headResult = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The prepared worktree Git HEAD could not be inspected.', 0, $exception);
        }

        $headSha = trim($headResult->output());

        if ($headResult->failed() || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $headSha) !== 1) {
            throw new OrbitRepositoryFailed('Could not resolve a valid Git HEAD for the prepared worktree.');
        }

        return new PreparedWorktree($worktree, $headSha);
    }

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck
    {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree->path);

        if ($repository === false || $root === false || $path === false || ! is_dir($path)
            || ($path !== $root && ! str_starts_with($path, $root.'/'))
            || preg_match('/^[a-f0-9]{40}$/', $worktree->headSha) !== 1) {
            throw new OrbitRepositoryFailed('The configured Orbit candidate check is unavailable.');
        }

        try {
            $result = Process::path($path)->timeout(3600)->run(['composer', 'check']);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit candidate check could not run.', 0, $exception);
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit candidate check failed: '.$details);
        }

        if (preg_match_all('/receipt: (.+)$/m', $result->output(), $matches) !== 1) {
            throw new OrbitRepositoryFailed('Orbit candidate check did not return one receipt path.');
        }

        $reportedReceipt = trim($matches[1][0]);

        try {
            $treeResult = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $commonResult = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The checked candidate Git metadata could not be inspected.', 0, $exception);
        }

        $treeSha = trim($treeResult->output());
        $common = realpath(trim($commonResult->output()));

        if ($treeResult->failed() || $commonResult->failed()
            || preg_match('/^[a-f0-9]{40}$/', $treeSha) !== 1 || $common === false || ! is_dir($common)) {
            throw new OrbitRepositoryFailed('The checked candidate Git metadata is invalid.');
        }

        $receiptPath = realpath($reportedReceipt);
        $expectedDirectory = $common.'/orbit-checks/'.$worktree->headSha;

        if ($receiptPath === false || is_link($reportedReceipt) || ! is_file($receiptPath)
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

        if (! is_array($receipt) || ! $this->validCandidateReceipt($receipt, $worktree, $treeSha, $path)) {
            throw new OrbitRepositoryFailed('Orbit candidate check returned an invalid receipt.');
        }

        return new CandidateCheck($receiptPath, $worktree->headSha, $treeSha);
    }

    /** @param array<mixed, mixed> $receipt */
    private function validCandidateReceipt(array $receipt, PreparedWorktree $worktree, string $treeSha, string $path): bool
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
