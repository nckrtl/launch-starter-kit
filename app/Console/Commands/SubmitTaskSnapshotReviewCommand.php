<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\ReviewTaskSnapshotReacquisition;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:snapshot-review-submit {operation} {--file=}')]
#[Description('Record one exact independent postinstall snapshot verdict; no cleanup or issue completion')]
final class SubmitTaskSnapshotReviewCommand extends Command
{
    public function handle(ReviewTaskSnapshotReacquisition $review): int
    {
        try {
            $id = filter_var($this->argument('operation'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive review operation identifier.');
            }
            $operation = TaskCloseoutOperation::query()->findOrFail($id);
            $workspace = TaskLanding::query()->findOrFail($operation->task_landing_id)->workspace()->firstOrFail();
            if (realpath((string) getcwd()) !== $workspace->worktree) {
                throw new LogicException('Submit from the exact assigned feature worktree.');
            }
            $recorded = $review->submit($id, TaskLandingData::readRequest((string) $this->option('file'), $workspace->worktree));
            $this->info('Postinstall review recorded: '.TaskLandingData::text(TaskLandingData::object($recorded['review'] ?? null), 'verdict').'. Stop and await Commander.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Submission not confirmed. Retain the unchanged private handoff and report the safe error.');

            return self::FAILURE;
        }
    }
}
