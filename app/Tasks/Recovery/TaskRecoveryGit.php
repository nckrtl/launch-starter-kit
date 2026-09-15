<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;

final class TaskRecoveryGit
{
    public function verifyResumable(TaskRecoveryEvidence $evidence): string
    {
        $head = $this->verify($evidence);
        $worktree = TaskRecoveryEvidence::string($evidence->workspace, 'worktree');
        $common = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $directory = $this->git($worktree, ['rev-parse', '--absolute-git-dir']);
        if ($common === $directory || realpath($common) !== $common || realpath($directory) !== $directory) {
            throw new LogicException('Resume requires a canonical linked Git administration directory.');
        }
        foreach (explode("\0\0", $this->git($worktree, ['worktree', 'list', '--porcelain', '-z'])) as $record) {
            $fields = explode("\0", $record);
            if ($fields[0] === 'worktree '.$worktree && array_any($fields, fn (string $field): bool => str_starts_with($field, 'prunable'))) {
                throw new LogicException('The recovered worktree registration is stale.');
            }
        }
        $sparse = $this->run($worktree, ['config', '--bool', '--get', 'core.sparseCheckout']);
        if (($sparse->successful() && trim($sparse->output()) !== 'false') || (! $sparse->successful() && $sparse->exitCode() !== 1)) {
            throw new LogicException('Sparse recovered worktrees are not supported.');
        }
        foreach (explode("\0", $this->git($worktree, ['ls-files', '--stage', '-z'])) as $entry) {
            if ($entry !== '' && preg_match('/\A(?:100644|100755|120000) [a-f0-9]+ 0\t/s', $entry) !== 1) {
                throw new LogicException('The recovered index contains conflicts, submodules, or unsupported entries.');
            }
        }
        foreach (explode("\0", $this->git($worktree, ['ls-tree', '-r', '-z', 'HEAD'])) as $entry) {
            if (str_starts_with($entry, '160000 ')) {
                throw new LogicException('Recovered worktrees with submodules are not supported.');
            }
        }
        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer'] as $state) {
            if (file_exists($this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-path', $state]))) {
                throw new LogicException('Finish the active Git operation before resuming the recovered feature.');
            }
        }

        return $head;
    }

    public function verify(TaskRecoveryEvidence $evidence): string
    {
        $repository = TaskRecoveryEvidence::string($evidence->workspace, 'repository');
        $worktree = TaskRecoveryEvidence::string($evidence->workspace, 'worktree');
        foreach ([$repository, $worktree] as $directory) {
            if ($directory === '/' || realpath($directory) !== $directory || ! is_dir($directory)) {
                throw new LogicException('Recovery Git paths must be canonical existing directories.');
            }
        }
        if ($repository === $worktree || ! is_file($worktree.'/.git') || is_link($worktree.'/.git')
            || $this->git($repository, ['rev-parse', '--show-toplevel']) !== $repository
            || $this->git($worktree, ['rev-parse', '--show-toplevel']) !== $worktree
            || $this->git($repository, ['rev-parse', '--path-format=absolute', '--git-common-dir'])
                !== $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir'])
            || ! str_contains($this->git($repository, ['worktree', 'list', '--porcelain', '-z']), 'worktree '.$worktree."\0")) {
            throw new LogicException('Recovery requires the recorded registered linked worktree.');
        }
        $filters = $this->run($worktree, ['config', '--get-regexp', '^filter\..*\.(clean|process)$']);
        if ($filters->exitCode() !== 1) {
            throw new LogicException('Recovery Git inspection cannot execute external filters.');
        }
        foreach (explode("\0", $this->git($worktree, ['ls-files', '-v', '-z'])) as $entry) {
            if ($entry !== '' && ($entry[0] === 'S' || ctype_lower($entry[0]))) {
                throw new LogicException('Recovery rejects hidden index flags.');
            }
        }
        if ($this->git($worktree, ['status', '--porcelain=v1', '-z', '--untracked-files=all']) !== '') {
            throw new LogicException('Recovery requires a clean worktree.');
        }
        $parent = TaskRecoveryEvidence::string($evidence->workspace, 'base_sha');
        foreach ($evidence->children as $child) {
            $headers = explode("\n\n", $this->git($worktree, ['cat-file', 'commit', $child['commit']]), 2)[0];
            preg_match_all('/^parent ([a-f0-9]+)$/m', $headers, $parents);
            preg_match('/^tree ([a-f0-9]+)$/m', $headers, $tree);
            if ($child['parent'] !== $parent || $parents[1] !== [$parent] || ($tree[1] ?? null) !== $child['tree']) {
                throw new LogicException('The real Git commit chain does not match the acknowledged approvals.');
            }
            $parent = $child['commit'];
        }
        if ($this->git($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']) !== $parent) {
            throw new LogicException('Recovery requires HEAD at the final acknowledged child commit.');
        }

        return $parent;
    }

    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): string
    {
        return rtrim($this->run($directory, $arguments)->throw()->output(), "\n");
    }

    /** @param list<string> $arguments */
    private function run(string $directory, array $arguments): ProcessResult
    {
        return Process::path($directory)->timeout(30)->env(array_merge(TaskProcessEnvironment::isolated(), [
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_NO_LAZY_FETCH' => '1', 'LC_ALL' => 'C',
        ]))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...$arguments]);
    }
}
