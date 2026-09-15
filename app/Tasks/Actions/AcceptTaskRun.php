<?php

declare(strict_types=1);

namespace App\Tasks\Actions;

use App\Models\Task;
use App\Models\TaskRunReview;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\TaskArtifactReviews;
use App\Tasks\Runtime\TaskMainIntegration;
use App\Tasks\TaskCommit;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use LogicException;

final readonly class AcceptTaskRun
{
    public function __construct(private TaskMutation $mutation, private TaskGraph $graph, private TaskMainIntegration $integrations, private TaskArtifactReviews $artifacts) {}

    public function handle(TaskRunReview $review, ?TaskCommit $commit = null): Task
    {
        $run = $review->taskRun()->firstOrFail();
        $task = $run->task()->firstOrFail();

        return $this->mutation->handle($task->project_id, function () use ($task, $run, $review, $commit): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $run = $task->runs()->lockForUpdate()->findOrFail($run->id);
            $review = $run->reviews()->lockForUpdate()->findOrFail($review->id);

            if ($task->kind !== TaskKind::Executable || $review->verdict !== TaskReviewVerdict::Pass
                || ($run->reviews()->orderByDesc('round')->first()->round ?? 0) !== $review->round || $task->runs()->where('attempt', '>', $run->attempt)->exists()) {
                throw new LogicException('Acceptance requires a passing verdict for the current review handoff.');
            }

            $integration = $this->integrations->binding($run);
            $parents = $integration['parents'] ?? [$run->base_sha];
            $artifact = $this->artifacts->active($run);
            if (($artifact && $commit !== null) || (! $artifact && ($run->base_sha === null) !== ($commit === null))
                || ($commit !== null && ($commit->treeSha !== $review->tree_sha || $commit->parentShas !== $parents
                    || $commit->integrationMainSha !== ($integration['main_sha'] ?? null)))) {
                throw new LogicException('The accepted commit must contain the reviewed tree and descend directly from the run base.');
            }

            if ($task->status === TaskStatus::Completed && $task->accepted_task_run_id === $run->id
                && $run->status === TaskRunStatus::Completed && $run->commit_sha === $commit?->sha) {
                return $task;
            }

            if ($task->status !== TaskStatus::AwaitingCommit || $run->status !== TaskRunStatus::Running || $run->active_task_id !== $task->id) {
                throw new LogicException('Only the current passed handoff can complete; acceptance cannot be rewritten.');
            }

            if ($artifact) {
                $this->artifacts->assertAcceptance($run, $review);
            }

            $this->graph->assertReady($task);
            $completedAt = now();
            $run->fill(['status' => TaskRunStatus::Completed, 'output' => $review->output, 'commit_sha' => $commit?->sha, 'finished_at' => $completedAt]);
            $run->save();
            $task->fill(['status' => TaskStatus::Completed, 'accepted_task_run_id' => $run->id, 'completed_at' => $completedAt]);
            $task->save();

            return $task;
        });
    }
}
