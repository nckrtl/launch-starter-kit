<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\TaskGraph;
use LogicException;

final readonly class AdvanceTaskWorkspace
{
    public function __construct(private TaskRuntimeLock $lock, private TaskRuntimePlan $plans, private TaskGraph $graph,
        private StartTaskRun $startRun, private TaskAgentPrompt $prompts, private GitTaskWorktree $git, private DispatchTaskAgent $dispatch,
        private TaskArtifactReviews $artifacts) {}

    public function handle(TaskWorkspace $workspace, ?string $executionKey = null): ?TaskAgentDispatch
    {
        if (config('task-runtime.enabled') !== true) {
            throw new LogicException('Task runtime is disabled.');
        }
        $intent = $this->lock->handle($workspace->id, function () use ($workspace): ?TaskAgentDispatch {
            $workspace = TaskWorkspace::query()->findOrFail($workspace->id);
            $root = $workspace->root()->firstOrFail();
            if ($workspace->attention !== null || $workspace->final_result !== null || $root->status === TaskStatus::Completed) {
                return null;
            }
            if (! hash_equals($this->plans->effectiveHash($workspace), $this->plans->hash($root))) {
                throw new LogicException('The feature content/order no longer matches the approved execution manifest.');
            }
            $pending = $workspace->dispatches()->whereNotIn('state', ['acknowledged', 'check_failed', 'integration_required'])->orderBy('id')->first();
            if ($pending !== null) {
                return $pending;
            }

            foreach ($this->graph->orderedChildren($root) as $task) {
                if ($task->status === TaskStatus::Completed) {
                    continue;
                }

                return $this->forTask($workspace, $task);
            }

            return $this->intent($workspace, null, 'final_review', $this->plans->finalRound($workspace));
        });

        if ($intent !== null) {
            $this->dispatch->handle($intent, $executionKey);
        }

        return $intent?->refresh();
    }

    private function forTask(TaskWorkspace $workspace, Task $task): TaskAgentDispatch
    {
        if ($task->status === TaskStatus::Pending) {
            $last = TaskRun::query()->where('root_task_id', $workspace->root_task_id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
            $base = $last->commit_sha ?? $workspace->base_sha;
            if ($this->git->validate($workspace->repository, $workspace->worktree) !== $base) {
                throw new LogicException('The worktree is not at the last accepted task commit.');
            }
            $run = $this->startRun->handle($task, 'workspace-'.$workspace->id, 'task-w'.$workspace->id.'-t'.$task->id,
                'task-w'.$workspace->id.'-reviewer', ['workspace_id' => $workspace->id], $base);

            return $this->intent($workspace, $run, 'implement', 0);
        }

        $run = $task->runs()->orderByDesc('attempt')->firstOrFail();
        $round = $run->reviews()->orderByDesc('round')->first()->round ?? 0;

        if ($this->artifacts->active($run)) {
            if ($task->status === TaskStatus::AwaitingCommit) {
                throw new LogicException('Artifact acceptance must finish atomically with its review handoff; inspect without dispatching a commit.');
            }
            if ($task->status === TaskStatus::AwaitingReview) {
                $this->artifacts->verifyLive($workspace, $run, $run->reviews()->where('round', $round)->firstOrFail());
            }
        }

        return match ($task->status) {
            TaskStatus::AwaitingReview => $this->intent($workspace, $run, 'review', $round),
            TaskStatus::ChangesRequested => $this->intent($workspace, $run, 'implement', $round),
            TaskStatus::AwaitingCommit => $this->intent($workspace, $run, 'commit', $round),
            default => throw new LogicException('The task has no supported next instruction.'),
        };
    }

    private function intent(TaskWorkspace $workspace, ?TaskRun $run, string $kind, int $round): TaskAgentDispatch
    {
        $key = ($run->id ?? 'root').':'.$kind.':'.$round;
        $existing = $workspace->dispatches()->where('step_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        $dispatch = $workspace->dispatches()->create(['task_run_id' => $run?->id, 'step_key' => $key,
            'kind' => $kind, 'round' => $round, 'final_check_version' => $kind === 'final_review' ? 1 : null,
            'final_preflight_version' => $kind === 'final_review' && (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) === 'proof' ? 1 : null,
            'token_hash' => hash('sha256', $token), 'handoff_token' => $token, 'prompt' => '']);
        $dispatch->update(['prompt' => $this->prompts->render($workspace, $dispatch, $token, $run)]);

        return $dispatch;
    }
}
