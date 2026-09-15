<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Models\TaskRun;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\GitObjectId;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use App\Tasks\TaskPayload;
use InvalidArgumentException;
use LogicException;

final readonly class StartTaskRun
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph, private TaskPayload $payloads) {}

    /** @param array<string, mixed> $input */
    public function handle(Task $task, string $idempotencyKey, string $workerRef, string $reviewerRef, array $input = [], ?string $baseSha = null): TaskRun
    {
        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100) {
            throw new InvalidArgumentException('An idempotency key of 1 to 100 characters is required.');
        }

        if (trim($workerRef) === '' || trim($reviewerRef) === '' || $workerRef !== trim($workerRef) || $reviewerRef !== trim($reviewerRef)
            || mb_strlen($workerRef) > 255 || mb_strlen($reviewerRef) > 255 || $workerRef === $reviewerRef) {
            throw new InvalidArgumentException('A task run requires distinct, nonblank worker and reviewer references.');
        }

        if ($baseSha !== null) {
            GitObjectId::validate($baseSha);
        }

        return $this->mutation->handle($task->project_id, function () use ($task, $idempotencyKey, $input, $workerRef, $reviewerRef, $baseSha): TaskRun {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $existing = $task->runs()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if (! $this->payloads->matches($existing->input, $input) || $existing->worker_ref !== $workerRef
                    || $existing->reviewer_ref !== $reviewerRef || $existing->base_sha !== $baseSha) {
                    throw new LogicException('An idempotency key cannot be reused with different inputs.');
                }

                return $existing;
            }

            if ($task->kind !== TaskKind::Executable || ! in_array($task->status, [TaskStatus::Pending, TaskStatus::Failed], true)) {
                throw new LogicException('Only pending or failed executable tasks can start an attempt.');
            }

            $this->graph->assertReady($task);
            $root = $this->graph->root($task);
            $featureRuns = TaskRun::query()->where('root_task_id', $root->id);

            if ((clone $featureRuns)->whereNotNull('active_root_task_id')->exists()) {
                throw new LogicException('Only one task run can own a feature at a time.');
            }

            if ((clone $featureRuns)->where('reviewer_ref', '!=', $reviewerRef)->exists()
                || (clone $featureRuns)->where('task_id', '!=', $task->id)->where('worker_ref', $workerRef)->exists()) {
                throw new LogicException('A feature keeps one reviewer and uses a fresh worker for each task.');
            }

            $lastCommit = (clone $featureRuns)->whereNotNull('commit_sha')->orderByDesc('id')->first();

            if ($baseSha !== null && $lastCommit !== null && $baseSha !== $lastCommit->commit_sha) {
                throw new LogicException('A coding task must start from the last accepted feature commit.');
            }

            $previous = $task->runs()->orderByDesc('attempt')->first();

            $run = $task->runs()->create([
                'attempt' => ($previous->attempt ?? 0) + 1,
                'idempotency_key' => $idempotencyKey,
                'root_task_id' => $root->id,
                'worker_ref' => $workerRef,
                'reviewer_ref' => $reviewerRef,
                'base_sha' => $baseSha,
                'status' => TaskRunStatus::Running,
                'input' => $input,
                'started_at' => now(),
            ]);

            $task->status = TaskStatus::Running;
            $task->save();

            return $run;
        });
    }
}
