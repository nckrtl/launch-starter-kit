<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskLanding;
use App\Tasks\Landing\RecoverTaskLandingReview;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:landing-review-recover {landing} {--file=} {--request=} {--exclusive} {--apply}')]
#[Description('Preview or apply one pinned recovery for a known oversized supplemental review refusal')]
final class RecoverTaskLandingReviewCommand extends Command
{
    public function handle(RecoverTaskLandingReview $recover): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive landing identifier.');
            }
            $landing = TaskLanding::query()->findOrFail($id);
            $workspace = $landing->workspace()->firstOrFail();
            $request = TaskLandingData::readRequest((string) $this->option('file'), $workspace->worktree);
            $hash = $this->option('request');
            $this->line(TaskLandingData::json($recover->handle($id, $request, (bool) $this->option('exclusive'),
                is_string($hash) ? $hash : null, (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
