<?php

declare(strict_types=1);

namespace App\Tasks\Orbit\Proof;

use App\Models\TaskWorkspace;
use App\Tasks\Landing\TaskLandingData;
use LogicException;

final readonly class TaskProofContract
{
    /** @return list<array{path:string,mode:string,sha256:string,contents:string}> */
    public function read(TaskWorkspace $workspace): array
    {
        $issue = $workspace->source_key;
        $planPath = '.loop/proof/'.$issue.'.json';
        $planFile = $this->regular($workspace, $planPath);
        $plan = $planFile['contents'];
        $decoded = json_decode($plan, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded) || ($decoded['snapshot_replacement'] ?? null) !== true) {
            throw new LogicException('The exact proof plan must declare snapshot_replacement true.');
        }
        $declared = $decoded['inputs'] ?? [];
        if (! is_array($declared) || ! array_is_list($declared) || count($declared) > 100) {
            throw new LogicException('The proof fixture declaration is invalid.');
        }
        $paths = [$planPath => true];
        foreach ($declared as $path) {
            if (! is_string($path) || preg_match('#\A(?!/)[A-Za-z0-9._/-]+\z#D', $path) !== 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== [] || isset($paths[$path])) {
                throw new LogicException('Proof fixtures must use unique safe repository-relative paths.');
            }
            if (str_starts_with($path, '.loop/') && preg_match('#\A\.loop/proof/[a-z0-9][a-z0-9._-]{0,127}\z#D', $path) !== 1) {
                throw new LogicException('Native proof fixtures must be explicitly declared flat files beside the issue plan.');
            }
            $full = $workspace->worktree.'/'.$path;
            if (is_link($full) || ! file_exists($full)) {
                throw new LogicException('A declared proof fixture is missing or redirected.');
            }
            if (is_file($full)) {
                $paths[$path] = true;

                continue;
            }
            if (! is_dir($full) || realpath($full) !== $full) {
                throw new LogicException('A declared proof fixture is not a canonical file or directory.');
            }
            $expanded = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $fixture) {
                if (! $fixture instanceof \SplFileInfo || $fixture->isLink() || ! $fixture->isFile()) {
                    throw new LogicException('A declared proof fixture directory contains an unsafe entry.');
                }
                $expanded[] = $path.'/'.substr($fixture->getPathname(), strlen($full) + 1);
                if (count($expanded) + count($paths) > 1000) {
                    throw new LogicException('The proof contract contains too many expanded files.');
                }
            }
            sort($expanded, SORT_STRING);
            if ($expanded === []) {
                throw new LogicException('A declared proof fixture directory is empty.');
            }
            foreach ($expanded as $expandedPath) {
                if (isset($paths[$expandedPath])) {
                    throw new LogicException('Expanded proof fixture paths must be unique.');
                }
                $paths[$expandedPath] = true;
            }
        }
        $directory = $workspace->worktree.'/.loop/proof';
        if (is_link($directory) || ! is_dir($directory) || realpath($directory) !== $directory) {
            throw new LogicException('The proof contract directory is unsafe or missing.');
        }
        $seen = [];
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (! $file instanceof \SplFileInfo) {
                throw new LogicException('The proof directory contains an invalid entry.');
            }
            $path = '.loop/proof/'.substr($file->getPathname(), strlen($directory) + 1);
            if (($path !== $planPath && (preg_match('#\A\.loop/proof/[a-z0-9][a-z0-9._-]{0,127}\z#D', $path) !== 1 || str_contains($file->getFilename(), '..')))
                || $file->isLink() || ! $file->isFile()) {
                throw new LogicException('The proof directory contains an unexpected or unsafe input.');
            }
            $seen[$path] = true;
        }
        $localPaths = array_filter($paths, fn (string $path): bool => str_starts_with($path, '.loop/proof/'), ARRAY_FILTER_USE_KEY);
        if (array_diff_key($localPaths, $seen) !== []) {
            throw new LogicException('A declared proof fixture is missing.');
        }
        ksort($seen, SORT_STRING);
        $paths += $seen;

        $contract = [];
        $bytes = 0;
        if (count($paths) > 1000) {
            throw new LogicException('The proof contract contains too many expanded files.');
        }
        foreach (array_keys($paths) as $path) {
            $file = $path === $planPath ? $planFile : $this->regular($workspace, $path);
            $contents = $file['contents'];
            $sha256 = hash('sha256', $contents);
            $bytes += strlen($contents);
            if ($bytes > 8_388_608) {
                throw new LogicException('The proof contract is too large.');
            }
            $contract[] = ['path' => $path, 'mode' => $file['mode'], 'sha256' => $sha256, 'contents' => $contents];
        }

        return $contract;
    }

    /** @return array{mode:string,contents:string} */
    private function regular(TaskWorkspace $workspace, string $relative): array
    {
        $path = $workspace->worktree.'/'.$relative;
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
            || (str_starts_with($relative, '.loop/') && ($stat['mode'] & 0111) !== 0)) {
            throw new LogicException('Proof inputs must be regular files; ignored proof files must be non-executable.');
        }
        try {
            return ['mode' => ($stat['mode'] & 0100) !== 0 ? '100755' : '100644',
                'contents' => TaskLandingData::file($path, 1_048_576)];
        } catch (\Throwable $exception) {
            throw new LogicException('Proof inputs must be canonical bounded UTF-8 without NUL bytes.', previous: $exception);
        }
    }
}
