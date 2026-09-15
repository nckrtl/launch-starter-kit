<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

trait UsesTaskSharedLocks
{
    protected string $taskLockDirectory;

    protected string $taskLockStore;

    public function setUpUsesTaskSharedLocks(): void
    {
        $this->taskLockStore = 'task-lock-test-'.bin2hex(random_bytes(16));
        $this->taskLockDirectory = sys_get_temp_dir().'/'.$this->taskLockStore;
        if (! mkdir($this->taskLockDirectory, 0700)) {
            throw new RuntimeException('Cannot create private Tasks lock fixture.');
        }

        $this->beforeApplicationDestroyed(function (): void {
            Cache::forgetDriver($this->taskLockStore);
            (new Filesystem)->deleteDirectory($this->taskLockDirectory);
        });

        config(['cache.default' => $this->taskLockStore, 'cache.stores.'.$this->taskLockStore => [
            'driver' => 'file', 'path' => $this->taskLockDirectory.'/cache',
            'lock_path' => $this->taskLockDirectory.'/locks',
        ]]);
        Cache::forgetDriver($this->taskLockStore);
    }
}
