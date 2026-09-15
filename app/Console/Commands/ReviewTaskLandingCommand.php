<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Landing\ReviewTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:landing-review {landing} {--package=} {--exclusive} {--apply}')]
#[Description('Preview or request one retained-reviewer supplemental approval of a frozen Orbit package')]
final class ReviewTaskLandingCommand extends Command
{
    public function handle(ReviewTaskLanding $review): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive landing identifier.');
            }
            $this->line(TaskLandingData::json($review->dispatch($id, (string) $this->option('package'),
                (bool) $this->option('exclusive'), (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
