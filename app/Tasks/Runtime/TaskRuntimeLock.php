<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskWorkspace;
use App\Tasks\TaskSharedLock;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class TaskRuntimeLock
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  positive-int  $transactionAttempts
     * @return T
     */
    public function handle(int $workspaceId, Closure $callback, int $transactionAttempts = 1): mixed
    {
        return TaskSharedLock::make('tasks:runtime:'.$workspaceId, 60)->block(5, fn () => DB::transaction(function () use ($workspaceId, $callback): mixed {
            TaskWorkspace::query()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();

            return $callback();
        }, $transactionAttempts));
    }
}
