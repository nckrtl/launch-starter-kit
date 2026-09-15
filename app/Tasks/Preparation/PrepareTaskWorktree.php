<?php

declare(strict_types=1);

namespace App\Tasks\Preparation;

use App\Models\Delivery;
use App\Models\Task;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use Throwable;

final readonly class PrepareTaskWorktree
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph,
        private TaskPreparationGuard $guard, private TaskWorktreePreparation $native) {}

    /** @param Closure(string, string):void $output
     * @return array{worktree: string, evidence: string, receipt: array<string, mixed>}
     */
    public function handle(Task $root, string $source, string $manifest, bool $exclusive, Closure $output): array
    {
        if (config('task-runtime.enabled') !== true || ! $exclusive || $root->project_id !== 'orbit') {
            throw new LogicException('Enable the Orbit task runtime and attest exclusive source/worktree ownership.');
        }
        $configuration = config('task-runtime.projects.'.$root->project_id);
        foreach (['repository', 'worktree_root', 'socket'] as $key) {
            if (! is_array($configuration) || ! is_string($configuration[$key] ?? null) || $configuration[$key] === '') {
                throw new LogicException('Missing preparation configuration: '.$key);
            }
        }
        $repository = $configuration['repository'];
        $worktreeRoot = $configuration['worktree_root'];
        $socket = $configuration['socket'];
        $timeout = config('task-runtime.preparation.timeout');
        if (! is_int($timeout) || $timeout < 1 || $timeout > 7200) {
            throw new LogicException('Preparation timeout must be between 1 and 7200 seconds.');
        }
        $common = $this->guard->common($repository);
        $directory = $this->guard->directory($common, $source);
        $issueLock = $this->guard->issue($common, $source, true);
        try {
            $checkoutLock = $this->guard->checkout($common);
            try {
                if (file_exists($directory) || is_link($directory)) {
                    throw new LogicException('Preparation already exists. No retry, replay, or reconciliation is supported in v1.');
                }
                $worktree = $worktreeRoot.'/'.strtolower($source);
                $this->assertPins($root, $source, $manifest, $worktree, $socket);
                $before = $this->native->inspect($repository, $worktreeRoot, $source);
                $environment = $this->environment();
                $this->guard->privateDirectory($directory);
                $pins = $this->guard->pins($root, $repository, $common, $worktree, $source, $manifest);
                $intent = ['schema' => 1, 'pins' => $pins, 'worktree' => $worktree, 'before' => $before,
                    'created_at' => gmdate('c'), 'exclusive_attestation' => true,
                    'command' => [$repository.'/bin/worktree-create', $source, '--flow=discovery'],
                    'timeout_seconds' => $timeout, 'temporary' => $environment['TMPDIR'],
                    'orbit_home' => $environment['ORBIT_HOME'], 'composer_home' => $environment['COMPOSER_HOME'],
                    'logging' => 'Captured stdout/stderr; interrupted native child logs may be incomplete.',
                    'lifetime' => 'Foreground only. A lost parent or timeout may leave children running; no v1 retry.'];
                $this->guard->write($directory.'/intent.json', $intent);
                $output('info', 'Preparation intent retained at '.$directory);
                try {
                    $verified = $this->native->run($before, $directory, $environment, $timeout, $output);
                    $this->assertPins($root, $source, $manifest, $worktree, $socket);
                    $receipt = ['schema' => 1, 'pins' => $pins, 'intent_sha256' => hash_file('sha256', $directory.'/intent.json'),
                        'exit_code' => 0, 'verified' => true, 'observed' => $verified, 'completed_at' => gmdate('c'),
                        'landing_ready' => false, 'scaffold' => 'Native plan, flow, and proof material preserved.'];
                    $this->guard->write($directory.'/success.json', $receipt);

                    return ['worktree' => $worktree, 'evidence' => $directory, 'receipt' => $receipt];
                } catch (Throwable $exception) {
                    throw new LogicException('Preparation remains unresolved at '.$directory.'. Captured output is retained; interrupted child logs may be incomplete. '.$exception->getMessage(), previous: $exception);
                }
            } finally {
                $checkoutLock->release();
            }
        } finally {
            $issueLock->release();
        }
    }

    private function assertPins(Task $root, string $source, string $manifest, string $worktree, string $socket): void
    {
        $root = Task::query()->findOrFail($root->id);
        if (! hash_equals($this->plans->hash($root), $manifest)) {
            throw new LogicException('The approved task manifest changed.');
        }
        $this->graph->assertUnattempted($root);
        if (TaskWorkspace::query()->where('root_task_id', $root->id)->orWhere('source_key', $source)->orWhere('worktree', $worktree)->exists()
            || Delivery::query()->active()->where(function (Builder $query) use ($source, $worktree): void {
                $query->where('external_issue_key', $source)->orWhere('worktree_path', $worktree);
            })->exists()) {
            throw new LogicException('A Tasks workspace or active Delivery already owns this root, source, or path.');
        }
        $this->native->assertUnowned($socket, $worktree);
    }

    /** @return array<string, string|false> */
    private function environment(): array
    {
        $root = config('task-runtime.preparation.temporary_root');
        if (! is_string($root) || $root === '/' || realpath($root) !== $root || ! is_dir($root)) {
            throw new LogicException('Preparation needs a canonical external temporary root.');
        }
        for ($parent = $root; $parent !== '/'; $parent = dirname($parent)) {
            if (file_exists($parent.'/.git') || is_link($parent.'/.git')) {
                throw new LogicException('Preparation temporary directories must be outside every Git worktree.');
            }
        }
        $private = $root.'/commander-preparation-'.bin2hex(random_bytes(16));
        $this->guard->privateDirectory($private);
        foreach (['tmp', 'orbit', 'composer'] as $child) {
            $this->guard->privateDirectory($private.'/'.$child);
        }

        return array_merge(TaskProcessEnvironment::isolated(), ['TMPDIR' => $private.'/tmp', 'TMP' => $private.'/tmp',
            'TEMP' => $private.'/tmp', 'ORBIT_HOME' => $private.'/orbit', 'COMPOSER_HOME' => $private.'/composer',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync']);
    }
}
