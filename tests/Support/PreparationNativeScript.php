<?php

// Executed only by disposable fake Orbit repositories in TaskWorktreePreparationTest.
declare(strict_types=1);

function preparationFixtureRun(array $arguments, string $directory): void
{
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $directory);
    if (! is_resource($process) || proc_close($process) !== 0) {
        exit(94);
    }
}

$repository = getcwd();
$source = $argv[1];
$branch = strtolower($source);
$root = dirname($repository).'/worktrees';
$worktree = $root.'/'.$branch;
$mode = trim(file_get_contents($repository.'/fixture-mode'));
file_put_contents(dirname($repository).'/native-called', 'yes');
preparationFixtureRun(['git', '-c', 'core.hooksPath=/dev/null', 'fetch', '--prune', 'origin'], $repository);
preparationFixtureRun(['git', '-c', 'core.hooksPath=/dev/null', 'merge', '--ff-only', 'origin/main'], $repository);
preparationFixtureRun(['git', '-c', 'core.hooksPath=/dev/null', 'worktree', 'add', '-b', $branch, $worktree, 'origin/main'], $repository);
mkdir($worktree.'/.loop/proof', 0700, true);
file_put_contents($worktree.'/.loop/flow.json', json_encode(['schema' => 1, 'flow' => $mode === 'flow' ? 'proof' : 'discovery'])."\n");
file_put_contents($worktree.'/.loop/plan.md', str_replace(['{{ISSUE}}', '{{FLOW}}'], [$source, 'discovery'], file_get_contents($worktree.'/.agents/skills/planning-features/template.md')));
fwrite(STDOUT, str_repeat('captured stdout ', 1000)."\n");
fwrite(STDERR, str_repeat('captured stderr ', 1000)."\n");
if ($mode === 'nonzero') {
    file_put_contents($worktree.'/.loop/partial', 'keep');
    exit(17);
}
if (in_array($mode, ['timeout', 'orphan', 'lost-parent'], true)) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose(STDOUT);
        fclose(STDERR);
        usleep(1800000);
        file_put_contents($worktree.'/.loop/child-finished', 'child outlived parent');
        exit(0);
    }
    file_put_contents($worktree.'/.loop/child-pid', (string) $pid);
    file_put_contents($worktree.'/.loop/native-pid', (string) getmypid());
    if ($mode === 'orphan') {
        posix_kill(getmypid(), SIGKILL);
    }
    sleep(2);
    file_put_contents($worktree.'/.loop/native-finished', 'native process finished');
    exit(0);
}
if ($mode === 'wrong-head') {
    preparationFixtureRun(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', '-c', 'commit.gpgSign=false',
        '-c', 'core.hooksPath=/dev/null', 'commit', '--allow-empty', '-m', 'Unexpected base'], $worktree);
}
preparationFixtureRun(['cp', '-a', __DIR__.'/../../vendor', $worktree.'/fixture-deps'], $worktree);
foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
    $base = $worktree.'/'.$project;
    mkdir($base.'/vendor');
    symlink($worktree.'/fixture-deps', $base.'/vendor/dependencies');
    file_put_contents($base.'/vendor/autoload.php', '<?php require __DIR__."/dependencies/autoload.php";');
    if (is_file($base.'/.env.example')) {
        copy($base.'/.env.example', $base.'/.env');
    }
}
if ($mode === 'vendor-escape') {
    symlink(dirname($repository), $worktree.'/apps/cli/vendor/escape');
}
if ($mode === 'nested-vendor-escape') {
    symlink(dirname($repository), $worktree.'/fixture-deps/escape');
}
if ($mode === 'testing-dotenv') {
    file_put_contents($worktree.'/apps/gateway/.env.testing', 'DB_URL=unsafe');
}
if ($mode === 'config-cache') {
    mkdir($worktree.'/apps/gateway/bootstrap/cache', 0700, true);
    file_put_contents($worktree.'/apps/gateway/bootstrap/cache/config.php', '<?php return [];');
}
if ($mode === 'dotenv-drift') {
    file_put_contents($worktree.'/apps/gateway/.env', "DB_DATABASE=/unsafe\n");
}
preparationFixtureRun(['composer', '--no-plugins', '--no-interaction', 'run-script', 'fixture-check'], $worktree.'/apps/cli');
fwrite(STDOUT, "\n".($mode === 'wrong-path' ? $root.'/wrong' : $worktree)."\n");
