<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TaskAgentDispatch;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use App\Tasks\Runtime\TaskRuntimeLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class AdvanceTaskRunner implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public string $executionKey;

    public function __construct(public int $workspaceId)
    {
        $this->executionKey = (string) Str::uuid();
        $this->onConnection('task-runtime')->onQueue('tasks');
    }

    public function handle(AdvanceTaskWorkspace $advance): void
    {
        $advance->handle(TaskWorkspace::query()->findOrFail($this->workspaceId), $this->executionKey);
    }

    public function failed(?Throwable $exception): void
    {
        (new TaskRuntimeLock)->handle($this->workspaceId, function (): void {
            $dispatch = TaskAgentDispatch::query()->where('task_workspace_id', $this->workspaceId)
                ->where('execution_key', $this->executionKey)->whereIn('state', ['sending', 'prompting'])->first();
            if ($dispatch === null) {
                return;
            }
            $workspace = $dispatch->workspace()->firstOrFail();
            if ($workspace->dispatches()->orderByDesc('id')->first()?->id !== $dispatch->id) {
                return;
            }
            $message = 'Dispatch '.$dispatch->id.' stopped before its outcome was recorded; inspect it before proceeding.';
            $dispatch->update(['state' => 'ambiguous', 'error' => $message]);
            $workspace->update(['attention' => $message]);
        });
        if ($exception !== null) {
            report($exception);
        }
    }
}
