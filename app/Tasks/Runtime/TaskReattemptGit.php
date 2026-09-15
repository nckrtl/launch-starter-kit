<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class TaskReattemptGit
{
    public function __construct(private GitTaskWorktree $worktrees) {}

    /** @return array<string, string> */
    public function observe(string $repository, string $worktree, ?string $retain = null): array
    {
        $head = $this->worktrees->inspectAssignment($repository, $worktree);
        $branch = $this->git($worktree, ['symbolic-ref', 'HEAD']);
        $directory = $this->git($worktree, ['rev-parse', '--absolute-git-dir']);
        $common = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $index = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'index']);
        $indexHash = (string) hash_file('sha256', $index);
        $trees = $this->temporary($worktree, $retain === null, function (array $environment) use ($worktree, $index): array {
            if (! copy($index, $environment['GIT_INDEX_FILE'])) {
                throw new RuntimeException('The original task index could not be preserved.');
            }
            $entries = $this->git($worktree, ['ls-files', '--stage', '-z']);
            $this->git($worktree, ['read-tree', '--empty'], $environment);
            $this->git($worktree, ['update-index', '-z', '--index-info'], $environment, $entries);
            $indexTree = $this->git($worktree, ['write-tree'], $environment);
            $this->git($worktree, ['add', '--all', '--', '.'], $environment);
            $tree = $this->git($worktree, ['write-tree'], $environment);
            foreach (explode("\0", $this->git($worktree, ['ls-tree', '-r', '-z', $tree], $environment)) as $entry) {
                if (str_starts_with($entry, '160000 ')) {
                    throw new RuntimeException('Reattempts cannot preserve embedded repositories.');
                }
            }

            return ['tree' => $tree, 'index_tree' => $indexTree];
        });
        if ($head !== $this->worktrees->head($worktree) || $branch !== $this->git($worktree, ['symbolic-ref', 'HEAD'])
            || $directory !== $this->git($worktree, ['rev-parse', '--absolute-git-dir'])
            || $common !== $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-common-dir'])
            || $indexHash !== hash_file('sha256', $index)) {
            throw new RuntimeException('Task Git identity or index changed during observation.');
        }
        if ($retain !== null) {
            $this->retain($worktree, $retain, $trees);
        }

        return ['head' => $head, 'branch' => $branch, 'git_directory' => $directory, 'common_directory' => $common,
            ...$trees, 'index_sha256' => $indexHash];
    }

    public function prerequisite(string $worktree, string $base, string $candidate, string $merge, string $main, bool $remote = true, bool $preserveHistory = false): void
    {
        $this->worktrees->assertLocalObjects($worktree);
        if ($base === $main) {
            throw new RuntimeException('A reattempt requires a distinct verified upstream base.');
        }
        foreach ([$base, $candidate, $merge, $main] as $commit) {
            if ($this->git($worktree, ['rev-parse', '--verify', $commit.'^{commit}']) !== $commit) {
                throw new RuntimeException('The prerequisite must identify exact existing commits.');
            }
        }
        $parents = explode(' ', $this->git($worktree, ['show', '--no-patch', '--format=%P', $merge]));
        if (count($parents) !== 2 || $parents[1] !== $candidate) {
            throw new RuntimeException('The prerequisite merge must retain the exact reviewed candidate as its second parent.');
        }
        if ($preserveHistory) {
            $this->mergedTree($worktree, $base, $main);
        } else {
            $this->git($worktree, ['merge-base', '--is-ancestor', $base, $main]);
        }
        $this->git($worktree, ['merge-base', '--is-ancestor', $merge, $main]);
        if ($this->mergedTree($worktree, $parents[0], $parents[1]) !== $this->git($worktree, ['rev-parse', $merge.'^{tree}'])) {
            throw new RuntimeException('The prerequisite merge differs from the conflict-free reviewed merge tree.');
        }
        if ($remote && $this->git($worktree, ['ls-remote', '--exit-code', 'origin', 'refs/heads/main']) !== $main."\trefs/heads/main") {
            throw new RuntimeException('Authoritative main no longer matches the pinned verified prerequisite.');
        }
    }

    public function integration(string $worktree, string $base, string $main, string $head): void
    {
        $this->worktrees->assertLocalObjects($worktree);
        if ($this->git($worktree, ['rev-parse', '--verify', $head.'^{commit}']) !== $head
            || $this->git($worktree, ['show', '--no-patch', '--format=%P', $head]) !== $base.' '.$main) {
            throw new RuntimeException('The integration commit must retain exactly the original base and verified main as ordered parents.');
        }
        if ($this->mergedTree($worktree, $base, $main) !== $this->git($worktree, ['rev-parse', $head.'^{tree}'])) {
            throw new RuntimeException('The integration commit differs from the exact conflict-free merge tree.');
        }
    }

    private function mergedTree(string $worktree, string $first, string $second): string
    {
        if (preg_match('/(?:\A|\x00)merge\.[^\n]*\.driver\n/', $this->git($worktree, ['config', '--null', '--list'])) === 1) {
            throw new RuntimeException('Reattempt preview cannot execute configured external merge drivers.');
        }

        return $this->temporary($worktree, true, fn (array $environment): string => $this->git($worktree, ['merge-tree', '--write-tree', $first, $second], $environment));
    }

    /** @param array<string, string> $checkpoint
     * @return array{tree:string,index_tree:string}
     */
    public function restored(string $worktree, array $checkpoint, string $main): array
    {
        $this->worktrees->assertLocalObjects($worktree);

        return $this->temporary($worktree, true, function (array $environment) use ($worktree, $checkpoint, $main): array {
            $trees = [];
            foreach (['tree', 'index_tree'] as $kind) {
                $patch = $this->git($worktree, ['diff', '--binary', '--full-index', '--no-ext-diff', '--no-textconv', $checkpoint['head'], $checkpoint[$kind], '--'], trim: false);
                $this->git($worktree, ['read-tree', $main], $environment);
                if ($patch !== '') {
                    $this->git($worktree, ['apply', '--cached', '--binary', '--whitespace=nowarn'], $environment, $patch);
                }
                $trees[$kind] = $this->git($worktree, ['write-tree'], $environment);
            }

            return $trees;
        });
    }

    /** @param array<string, string> $observation */
    public function assertRetained(string $worktree, string $identity, array $observation): void
    {
        $this->worktrees->assertLocalObjects($worktree);
        $prefix = $this->retentionPrefix($identity);
        $expected = $prefix.'/index_tree '.$observation['index_tree']." \n".$prefix.'/tree '.$observation['tree'].' ';
        if ($this->retentionRefs($worktree, $prefix) !== $expected) {
            throw new RuntimeException('The exact retained checkpoint tree refs are missing or changed; no repair is automatic.');
        }
        foreach (['tree', 'index_tree'] as $kind) {
            if ($this->git($worktree, ['cat-file', '-t', $observation[$kind]]) !== 'tree') {
                throw new RuntimeException('Retained checkpoint refs must identify existing tree objects.');
            }
        }
    }

    /** @param array<string, string> $trees */
    private function retain(string $worktree, string $identity, array $trees): void
    {
        $prefix = $this->retentionPrefix($identity);
        if ($this->retentionRefs($worktree, $prefix) === '') {
            $this->git($worktree, ['update-ref', '--no-deref', '--stdin'], input: "start\n"
                .'create '.$prefix.'/tree '.$trees['tree']."\n"
                .'create '.$prefix.'/index_tree '.$trees['index_tree']."\nprepare\ncommit\n");
        }
        $this->assertRetained($worktree, $identity, $trees);
    }

    private function retentionPrefix(string $identity): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $identity) !== 1) {
            throw new RuntimeException('Invalid checkpoint retention identity.');
        }

        return 'refs/commander/task-reattempts/'.$identity;
    }

    private function retentionRefs(string $worktree, string $prefix): string
    {
        return $this->git($worktree, ['for-each-ref', '--sort=refname', '--format=%(refname) %(objectname) %(symref)', $prefix.'/']);
    }

    /** @template T
     * @param  Closure(array<string, string>):T  $callback
     * @return T
     */
    private function temporary(string $worktree, bool $isolatedObjects, Closure $callback): mixed
    {
        $directory = trim(Process::env(TaskProcessEnvironment::isolated())->timeout(10)->run(['mktemp', '-d', sys_get_temp_dir().'/commander-reattempt-XXXXXX'])->throw()->output());
        try {
            $environment = ['GIT_INDEX_FILE' => $directory.'/index'];
            if ($isolatedObjects) {
                $objects = $this->git($worktree, ['rev-parse', '--path-format=absolute', '--git-path', 'objects']);
                if (str_contains($objects, PATH_SEPARATOR)) {
                    throw new RuntimeException('The Git object directory contains an unsupported path separator.');
                }
                File::makeDirectory($directory.'/objects');
                $environment += ['GIT_OBJECT_DIRECTORY' => $directory.'/objects', 'GIT_ALTERNATE_OBJECT_DIRECTORIES' => $objects];
            }

            return $callback($environment);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @param list<string> $arguments
     * @param  array<string, string>  $environment
     */
    private function git(string $worktree, array $arguments, array $environment = [], ?string $input = null, bool $trim = true): string
    {
        $result = Process::path($worktree)->timeout(30)->input($input)->env(array_merge(TaskProcessEnvironment::isolated(), [
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
            'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_LITERAL_PATHSPECS' => '1', 'LC_ALL' => 'C',
            'GIT_NO_LAZY_FETCH' => '1',
        ], $environment))->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
            '-c', 'core.untrackedCache=false', '-c', 'core.fileMode=true', '-c', 'core.symlinks=true',
            '-c', 'core.ignoreStat=false', '-c', 'core.ignoreCase=false', '-c', 'core.splitIndex=false', ...$arguments]);
        if ($result->failed()) {
            throw new RuntimeException('Reattempt Git observation failed: '.trim($result->errorOutput()));
        }

        return $trim ? rtrim($result->output(), "\n") : $result->output();
    }
}
