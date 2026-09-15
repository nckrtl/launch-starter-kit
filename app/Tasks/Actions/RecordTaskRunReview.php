<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Models\TaskRunReview;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class RecordTaskRunReview
{
    public function __construct(private TaskMutation $mutation) {}

    public function handle(TaskRunReview $review, string $reviewerRef, TaskReviewVerdict $verdict, string $summary, string $evidenceRef): TaskRunReview
    {
        if (trim($reviewerRef) === '' || mb_strlen($reviewerRef) > 255 || trim($summary) === '' || trim($evidenceRef) === '') {
            throw new InvalidArgumentException('A review requires a reviewer reference, summary, and evidence reference.');
        }

        $run = $review->taskRun()->firstOrFail();
        $task = $run->task()->firstOrFail();

        return $this->mutation->handle($task->project_id, function () use ($task, $run, $review, $verdict, $reviewerRef, $summary, $evidenceRef): TaskRunReview {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $run = $task->runs()->lockForUpdate()->findOrFail($run->id);
            $review = $run->reviews()->lockForUpdate()->findOrFail($review->id);

            if ($run->reviewer_ref !== $reviewerRef) {
                throw new LogicException('Only the assigned reviewer can resolve this handoff.');
            }

            if ($review->verdict !== null) {
                if ($review->verdict !== $verdict || $review->summary !== $summary || $review->evidence_ref !== $evidenceRef) {
                    throw new LogicException('A review round cannot receive a conflicting verdict.');
                }

                return $review;
            }

            if ($run->status !== TaskRunStatus::Running || $run->active_task_id !== $task->id
                || $task->status !== TaskStatus::AwaitingReview || ($run->reviews()->orderByDesc('round')->first()->round ?? 0) !== $review->round) {
                throw new LogicException('Only the current review handoff can receive a verdict.');
            }

            $review->fill([
                'verdict' => $verdict,
                'summary' => $summary,
                'evidence_ref' => $evidenceRef,
                'reviewed_at' => now(),
            ]);
            $review->save();
            $task->status = $verdict === TaskReviewVerdict::Revise ? TaskStatus::ChangesRequested : TaskStatus::AwaitingCommit;
            $task->save();

            return $review;
        });
    }
}
