<?php

declare(strict_types=1);

namespace App\Tasks;

use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use LogicException;

final readonly class TaskSharedLock
{
    public static function make(string $name, int $seconds): Lock
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof FileStore || $store::class !== FileStore::class) {
            throw new LogicException('Tasks mutations require the supported Laravel file cache lock store; resolved '.$store::class.'.');
        }

        return $store->lock($name, $seconds);
    }
}
