<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskLanding;
use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:landing-submit {landing} {--file=}')]
#[Description('Record an exact secret-assignment supplemental package verdict without advancing tasks or merging')]
final class SubmitTaskLandingReviewCommand extends Command
{
    public function handle(ReviewTaskLanding $review): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive landing identifier.');
            }
            $workspace = TaskLanding::query()->findOrFail($id)->workspace()->firstOrFail();
            if (realpath((string) getcwd()) !== $workspace->worktree) {
                throw new LogicException('Submit from the exact assigned feature worktree.');
            }
            $receipt = TaskLandingData::readRequest((string) $this->option('file'), $workspace->worktree);
            $recorded = $review->submit($id, $receipt);
            $verdict = TaskLandingData::text($recorded->review_result ?? [], 'verdict');
            $this->info('Supplemental verdict recorded for landing '.$recorded->id.': '.$verdict.' ('.$recorded->state.'). Stop and await Commander. No merge or cleanup was authorized.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Submission not confirmed. Retain the unchanged private handoff and report this failure to Commander.');

            return self::FAILURE;
        }
    }
}
