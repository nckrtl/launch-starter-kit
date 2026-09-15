<?php

declare(strict_types=1);

namespace App\Tasks\Preparation;

use App\Herdr\SocketClient;
use App\Herdr\SocketHerdrRuntime;
use App\Tasks\Runtime\GitTaskWorktree;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Closure;
use Illuminate\Support\Facades\Process;
use LogicException;
use Throwable;

/** @phpstan-import-type PreparationObservation from TaskWorktreePreparation */
final readonly class NativeOrbitTaskWorktreePreparation implements TaskWorktreePreparation
{
    public function __construct(private GitTaskWorktree $git, private TaskPreparationGuard $guard,
        private TaskPreparationConfiguration $configuration) {}

    public function inspect(string $repository, string $root, string $source): array
    {
        $common = $this->guard->common($repository);
        $this->guard->directory($common, $source);
        if (realpath($root) !== $root || ! is_dir($root) || $root === '/'
            || str_starts_with($root.'/', $repository.'/')
            || $this->gitCommand($repository, ['rev-parse', '--show-toplevel']) !== $repository
            || $this->gitCommand($repository, ['rev-parse', '--path-format=absolute', '--git-common-dir']) !== $common) {
            throw new LogicException('Preparation paths must match the canonical primary repository and external worktree root.');
        }
        $this->git->assertLocalObjects($repository);
        $this->cleanPrimary($repository);
        $configuredRoot = $this->gitCommand($repository, ['config', '--local', '--path', '--get', 'orbit.worktreeRoot']);
        if ($configuredRoot !== $root) {
            throw new LogicException('Set the native local orbit.worktreeRoot to the configured canonical root.');
        }
        $branch = strtolower($source);
        $worktree = $root.'/'.$branch;
        if (file_exists($worktree) || is_link($worktree)) {
            throw new LogicException('Preparation only supports a previously absent deterministic worktree path.');
        }
        $refs = $this->gitCommand($repository, ['for-each-ref', '--format=%(refname)', 'refs/heads', 'refs/remotes/origin']);
        foreach (explode("\n", $refs) as $ref) {
            if (preg_match('~\Arefs/(heads|remotes/origin)/'.preg_quote($branch, '~').'(?:\z|[-/])~', $ref) === 1) {
                throw new LogicException('A local or cached remote issue branch already exists.');
            }
        }
        foreach (explode("\0\0", $this->gitCommand($repository, ['worktree', 'list', '--porcelain', '-z'])) as $record) {
            $path = substr(explode("\0", $record)[0], 9);
            if ($path === $worktree || str_starts_with($path, $worktree.'-') || str_contains($record, 'branch refs/heads/'.$branch."\0")) {
                throw new LogicException('A registered worktree already owns this issue path or branch.');
            }
        }
        $remote = $this->remoteMain($repository, $branch);
        $primary = $this->gitCommand($repository, ['rev-parse', '--verify', 'HEAD^{commit}']);
        $inputs = $this->inputs($repository, $primary);
        $this->configuration->assertGuidanceInputs($repository);
        foreach (['bin/worktree-create', 'bin/bootstrap', 'bin/loop-flow', 'bin/tia-cache', 'bin/worktree-cache'] as $script) {
            if (! isset($inputs[$script]) || ! str_starts_with($inputs[$script], '100755 ')
                || realpath($repository.'/'.$script) !== $repository.'/'.$script || ! is_executable($repository.'/'.$script)) {
                throw new LogicException('A required native preparation input is missing or unsafe: '.$script);
            }
        }

        return ['repository' => $repository, 'root' => $root, 'source' => $source, 'worktree' => $worktree,
            'primary' => $primary, 'remote_main' => $remote, 'inputs' => $inputs];
    }

    public function assertUnowned(string $socket, string $worktree): void
    {
        $snapshot = (new SocketHerdrRuntime(new SocketClient($socket)))->snapshot();
        foreach ($snapshot->workspaces as $workspace) {
            if ($workspace->checkoutPath === $worktree) {
                throw new LogicException('A Herdr workspace already owns the preparation path.');
            }
        }
        foreach ([...$snapshot->panes, ...$snapshot->agents] as $entry) {
            if (is_string($entry->workingDirectory)
                && ($entry->workingDirectory === $worktree || str_starts_with($entry->workingDirectory, $worktree.'/'))) {
                throw new LogicException('A Herdr pane or agent already uses the preparation path.');
            }
        }
    }

    public function run(array $before, string $directory, array $environment, int $timeout, Closure $output): array
    {
        $streams = [];
        try {
            foreach (['out' => 'stdout.log', 'err' => 'stderr.log'] as $type => $name) {
                $stream = fopen($directory.'/'.$name, 'x+b');
                if ($stream === false) {
                    throw new LogicException('Cannot create captured preparation logs.');
                }
                chmod($directory.'/'.$name, 0600);
                $streams[$type] = $stream;
            }
            $capture = function (string $type, string $buffer) use ($streams, $output): void {
                $stream = $streams[$type];
                if (fwrite($stream, $buffer) !== strlen($buffer) || ! fflush($stream)) {
                    throw new LogicException('Cannot retain captured preparation output.');
                }
                $output($type, $buffer);
            };
            $repository = $before['repository'];
            $this->materializeMain($before, $directory, $capture);
            if ($this->inspect($repository, $before['root'], $before['source']) !== $before) {
                throw new LogicException('Preparation inputs or ownership refs changed before native setup.');
            }
            try {
                $result = Process::path($repository)->timeout($timeout)->env($environment)
                    ->run([$repository.'/bin/worktree-create', $before['source'], '--flow=discovery'], $capture);
            } catch (Throwable $exception) {
                $this->guard->write($directory.'/native-exit.json', ['exit_code' => null, 'observed_completion' => false,
                    'reason' => $exception::class, 'child_logs_may_be_incomplete' => true]);
                throw $exception;
            }
            $this->guard->write($directory.'/native-exit.json', ['exit_code' => $result->exitCode(),
                'observed_completion' => true, 'captured_stdout_sha256' => hash_file('sha256', $directory.'/stdout.log'),
                'captured_stderr_sha256' => hash_file('sha256', $directory.'/stderr.log')]);
            if ($result->failed()) {
                throw new LogicException('Native worktree preparation exited nonzero; no admission receipt was issued.');
            }
            $lines = preg_split('/\R/', trim($result->output())) ?: [];
            if (end($lines) !== $before['worktree']) {
                throw new LogicException('Native preparation reported a different worktree path.');
            }
            $this->cleanPrimary($repository);
            $head = $this->git->inspect($repository, $before['worktree']);
            if ($head !== $before['remote_main']
                || $this->gitCommand($repository, ['rev-parse', 'HEAD']) !== $head
                || $this->gitCommand($repository, ['rev-parse', 'refs/remotes/origin/main']) !== $head
                || $this->remoteMain($repository, strtolower($before['source'])) !== $head
                || $this->gitCommand($before['worktree'], ['symbolic-ref', '--short', 'HEAD']) !== strtolower($before['source'])
                || $this->gitCommand($repository, ['config', '--local', '--path', '--get', 'orbit.worktreeRoot']) !== $before['root']
                || $this->inputs($repository, $head) !== $before['inputs']) {
                throw new LogicException('Native preparation did not preserve the inspected base, branch, root, and setup inputs.');
            }
            $worktree = $before['worktree'];
            foreach (['.loop', '.loop/proof'] as $path) {
                if (! is_dir($worktree.'/'.$path) || realpath($worktree.'/'.$path) !== $worktree.'/'.$path) {
                    throw new LogicException('Native discovery scaffold is missing or redirected.');
                }
            }
            $flow = $this->jsonFile($worktree.'/.loop/flow.json');
            if ($flow !== ['schema' => 1, 'flow' => 'discovery']) {
                throw new LogicException('Native worktree is not explicitly in discovery flow.');
            }
            $plan = $this->file($worktree.'/.loop/plan.md');
            $template = $this->file($worktree.'/.agents/skills/planning-features/template.md');
            if ($plan !== str_replace(['{{ISSUE}}', '{{FLOW}}'], [$before['source'], 'discovery'], $template)) {
                throw new LogicException('Native preparation scaffold contains unexpected authored changes.');
            }
            $config = $this->configuration->verify($worktree, $environment);
            $this->cleanPrimary($repository);
            if ($this->git->inspect($repository, $worktree) !== $head
                || $this->gitCommand($repository, ['rev-parse', 'HEAD']) !== $head
                || $this->inputs($repository, $head) !== $before['inputs']) {
                throw new LogicException('Preparation identity changed during verification.');
            }

            return ['primary_before' => $before['primary'], 'primary_after' => $head, 'feature_head' => $head,
                'remote_main' => $head, 'inputs_before' => $before['inputs'], 'inputs_after' => $this->inputs($repository, $head),
                'configuration' => $config, 'flow_sha256' => hash_file('sha256', $worktree.'/.loop/flow.json'),
                'plan_sha256' => hash('sha256', $plan), 'proof_preserved' => true];
        } finally {
            foreach ($streams as $stream) {
                fflush($stream);
                fsync($stream);
                fclose($stream);
            }
        }
    }

    /** @param PreparationObservation $before
     * @param  Closure(string, string):void  $capture
     */
    private function materializeMain(array $before, string $directory, Closure $capture): void
    {
        $repository = $before['repository'];
        $sha = $before['remote_main'];
        $refsBefore = $this->gitCommand($repository, ['for-each-ref', '--format=%(refname) %(objectname)']);
        $fetchHead = $repository.'/.git/FETCH_HEAD';
        if (is_link($fetchHead)) {
            throw new LogicException('Unsafe FETCH_HEAD.');
        }
        $fetchBefore = is_file($fetchHead) ? hash_file('sha256', $fetchHead) : null;
        $present = Process::path($repository)->timeout(30)->env($this->gitEnvironment())
            ->run(['git', 'cat-file', '-e', $sha.'^{commit}'])->successful();
        if (! $present) {
            Process::path($repository)->timeout(120)->env($this->gitEnvironment())
                ->run(['git', '-c', 'maintenance.auto=false', '-c', 'gc.auto=0', '-c', 'core.hooksPath=/dev/null',
                    'fetch', '--no-tags', '--no-write-fetch-head', '--no-auto-maintenance', 'origin', $sha], $capture)->throw();
        }
        if ($refsBefore !== $this->gitCommand($repository, ['for-each-ref', '--format=%(refname) %(objectname)'])
            || $fetchBefore !== (is_file($fetchHead) ? hash_file('sha256', $fetchHead) : null)) {
            throw new LogicException('Object-only preflight changed refs or FETCH_HEAD.');
        }
        $remoteInputs = $this->inputs($repository, $sha);
        $this->guard->write($directory.'/preflight.json', ['remote_main' => $sha, 'object_only_fetch' => ! $present,
            'refs_unchanged' => true, 'fetch_head_unchanged' => true, 'remote_inputs' => $remoteInputs]);
        if ($remoteInputs !== $before['inputs']) {
            throw new LogicException('Remote main changes supported bootstrap or configuration inputs; inspect and approve those inputs separately.');
        }
        $this->gitCommand($repository, ['merge-base', '--is-ancestor', $before['primary'], $sha]);
    }

    private function cleanPrimary(string $repository): void
    {
        $this->git->assertSupportedCheckout($repository);
        if ($this->gitCommand($repository, ['symbolic-ref', '--short', 'HEAD']) !== 'main'
            || $this->gitCommand($repository, ['status', '--porcelain=v1', '-z', '--untracked-files=all']) !== '') {
            throw new LogicException('The primary checkout must be clean and on main.');
        }
        $this->assertActualInputs($repository, $this->inputs($repository, $this->gitCommand($repository, ['rev-parse', '--verify', 'HEAD^{commit}'])));
    }

    /** @param array<string, string> $inputs */
    private function assertActualInputs(string $repository, array $inputs): void
    {
        $paths = [];
        $blobs = [];
        foreach ($inputs as $path => $identity) {
            $absolute = $repository.'/'.$path;
            clearstatcache(true, $absolute);
            if (realpath($absolute) !== $absolute || ! is_file($absolute)) {
                throw new LogicException('An actual preparation input is missing or redirected: '.$path);
            }
            $permissions = fileperms($absolute);
            $mode = $permissions !== false && ($permissions & 0100) !== 0 ? '100755' : '100644';
            if ($permissions === false || $mode !== substr($identity, 0, 6)) {
                throw new LogicException('An actual preparation input mode differs from its committed identity: '.$path);
            }
            $paths[] = $absolute;
            $blobs[] = substr($identity, 7);
        }
        if ($paths !== [] && $this->gitCommand($repository, ['hash-object', '--no-filters', '--', ...$paths]) !== implode("\n", $blobs)) {
            throw new LogicException('Actual preparation input bytes differ from their committed identities.');
        }
    }

    private function remoteMain(string $repository, string $branch): string
    {
        $main = null;
        foreach (explode("\n", $this->gitCommand($repository, ['ls-remote', '--heads', 'origin'])) as $line) {
            if (preg_match('/\A([a-f0-9]{40})\trefs\/heads\/(.+)\z/', $line, $match) !== 1) {
                throw new LogicException('The remote returned unsupported branch identities.');
            }
            if ($match[2] === $branch || str_starts_with($match[2], $branch.'-') || str_starts_with($match[2], $branch.'/')) {
                throw new LogicException('A current remote issue branch already exists.');
            }
            if ($match[2] === 'main') {
                $main = $match[1];
            }
        }

        return $main ?? throw new LogicException('The remote has no main branch.');
    }

    /** @return array<string, string> */
    private function inputs(string $repository, string $sha): array
    {
        $inputs = [];
        foreach (explode("\0", $this->gitCommand($repository, ['ls-tree', '-r', '-z', $sha])) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/\A([0-9]{6}) (?:blob|commit) ([a-f0-9]{40})\t(.+)\z/s', $line, $entry) !== 1) {
                throw new LogicException('Unsupported preparation source tree.');
            }
            $path = $entry[3];
            if ((str_starts_with(basename($path), '.env') && basename($path) !== '.env.example')
                || str_ends_with($path, '/bootstrap/cache/config.php')) {
                throw new LogicException('Tracked dotenv overrides or cached configuration cannot be prepared.');
            }
            if (str_starts_with($path, 'bin/') || $path === '.agents/skills/planning-features/template.md'
                || in_array(basename($path), ['composer.json', 'composer.lock', '.env.example', '.gitignore', '.gitattributes', '.gitmodules'], true)
                || in_array($path, ['apps/gateway/tests/bootstrap.php', 'apps/gateway/tests/Support/TestDatabaseEnvironment.php'], true)
                || preg_match('~\A(?:apps/(?:cli|docs|gateway|e2e)|packages/php-sdk)/(?:phpunit[^/]*\.xml[^/]*|(?:config|bootstrap|scripts)/.*)\z~', $path) === 1) {
                if (! in_array($entry[1], ['100644', '100755'], true)) {
                    throw new LogicException('Preparation source inputs cannot be links or submodules.');
                }
                $inputs[$path] = $entry[1].' '.$entry[2];
            }
        }

        return $inputs;
    }

    /** @param list<string> $arguments */
    private function gitCommand(string $repository, array $arguments): string
    {
        return rtrim(Process::path($repository)->timeout(30)->env($this->gitEnvironment())
            ->run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', ...$arguments])->throw()->output(), "\n");
    }

    /** @return array<string, string|false> */
    private function gitEnvironment(): array
    {
        return array_merge(TaskProcessEnvironment::isolated(), ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_NO_LAZY_FETCH' => '1']);
    }

    private function file(string $path): string
    {
        if (realpath($path) !== $path || ! is_file($path)) {
            throw new LogicException('A preparation verification input is missing or redirected.');
        }

        return file_get_contents($path) ?: throw new LogicException('A preparation verification input is empty.');
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        $data = json_decode($this->file($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new LogicException('Invalid preparation JSON.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
