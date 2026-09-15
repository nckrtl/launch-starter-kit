<?php

use App\Models\Task;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\Runtime\TaskRuntimeLock;
use App\Tasks\TaskMutation;
use App\Tasks\TaskSharedLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\UsesTaskSharedLocks;

uses(DatabaseMigrations::class, UsesTaskSharedLocks::class);

beforeEach(function () {
    $this->lockTask = Task::query()->create(['project_id' => 'lock-fixture', 'title' => 'Unchanged', 'kind' => TaskKind::Group]);
    $this->lockWorkspace = TaskWorkspace::query()->create([
        'root_task_id' => $this->lockTask->id, 'project_id' => 'lock-fixture', 'source_key' => 'LOCK-TEST',
        'repository' => $this->taskLockDirectory.'/repository', 'worktree' => $this->taskLockDirectory.'/worktree',
        'base_sha' => str_repeat('a', 40), 'manifest_hash' => str_repeat('b', 64), 'configuration' => [],
    ]);
});

function taskLockEntry(string $entry, Closure $callback): mixed
{
    return $entry === 'project'
        ? app(TaskMutation::class)->handle('lock-fixture', $callback)
        : app(TaskRuntimeLock::class)->handle(test()->lockWorkspace->id, $callback);
}

function taskLockKey(string $entry): string
{
    return $entry === 'project' ? 'tasks:project:'.hash('sha256', 'lock-fixture') : 'tasks:runtime:'.test()->lockWorkspace->id;
}

function assertTaskLockRejected(string $entry): void
{
    $called = false;
    $transactions = 0;
    $queries = [];
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(fn () => taskLockEntry($entry, function () use (&$called): void {
        $called = true;
        test()->lockTask->update(['title' => 'Must not change']);
    }))->toThrow(LogicException::class, 'Tasks mutations require the supported Laravel file cache lock store');
    expect($called)->toBeFalse()->and($transactions)->toBe(0)->and($queries)->toBe([])
        ->and(DB::transactionLevel())->toBe(0)->and(test()->lockTask->fresh()->title)->toBe('Unchanged');
}

it('rejects unsupported resolved stores before a callback transaction or query in every environment', function (string $entry, string $driver, string $environment) {
    $originalEnvironment = app()->environment();
    app()->instance('env', $environment);
    config(['cache.default' => 'task-rejected', 'cache.stores.task-rejected' => match ($driver) {
        'failover' => ['driver' => 'failover', 'stores' => [$this->taskLockStore]],
        'database' => ['driver' => 'database', 'table' => 'cache'],
        default => ['driver' => $driver],
    }]);

    try {
        assertTaskLockRejected($entry);
    } finally {
        app()->instance('env', $originalEnvironment);
    }
})->with(['project', 'workspace'])->with(['array', 'null', 'failover', 'database'])->with(['local', 'production', 'testing']);

it('rejects unknown lock providers and file-store subclasses without calling their lock method', function (string $entry, string $driver) {
    $store = $driver === 'custom'
        ? new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null): never
            {
                throw new RuntimeException('Unknown lock provider must not be called.');
            }
        }
    : new class(new Filesystem, $this->taskLockDirectory.'/cache') extends FileStore
    {
        public function lock($name, $seconds = 0, $owner = null): never
        {
            throw new RuntimeException('File-store subclass must not be called.');
        }
    };
    Cache::store()->setStore($store);

    assertTaskLockRejected($entry);
})->with(['project', 'workspace'])->with(['custom', 'file-subclass']);

it('does not trust a store named file when it resolves the array driver', function (string $entry) {
    config(['cache.default' => 'file', 'cache.stores.file' => ['driver' => 'array']]);
    Cache::forgetDriver('file');

    assertTaskLockRejected($entry);
})->with(['project', 'workspace']);

it('rejects an already resolved array store after configuration is corrected to file', function (string $entry) {
    $fileConfig = config('cache.stores.'.$this->taskLockStore);
    config(['cache.stores.'.$this->taskLockStore => ['driver' => 'array']]);
    expect(Cache::store()->getStore()::class)->toBe(ArrayStore::class);
    config(['cache.stores.'.$this->taskLockStore => $fileConfig]);

    assertTaskLockRejected($entry);
})->with(['project', 'workspace']);

it('uses the verified resolved file object even if the config label later changes', function (string $entry) {
    $store = Cache::store()->getStore();
    config(['cache.stores.'.$this->taskLockStore => ['driver' => 'array']]);
    expect($store::class)->toBe(FileStore::class)
        ->and(taskLockEntry($entry, fn (): string => 'protected result'))->toBe('protected result')
        ->and(Cache::store()->getStore())->toBe($store);
})->with(['project', 'workspace']);

it('preserves file-lock keys leases transactions return values and release', function (string $entry, int $lease) {
    $other = new FileStore(new Filesystem, $this->taskLockDirectory.'/locks');
    $key = taskLockKey($entry);
    $result = taskLockEntry($entry, function () use ($other, $key, $lease): object {
        expect(DB::transactionLevel())->toBe(1)->and($other->lock($key, 1)->get())->toBeFalse();
        $hash = sha1('file-store-lock:'.$key);
        $path = $this->taskLockDirectory.'/locks/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
        $expiry = (int) substr(file_get_contents($path), 0, 10);
        expect($expiry - time())->toBeGreaterThanOrEqual($lease - 1)->toBeLessThanOrEqual($lease);
        $this->lockTask->update(['title' => 'Committed']);

        return $this->lockTask;
    });
    expect($result)->toBe($this->lockTask)->and($this->lockTask->fresh()->title)->toBe('Committed')
        ->and(DB::transactionLevel())->toBe(0);
    $next = $other->lock($key, 1);
    expect($next->get())->toBeTrue();
    $next->release();
})->with([['project', 30], ['workspace', 60]]);

it('rolls back callback changes and releases the same lock after an exception', function (string $entry) {
    expect(fn () => taskLockEntry($entry, function (): never {
        $this->lockTask->update(['title' => 'Rolled back']);
        throw new RuntimeException('Synthetic callback failure.');
    }))->toThrow(RuntimeException::class, 'Synthetic callback failure.');
    expect($this->lockTask->fresh()->title)->toBe('Unchanged')->and(DB::transactionLevel())->toBe(0);
    $next = TaskSharedLock::make(taskLockKey($entry), 1);
    expect($next->get())->toBeTrue();
    $next->release();
})->with(['project', 'workspace']);

it('still requires the workspace row before entering its callback', function () {
    $called = false;
    expect(fn () => app(TaskRuntimeLock::class)->handle($this->lockWorkspace->id + 100, function () use (&$called): void {
        $called = true;
    }))->toThrow(ModelNotFoundException::class);
    expect($called)->toBeFalse()->and(DB::transactionLevel())->toBe(0);
});

it('times out before the transaction while a separate PHP process holds the same file lock', function (string $entry) {
    $key = taskLockKey($entry);
    $input = new InputStream;
    $process = new Process([PHP_BINARY, base_path('tests/Support/HoldTaskFileLock.php'), $this->taskLockDirectory, $key],
        base_path(), TaskProcessEnvironment::isolated(), $input, 15);
    $transactions = 0;
    $called = false;
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });
    try {
        $process->start();
        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, "held\n")))->toBeTrue();
        $start = microtime(true);
        expect(fn () => taskLockEntry($entry, function () use (&$called): void {
            $called = true;
            $this->lockTask->update(['title' => 'Must not change']);
        }))->toThrow(LockTimeoutException::class);
        expect(microtime(true) - $start)->toBeGreaterThanOrEqual(4.5)->toBeLessThan(7)
            ->and($transactions)->toBe(0)->and($called)->toBeFalse()
            ->and($this->lockTask->fresh()->title)->toBe('Unchanged');
    } finally {
        $input->write("release\n");
        $input->close();
        $process->wait();
    }
    expect($process->getExitCode())->toBe(0)->and($process->getErrorOutput())->toBe('')
        ->and(taskLockEntry($entry, fn (): string => 'after release'))->toBe('after release');
})->with(['project', 'workspace']);
