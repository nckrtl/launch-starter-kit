<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ProcessOrbitRepository implements OrbitRepository
{
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
}
