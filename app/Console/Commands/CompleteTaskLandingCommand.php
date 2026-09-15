<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Completion\CompleteTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:complete {landing} {--package=} {--exclusive} {--apply}')]
#[Description('Complete one exactly delivered Tasks issue in Linear without cleanup or acceptance rewrites')]
final class CompleteTaskLandingCommand extends Command
{
    public function handle(CompleteTaskLanding $completion): int
    {
        try {
            $this->line(TaskLandingData::json($completion->handle((int) $this->argument('landing'),
                (string) $this->option('package'), (bool) $this->option('exclusive'), (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
