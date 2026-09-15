<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskGraph;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** @phpstan-type ReattemptBinding array{workspace:array<string,mixed>,dispatch:array<string,mixed>,run:array<string,mixed>,run_id:int,manifest:string,task_id:int,worker_session:array<string,mixed>} */
final readonly class TaskReattemptGuard
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph, private TaskAgents $agents) {}

    /** @return ReattemptBinding */
    public function origin(TaskWorkspace $workspace, int $dispatchId, string $head, string $manifest): array
    {
        $root = $workspace->root()->firstOrFail();
        if (Schema::hasTable('task_manifest_amendments')
            && $workspace->getConnection()->table('task_manifest_amendments')->where('task_workspace_id', $workspace->id)->exists()) {
            throw new LogicException('Manifest-amended workspaces cannot enter the first-task reattempt flow.');
        }
        $dispatch = $workspace->dispatches()->orderByDesc('id')->first();
        $run = $dispatch?->run()->first();
        $task = $run?->task()->first();
        $children = $this->graph->orderedChildren($root);
        if ($root->status !== TaskStatus::Pending || $workspace->final_check !== null || $workspace->final_result !== null
            || $workspace->dispatches()->count() !== 1 || $dispatch?->id !== $dispatchId
            || $dispatch->kind !== 'implement' || $dispatch->round !== 0 || $dispatch->state !== 'acknowledged'
            || ($dispatch->result['verdict'] ?? null) !== 'blocked' || $dispatch->session === null
            || $workspace->attention === null || $workspace->attention !== ($dispatch->result['summary'] ?? null)
            || $run === null || $task === null || $run->attempt !== 1 || $run->status !== TaskRunStatus::Running
            || $task->status !== TaskStatus::Running || $run->active_task_id !== $task->id || $run->active_root_task_id !== $root->id
            || ($children[0]->id ?? null) !== $task->id || $run->base_sha !== $head || $workspace->base_sha !== $head
            || $run->reviews()->exists() || $task->runs()->orderByDesc('attempt')->first()?->id !== $run->id
            || TaskRun::query()->where('root_task_id', $root->id)->count() !== 1
            || $root->children()->whereNotNull('accepted_task_run_id')->exists()) {
            throw new LogicException('Only the exact first blocked implementation, without reviews or accepted children, can reattempt.');
        }
        $this->graph->assertReady($task);
        $this->manifest($workspace, $manifest);

        return ['workspace' => $workspace->toArray(), 'dispatch' => $dispatch->toArray(), 'run' => $run->toArray(),
            'run_id' => $run->id, 'manifest' => $manifest, 'task_id' => $task->id, 'worker_session' => $dispatch->session];
    }

    public function manifest(TaskWorkspace $workspace, string $manifest): void
    {
        if (! hash_equals($this->plans->effectiveHash($workspace), $manifest)
            || ! hash_equals($this->plans->hash($workspace->root()->firstOrFail()), $manifest)) {
            throw new LogicException('The approved task manifest changed.');
        }
    }

    /** @param ReattemptBinding $binding */
    public function sessions(TaskWorkspace $workspace, array $binding): void
    {
        $this->agents->assertSession($workspace, $binding['worker_session']);
        if ($workspace->reviewer_session !== null) {
            $this->agents->assertSession($workspace, $workspace->reviewer_session);
        }
    }
}
