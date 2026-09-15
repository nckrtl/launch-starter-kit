<?php

use App\Tasks\Runtime\GitTaskWorktree;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->taskGitDirectory = trim(Process::timeout(10)->run([
        'mktemp', '-d', sys_get_temp_dir().'/commander-task-git-XXXXXX',
    ])->throw()->output());
    $this->taskGitRepository = $this->taskGitDirectory.'/repository';
    $this->taskGitWorktree = $this->taskGitDirectory.'/task worktree';
    File::makeDirectory($this->taskGitRepository);
    taskGitCommand($this->taskGitRepository, ['init', '--initial-branch=main']);
    taskGitCommand($this->taskGitRepository, ['config', 'user.email', 'tasks@example.test']);
    taskGitCommand($this->taskGitRepository, ['config', 'user.name', 'Task Test']);
    File::put($this->taskGitRepository.'/.gitignore', ".env\nvendor/\n");
    File::put($this->taskGitRepository.'/tracked.txt', "original\n");
    File::put($this->taskGitRepository.'/deleted.txt', "delete me\n");
    File::put($this->taskGitRepository.'/executable.sh', "#!/bin/sh\n");
    taskGitCommand($this->taskGitRepository, ['add', '--all']);
    taskGitCommand($this->taskGitRepository, ['commit', '--message=Base']);
    taskGitCommand($this->taskGitRepository, ['worktree', 'add', '-b', 'task-test', $this->taskGitWorktree]);
    $this->taskGitBase = trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', 'HEAD']));
    $this->taskGit = new GitTaskWorktree;
});

afterEach(fn () => File::deleteDirectory($this->taskGitDirectory));

/** @param list<string> $arguments */
function taskGitCommand(string $directory, array $arguments, ?string $input = null): string
{
    return Process::path($directory)->timeout(10)->input($input)->env([
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_TERMINAL_PROMPT' => '0',
    ])->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgSign=false', ...$arguments])->throw()->output();
}

it('validates a registered linked checkout while preserving ignored runtime files', function () {
    File::put($this->taskGitWorktree.'/.env', 'runtime secret');
    File::makeDirectory($this->taskGitWorktree.'/vendor');
    File::put($this->taskGitWorktree.'/vendor/runtime.php', '<?php');

    expect($this->taskGit->validate($this->taskGitRepository, $this->taskGitWorktree))->toBe($this->taskGitBase)
        ->and(File::get($this->taskGitWorktree.'/.env'))->toBe('runtime secret')
        ->and(File::exists($this->taskGitWorktree.'/vendor/runtime.php'))->toBeTrue();
});

it('rejects primary, noncanonical, unregistered and unrelated checkouts', function (string $case) {
    $repository = $this->taskGitRepository;
    $worktree = $this->taskGitWorktree;

    if ($case === 'primary') {
        $worktree = $repository;
    } elseif ($case === 'noncanonical') {
        $worktree .= '/.';
    } elseif ($case === 'symlink') {
        symlink($worktree, $this->taskGitDirectory.'/alias');
        $worktree = $this->taskGitDirectory.'/alias';
    } elseif ($case === 'unregistered') {
        File::makeDirectory($this->taskGitDirectory.'/copy');
        File::copy($worktree.'/.git', $this->taskGitDirectory.'/copy/.git');
        $worktree = $this->taskGitDirectory.'/copy';
    } else {
        $repository = $this->taskGitDirectory.'/unrelated';
        File::makeDirectory($repository);
        taskGitCommand($repository, ['init']);
    }

    expect(fn () => $this->taskGit->validate($repository, $worktree))->toThrow(RuntimeException::class);
})->with(['primary', 'noncanonical', 'symlink', 'unregistered', 'unrelated']);

it('rejects dirty initial worktrees including mode changes and untracked files', function (string $case) {
    if ($case === 'untracked') {
        File::put($this->taskGitWorktree.'/new.txt', 'new');
    } elseif ($case === 'mode') {
        chmod($this->taskGitWorktree.'/executable.sh', 0755);
        taskGitCommand($this->taskGitWorktree, ['config', 'core.fileMode', 'false']);
    } else {
        File::put($this->taskGitWorktree.'/tracked.txt', 'changed');

        if ($case === 'staged') {
            taskGitCommand($this->taskGitWorktree, ['add', 'tracked.txt']);
        }
    }

    expect(fn () => $this->taskGit->validate($this->taskGitRepository, $this->taskGitWorktree))
        ->toThrow(RuntimeException::class, 'clean');
})->with(['unstaged', 'staged', 'untracked', 'mode']);

it('captures the full tree in a durable object without modifying the real checkout or index', function () {
    File::put($this->taskGitWorktree.'/tracked.txt', "staged version\n");
    File::put($this->taskGitWorktree.'/staged-new.txt', "new staged\n");
    taskGitCommand($this->taskGitWorktree, ['add', '--all']);
    File::put($this->taskGitWorktree.'/tracked.txt', "final unstaged version\n");
    File::put($this->taskGitWorktree."/new\nfile.txt", "new untracked\n");
    File::put($this->taskGitWorktree.'/.env', 'ignored runtime');
    File::makeDirectory($this->taskGitWorktree.'/vendor');
    File::put($this->taskGitWorktree.'/vendor/cache.txt', 'ignored cache');
    File::delete($this->taskGitWorktree.'/deleted.txt');
    chmod($this->taskGitWorktree.'/executable.sh', 0755);
    symlink('tracked.txt', $this->taskGitWorktree.'/linked.txt');
    $index = trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', '--path-format=absolute', '--git-path', 'index']));
    $indexBefore = File::get($index);
    $statusBefore = taskGitCommand($this->taskGitWorktree, ['status', '--porcelain=v1', '-z']);

    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);
    $entries = taskGitCommand($this->taskGitWorktree, ['ls-tree', '-r', '-z', $tree]);

    expect(taskGitCommand($this->taskGitWorktree, ['show', $tree.':tracked.txt']))->toBe("final unstaged version\n")
        ->and(taskGitCommand($this->taskGitWorktree, ['show', $tree.':staged-new.txt']))->toBe("new staged\n")
        ->and(taskGitCommand($this->taskGitWorktree, ['show', $tree.":new\nfile.txt"]))->toBe("new untracked\n")
        ->and($entries)->toContain('100755 blob', '120000 blob')->not->toContain('deleted.txt', '.env', 'vendor/')
        ->and(File::get($index))->toBe($indexBefore)
        ->and(taskGitCommand($this->taskGitWorktree, ['status', '--porcelain=v1', '-z']))->toBe($statusBefore)
        ->and($this->taskGit->head($this->taskGitWorktree))->toBe($this->taskGitBase)
        ->and(trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', 'refs/commander/task-trees/'.$tree])))->toBe($tree)
        ->and(File::get($this->taskGitWorktree.'/.env'))->toBe('ignored runtime')
        ->and(File::get($this->taskGitWorktree.'/vendor/cache.txt'))->toBe('ignored cache');

    $this->taskGit->assertSnapshot($this->taskGitWorktree, $this->taskGitBase, $tree);
    taskGitCommand($this->taskGitWorktree, ['gc', '--prune=now']);
    expect(trim(taskGitCommand($this->taskGitWorktree, ['cat-file', '-t', $tree])))->toBe('tree');
});

it('retains intentionally staged ignored files and intent-to-add content', function () {
    File::put($this->taskGitWorktree.'/.env', 'intentionally tracked');
    taskGitCommand($this->taskGitWorktree, ['add', '--force', '.env']);
    File::put($this->taskGitWorktree.'/intent.txt', 'intent content');
    taskGitCommand($this->taskGitWorktree, ['add', '--intent-to-add', 'intent.txt']);

    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);

    expect(taskGitCommand($this->taskGitWorktree, ['show', $tree.':.env']))->toBe('intentionally tracked')
        ->and(taskGitCommand($this->taskGitWorktree, ['show', $tree.':intent.txt']))->toBe('intent content');
});

it('rejects changes after the review snapshot', function () {
    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);
    File::put($this->taskGitWorktree.'/unreviewed.txt', 'drift');

    expect(fn () => $this->taskGit->assertSnapshot($this->taskGitWorktree, $this->taskGitBase, $tree))
        ->toThrow(RuntimeException::class, 'changed after');
});

it('accepts the actual clean single commit with the exact reviewed tree', function () {
    File::put($this->taskGitWorktree.'/tracked.txt', 'reviewed change');
    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);
    taskGitCommand($this->taskGitWorktree, ['add', '--all']);
    taskGitCommand($this->taskGitWorktree, ['commit', '--message=Task']);
    File::put($this->taskGitWorktree.'/.env', 'ignored runtime');

    $commit = $this->taskGit->acceptedCommit($this->taskGitWorktree, $this->taskGitBase, $tree);

    expect($commit->sha)->toBe(trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', 'HEAD'])))
        ->and($commit->treeSha)->toBe($tree)
        ->and($commit->parentShas)->toBe([$this->taskGitBase]);
});

it('rejects incomplete or drifting acceptance histories', function (string $case) {
    File::put($this->taskGitWorktree.'/tracked.txt', 'reviewed change');
    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);

    if ($case === 'wrong tree') {
        File::put($this->taskGitWorktree.'/tracked.txt', 'unreviewed change');
    }

    taskGitCommand($this->taskGitWorktree, ['add', '--all']);

    if ($case !== 'no commit') {
        taskGitCommand($this->taskGitWorktree, ['commit', '--message=Task']);
    }

    if ($case === 'extra commit') {
        taskGitCommand($this->taskGitWorktree, ['commit', '--allow-empty', '--message=Extra']);
    } elseif ($case === 'untracked') {
        File::put($this->taskGitWorktree.'/leftover.txt', 'leftover');
    } elseif ($case === 'unstaged') {
        File::put($this->taskGitWorktree.'/tracked.txt', 'leftover');
    } elseif ($case === 'merge') {
        $parent = trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', 'HEAD']));
        $merge = trim(taskGitCommand($this->taskGitWorktree, ['commit-tree', $tree, '-p', $this->taskGitBase, '-p', $parent, '-m', 'Merge']));
        taskGitCommand($this->taskGitWorktree, ['update-ref', 'HEAD', $merge]);
    }

    expect(fn () => $this->taskGit->acceptedCommit($this->taskGitWorktree, $this->taskGitBase, $tree))
        ->toThrow(RuntimeException::class);
})->with(['no commit', 'wrong tree', 'extra commit', 'untracked', 'unstaged', 'merge']);

it('rejects unsupported Git index and checkout features', function (string $case) {
    if ($case === 'skip-worktree' || $case === 'assume-unchanged') {
        taskGitCommand($this->taskGitWorktree, ['update-index', '--'.$case, 'tracked.txt']);
        File::put($this->taskGitWorktree.'/tracked.txt', 'hidden change');
    } elseif ($case === 'sparse') {
        taskGitCommand($this->taskGitWorktree, ['sparse-checkout', 'init', '--cone']);
    } elseif ($case === 'conflict') {
        $blob = trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', 'HEAD:tracked.txt']));
        taskGitCommand($this->taskGitWorktree, ['update-index', '--index-info'], "100644 {$blob} 1\ttracked.txt\n");
    } elseif ($case === 'submodule') {
        taskGitCommand($this->taskGitWorktree, ['update-index', '--add', '--cacheinfo', '160000,'.$this->taskGitBase.',module']);
    } elseif ($case === 'embedded repository') {
        taskGitCommand($this->taskGitWorktree, ['clone', '--local', $this->taskGitRepository, 'embedded']);
    } else {
        taskGitCommand($this->taskGitWorktree, ['config', 'filter.custom.clean', 'touch must-not-run']);
    }

    expect(fn () => $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase))
        ->toThrow(RuntimeException::class)
        ->and(File::exists($this->taskGitWorktree.'/must-not-run'))->toBeFalse();
})->with(['skip-worktree', 'assume-unchanged', 'sparse', 'conflict', 'submodule', 'embedded repository', 'external filter']);

it('rejects a changed base and supports SHA-256 repositories', function () {
    $repository = $this->taskGitDirectory.'/sha256';
    $worktree = $this->taskGitDirectory.'/sha256-task';
    File::makeDirectory($repository);
    taskGitCommand($repository, ['init', '--object-format=sha256']);
    taskGitCommand($repository, ['config', 'user.email', 'tasks@example.test']);
    taskGitCommand($repository, ['config', 'user.name', 'Task Test']);
    taskGitCommand($repository, ['commit', '--allow-empty', '--message=Base']);
    taskGitCommand($repository, ['worktree', 'add', '-b', 'task', $worktree]);
    $base = $this->taskGit->validate($repository, $worktree);
    File::put($worktree.'/new.txt', 'new');
    $tree = $this->taskGit->snapshot($worktree, $base);
    taskGitCommand($worktree, ['add', '--all']);
    taskGitCommand($worktree, ['commit', '--message=Task']);

    expect(strlen($this->taskGit->acceptedCommit($worktree, $base, $tree)->sha))->toBe(64)
        ->and(fn () => $this->taskGit->snapshot($worktree, $base))->toThrow(RuntimeException::class, 'recorded base');
});

it('ignores inherited Git repository and index redirection', function () {
    $variables = [
        'GIT_DIR' => $this->taskGitRepository.'/.git',
        'GIT_WORK_TREE' => $this->taskGitRepository,
        'GIT_INDEX_FILE' => $this->taskGitDirectory.'/must-not-create-index',
        'GIT_CONFIG_COUNT' => '1',
        'GIT_CONFIG_KEY_0' => 'core.bare',
        'GIT_CONFIG_VALUE_0' => 'true',
    ];
    $previous = [];

    foreach ($variables as $key => $value) {
        $previous[$key] = getenv($key);
        putenv($key.'='.$value);
    }

    try {
        expect($this->taskGit->validate($this->taskGitRepository, $this->taskGitWorktree))->toBe($this->taskGitBase);
        $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);
        expect(File::exists($this->taskGitDirectory.'/must-not-create-index'))->toBeFalse();
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
});

it('rehashes tracked files even when cached Git stats hide a same-size change', function () {
    $file = $this->taskGitWorktree.'/tracked.txt';
    $timestamp = time() - 120;
    taskGitCommand($this->taskGitWorktree, ['config', 'core.trustctime', 'false']);
    taskGitCommand($this->taskGitWorktree, ['config', 'core.checkStat', 'minimal']);
    touch($file, $timestamp);
    taskGitCommand($this->taskGitWorktree, ['update-index', '--refresh']);
    File::put($file, "modified\n");
    touch($file, $timestamp);
    expect(taskGitCommand($this->taskGitWorktree, ['status', '--porcelain=v1']))->toBe('');

    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);

    expect(taskGitCommand($this->taskGitWorktree, ['show', $tree.':tracked.txt']))->toBe("modified\n")
        ->and(fn () => $this->taskGit->validate($this->taskGitRepository, $this->taskGitWorktree))
        ->toThrow(RuntimeException::class, 'changed after');
});

it('handles split indexes and distinct filename case without changing the real index', function () {
    taskGitCommand($this->taskGitWorktree, ['update-index', '--split-index']);
    taskGitCommand($this->taskGitWorktree, ['config', 'core.ignoreCase', 'true']);
    $index = trim(taskGitCommand($this->taskGitWorktree, ['rev-parse', '--path-format=absolute', '--git-path', 'index']));
    $before = File::get($index);
    File::put($this->taskGitWorktree.'/TRACKED.txt', 'distinct file');

    $tree = $this->taskGit->snapshot($this->taskGitWorktree, $this->taskGitBase);

    expect(taskGitCommand($this->taskGitWorktree, ['show', $tree.':TRACKED.txt']))->toBe('distinct file')
        ->and(taskGitCommand($this->taskGitWorktree, ['show', $tree.':tracked.txt']))->toBe("original\n")
        ->and(File::get($index))->toBe($before);
});
