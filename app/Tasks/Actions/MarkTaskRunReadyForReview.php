<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\GitObjectId;
use App\Tasks\TaskMutation;
use App\Tasks\TaskPayload;
use InvalidArgumentException;
use LogicException;

final readonly class MarkTaskRunReadyForReview
{
    public function __construct(private TaskMutation $mutation, private TaskPayload $payloads) {}

    /** @param array<string, mixed> $output */
    public function handle(TaskRun $run, string $workerRef, int $round, array $output = [], ?string $treeSha = null): TaskRunReview
    {
        if ($round < 1) {
            throw new InvalidArgumentException('A review round must be positive.');
        }

        if ($treeSha !== null) {
            GitObjectId::validate($treeSha);
        }

        $task = $run->task()->firstOrFail();

        return $this->mutation->handle($task->project_id, function () use ($task, $run, $workerRef, $round, $output, $treeSha): TaskRunReview {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $run = $task->runs()->lockForUpdate()->findOrFail($run->id);

            if ($run->worker_ref !== $workerRef) {
                throw new LogicException('Only the assigned worker can hand off this run.');
            }

            if (($run->base_sha === null) !== ($treeSha === null)
                || ($treeSha !== null && mb_strlen($treeSha) !== mb_strlen((string) $run->base_sha))) {
                throw new InvalidArgumentException('A coding handoff requires a tree snapshot in the base commit object format.');
            }

            $existing = $run->reviews()->where('round', $round)->first();

            if ($existing !== null) {
                if ($existing->tree_sha !== $treeSha || ! $this->payloads->matches($existing->output, $output)) {
                    throw new LogicException('A review handoff cannot be reused with different content.');
                }

                return $existing;
            }

            $latestRound = ($run->reviews()->orderByDesc('round')->first()->round ?? 0);

            if ($run->status !== TaskRunStatus::Running || $run->active_task_id !== $task->id
                || ! in_array($task->status, [TaskStatus::Running, TaskStatus::ChangesRequested], true)
                || $round !== $latestRound + 1) {
                throw new LogicException('Only the current implementation turn can submit the next review round.');
            }

            $review = $run->reviews()->create(['round' => $round, 'tree_sha' => $treeSha, 'output' => $output, 'requested_at' => now()]);
            $task->status = TaskStatus::AwaitingReview;
            $task->save();

            return $review;
        });
    }
}
