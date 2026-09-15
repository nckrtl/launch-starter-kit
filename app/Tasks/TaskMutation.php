<?php

declare(strict_types=1);

namespace App\Tasks;

use Closure;
use Illuminate\Support\Facades\DB;

final readonly class TaskMutation
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function handle(string $projectId, Closure $callback): mixed
    {
        return TaskSharedLock::make('tasks:project:'.hash('sha256', $projectId), 30)
            ->block(5, fn () => DB::transaction($callback, 3));
    }
}
