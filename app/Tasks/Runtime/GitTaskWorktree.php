<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Tasks\GitObjectId;
use App\Tasks\TaskCommit;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class GitTaskWorktree
{
    public function tree(string $worktree, string $commit): string
    {
        GitObjectId::validate($commit);
        $this->linkedWorktree($worktree);

        return $this->git($worktree, ['rev-parse', '--verify', $commit.'^{tree}']);
    }

    /** @param list<string> $paths */
    public function assertIgnored(string $worktree, array $paths): void
    {
        foreach ($paths as $path) {
            if ($this->git($worktree, ['check-ignore', '--', $path], ['GIT_LITERAL_PATHSPECS' => '0']) !== $path) {
                throw new RuntimeException('Artifact results must contain only ignored proof files.');
            }
        }
    }

    public function validate(string $repository, string $worktree): string
    {
        $head = $this->inspect($repository, $worktree);
        $this->assertSnapshot($worktree, $head, $this->git($worktree, ['rev-parse', 'HEAD^{tree}']));

        return $head;
    }

    public function inspect(string $repository, string $worktree): string
    {
        $this->inspectAssignment($repository, $worktree);
        $this->clean($worktree);

        return $this->head($worktree);
    }

    public function inspectAssignment(string $repository, string $worktree): string
    {
        $this->canonicalDirectory($repository);
        $common = $this->linkedWorktree($worktree);

        if ($repository === $worktree
            || $this->git($repository, ['rev-parse', '--show-toplevel']) !== $repository
            || $this->git($repository, ['rev-parse', '--path-format=absolute', '--git-common-dir']) !== $common) {
            throw new RuntimeException('The task worktree must belong to the configured repository.');
        }

        $this->supported($worktree);

        return $this->head($worktree);
    }

    public function head(string $worktree): string
    {
        $this->linkedWorktree($worktree);
        $sha = $this->git($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']);
        GitObjectId::validate($sha);

        return $sha;
    }

    public function snapshot(string $worktree, string $baseSha, ?string $integrationMainSha = null): string
    {
        GitObjectId::validate($baseSha);
        $this->linkedWorktree($worktree);
        $this->supported($worktree, $integrationMainSha);

        if ($this->head($worktree) !== $baseSha) {
            throw new RuntimeException('The task worktree HEAD changed from its recorded base.');
        }

        $index = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'index']);
        $temporaryIndex = tempnam(sys_get_temp_dir(), 'commander-task-index-');

        if ($temporaryIndex === false) {
            throw new RuntimeException('The task snapshot index could not be allocated.');
        }

        try {
            $environment = ['GIT_INDEX_FILE' => $temporaryIndex];

            if (! is_file($index) || ! copy($index, $temporaryIndex)) {
                throw new RuntimeException('The task worktree index could not be copied.');
            }

            $entries = $this->git($worktree, ['ls-files', '--stage', '-z']);
            $this->git($worktree, ['read-tree', '--empty'], $environment);
            $this->git($worktree, ['update-index', '-z', '--index-info'], $environment, $entries);
            $this->git($worktree, ['add', '--all', '--', '.'], $environment);
            $this->supportedIndex($worktree, $environment);
            $tree = $this->git($worktree, ['write-tree'], $environment);
            GitObjectId::validate($tree);

            if ($this->head($worktree) !== $baseSha) {
                throw new RuntimeException('The task worktree HEAD changed during snapshot capture.');
            }
            if ($integrationMainSha !== null) {
                $this->supported($worktree, $integrationMainSha);
            }

            $this->git($worktree, ['update-ref', 'refs/commander/task-trees/'.$tree, $tree]);

            return $tree;
        } finally {
            @unlink($temporaryIndex.'.lock');
            unlink($temporaryIndex);
        }
    }

    public function assertSnapshot(string $worktree, string $baseSha, string $treeSha, ?string $integrationMainSha = null): void
    {
        GitObjectId::validate($treeSha);

        if ($this->snapshot($worktree, $baseSha, $integrationMainSha) !== $treeSha) {
            throw new RuntimeException('The task worktree changed after its review snapshot.');
        }
    }

    public function acceptedCommit(string $worktree, string $baseSha, string $treeSha, ?string $integrationMainSha = null): TaskCommit
    {
        GitObjectId::validate($baseSha);
        GitObjectId::validate($treeSha);
        $this->supported($worktree);
        $this->clean($worktree);
        $head = $this->head($worktree);
        $headers = explode("\n\n", $this->git($worktree, ['cat-file', 'commit', $head]), 2)[0];
        $tree = '';
        $parents = [];

        foreach (explode("\n", $headers) as $header) {
            if (str_starts_with($header, 'tree ')) {
                $tree = substr($header, 5);
            } elseif (str_starts_with($header, 'parent ')) {
                $parents[] = substr($header, 7);
            }
        }

        $expectedParents = $integrationMainSha === null ? [$baseSha] : [$baseSha, $integrationMainSha];
        if ($parents !== $expectedParents || $tree !== $treeSha || $this->head($worktree) !== $head) {
            throw new RuntimeException('The accepted task requires one commit on its base with the exact reviewed tree.');
        }

        $this->assertSnapshot($worktree, $head, $treeSha);

        return new TaskCommit($head, $tree, $parents, $integrationMainSha);
    }

    /** @return array{head: string, main_sha: string, main_is_ancestor: bool} */
    public function currentMain(string $repository, string $worktree): array
    {
        $head = $this->inspect($repository, $worktree);
        $this->git($worktree, ['fetch', '--no-tags', 'origin', '+refs/heads/main:refs/remotes/origin/main']);
        $main = $this->git($worktree, ['rev-parse', '--verify', 'refs/remotes/origin/main^{commit}']);
        GitObjectId::validate($main);
        $remote = $this->git($worktree, ['ls-remote', '--exit-code', 'origin', 'refs/heads/main']);
        if ($remote !== $main."\trefs/heads/main" || $this->inspect($repository, $worktree) !== $head) {
            throw new RuntimeException('Main or the accepted worktree changed during observation; inspect before retrying.');
        }
        $ancestor = $this->run($worktree, ['merge-base', '--is-ancestor', $main, $head]);
        if (! in_array($ancestor->exitCode(), [0, 1], true)) {
            throw new RuntimeException('The current-main ancestry could not be determined.');
        }

        return ['head' => $head, 'main_sha' => $main, 'main_is_ancestor' => $ancestor->successful()];
    }

    public function isAncestor(string $worktree, string $ancestor, string $descendant): bool
    {
        GitObjectId::validate($ancestor);
        GitObjectId::validate($descendant);
        $result = $this->run($worktree, ['merge-base', '--is-ancestor', $ancestor, $descendant]);
        if (! in_array($result->exitCode(), [0, 1], true)) {
            throw new RuntimeException('The pinned main ancestry could not be determined.');
        }

        return $result->successful();
    }

    private function canonicalDirectory(string $path): void
    {
        if ($path === '/' || ! str_starts_with($path, '/') || realpath($path) !== $path || ! is_dir($path)) {
            throw new RuntimeException('Task Git paths must be existing canonical absolute directories.');
        }
    }

    private function linkedWorktree(string $worktree): string
    {
        $this->canonicalDirectory($worktree);
        $this->assertLocalObjects($worktree);

        if (! is_file($worktree.'/.git') || is_link($worktree.'/.git')
            || $this->git($worktree, ['rev-parse', '--show-toplevel']) !== $worktree) {
            throw new RuntimeException('Tasks require the root of a linked Git worktree, not the primary checkout.');
        }

        $common = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $directory = $this->git($worktree, ['rev-parse', '--absolute-git-dir']);
        $this->canonicalDirectory($common);
        $this->canonicalDirectory($directory);

        if ($common === $directory) {
            throw new RuntimeException('Tasks cannot run in the primary Git checkout.');
        }

        $registered = false;

        foreach (explode("\0\0", $this->git($worktree, ['worktree', 'list', '--porcelain', '-z'])) as $record) {
            $fields = explode("\0", $record);

            if ($fields[0] === 'worktree '.$worktree) {
                $registered = true;

                foreach ($fields as $field) {
                    if (str_starts_with($field, 'prunable')) {
                        throw new RuntimeException('The task worktree registration is stale.');
                    }
                }
            }
        }

        if (! $registered) {
            throw new RuntimeException('The task worktree is not registered in its Git repository.');
        }

        return $common;
    }

    public function assertLocalObjects(string $worktree): void
    {
        $partial = $this->run($worktree, ['config', '--get-regexp', '^(extensions\.partialclone|remote\..*\.(promisor|partialclonefilter))$']);

        if ($partial->successful() || $partial->exitCode() !== 1) {
            throw new RuntimeException('Partial or promisor task repositories are not supported; materialize objects separately.');
        }
    }

    private function supported(string $worktree, ?string $integrationMainSha = null): void
    {
        $this->linkedWorktree($worktree);
        $this->assertSupportedCheckout($worktree, $integrationMainSha);
    }

    public function assertSupportedCheckout(string $worktree, ?string $integrationMainSha = null): void
    {
        $this->canonicalDirectory($worktree);
        $this->assertLocalObjects($worktree);
        $sparse = $this->run($worktree, ['config', '--bool', '--get', 'core.sparseCheckout']);

        if (($sparse->successful() && trim($sparse->output()) !== 'false')
            || (! $sparse->successful() && $sparse->exitCode() !== 1)) {
            throw new RuntimeException('Sparse task worktrees are not supported.');
        }

        $filters = $this->run($worktree, ['config', '--get-regexp', '^filter\..*\.(clean|process)$']);

        if ($filters->successful() || $filters->exitCode() !== 1) {
            throw new RuntimeException('Task snapshots do not support external Git clean or process filters.');
        }

        $this->supportedIndex($worktree);

        foreach (explode("\0", $this->git($worktree, ['ls-tree', '-r', '-z', 'HEAD'])) as $entry) {
            if (str_starts_with($entry, '160000 ')) {
                throw new RuntimeException('Task worktrees with submodules are not supported.');
            }
        }

        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer'] as $state) {
            $path = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-path', $state]);

            if ($state === 'MERGE_HEAD' && $integrationMainSha !== null) {
                GitObjectId::validate($integrationMainSha);
                if (! is_file($path) || is_link($path) || file_get_contents($path) !== $integrationMainSha."\n") {
                    throw new RuntimeException('The integration requires exactly its pinned main in MERGE_HEAD.');
                }

                continue;
            }

            if (file_exists($path)) {
                throw new RuntimeException('Finish the active Git operation before running a task.');
            }
        }
    }

    /** @param array<string, string> $environment */
    private function supportedIndex(string $worktree, array $environment = []): void
    {
        foreach (explode("\0", $this->git($worktree, ['ls-files', '-v', '-z'], $environment)) as $entry) {
            if ($entry !== '' && ($entry[0] === 'S' || ctype_lower($entry[0]))) {
                throw new RuntimeException('Task indexes cannot contain skip-worktree or assume-unchanged entries.');
            }
        }

        foreach (explode("\0", $this->git($worktree, ['ls-files', '--stage', '-z'], $environment)) as $entry) {
            if ($entry === '') {
                continue;
            }

            if (str_starts_with($entry, '160000 ')) {
                throw new RuntimeException('Task worktrees with submodules or embedded repositories are not supported.');
            }

            if (preg_match('/\A(?:100644|100755|120000) [a-f0-9]+ 0\t/s', $entry) !== 1) {
                throw new RuntimeException('The task Git index contains conflicts or unsupported entries.');
            }
        }
    }

    private function clean(string $worktree): void
    {
        if ($this->git($worktree, ['status', '--porcelain=v1', '-z', '--untracked-files=all']) !== '') {
            throw new RuntimeException('The task worktree must be clean, including nonignored untracked files.');
        }
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function git(string $worktree, array $arguments, array $environment = [], ?string $input = null): string
    {
        $result = $this->run($worktree, $arguments, $environment, $input);

        if ($result->failed()) {
            throw new RuntimeException('Task Git inspection failed: '.trim($result->errorOutput()));
        }

        return rtrim($result->output(), "\n");
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function run(string $worktree, array $arguments, array $environment = [], ?string $input = null): ProcessResult
    {
        return Process::path($worktree)->timeout(30)->input($input)->env(array_merge(TaskProcessEnvironment::isolated(), [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0',
            'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_LITERAL_PATHSPECS' => '1',
            'LC_ALL' => 'C',
        ], $environment))->run([
            'git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
            '-c', 'core.untrackedCache=false', '-c', 'core.fileMode=true',
            '-c', 'core.symlinks=true', '-c', 'core.ignoreStat=false',
            '-c', 'core.ignoreCase=false', '-c', 'core.splitIndex=false',
            ...$arguments,
        ]);
    }
}
