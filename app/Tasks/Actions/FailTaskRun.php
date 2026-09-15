<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Models\TaskRun;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskMutation;
use App\Tasks\TaskPayload;
use InvalidArgumentException;
use LogicException;

final readonly class FailTaskRun
{
    public function __construct(private TaskMutation $mutation, private TaskPayload $payloads) {}

    /** @param array<string, mixed> $output */
    public function handle(TaskRun $run, int $expectedRound, TaskStatus $expectedStatus, string $failureMessage, array $output = []): TaskRun
    {
        if ($expectedRound < 0 || trim($failureMessage) === ''
            || ! in_array($expectedStatus, [TaskStatus::Running, TaskStatus::ChangesRequested, TaskStatus::AwaitingReview], true)) {
            throw new InvalidArgumentException('Failure requires an implementation or review handoff and a nonblank reason.');
        }

        $task = $run->task()->firstOrFail();

        return $this->mutation->handle($task->project_id, function () use ($task, $run, $expectedRound, $expectedStatus, $failureMessage, $output): TaskRun {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $run = $task->runs()->lockForUpdate()->findOrFail($run->id);
            $result = ['handoff' => ['round' => $expectedRound, 'status' => $expectedStatus->value], 'result' => $output];

            if ($run->status === TaskRunStatus::Failed && $run->output !== null
                && $run->failure_message === $failureMessage && $this->payloads->matches($run->output, $result)) {
                return $run;
            }

            if ($run->status !== TaskRunStatus::Running || $run->active_task_id !== $task->id
                || $task->status !== $expectedStatus || ($run->reviews()->orderByDesc('round')->first()->round ?? 0) !== $expectedRound) {
                throw new LogicException('Only the current unfinished handoff can fail; accepted or committing work cannot be aborted.');
            }

            $run->fill(['status' => TaskRunStatus::Failed, 'output' => $result, 'failure_message' => $failureMessage, 'finished_at' => now()]);
            $run->save();
            $task->status = TaskStatus::Failed;
            $task->save();

            return $run;
        });
    }
}
