<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Closeout\TaskMainHoldService;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:main-hold {action} {--file=} {--exclusive} {--apply}')]
#[Description('Import a main incident, authorize repair, retain proof, clear it, or withdraw an obsolete requirement')]
final class ManageTaskMainHoldCommand extends Command
{
    public function handle(TaskMainHoldService $holds): int
    {
        try {
            $configuration = TaskLandingData::object(config('task-runtime.projects.orbit'));
            $request = TaskLandingData::readRequest((string) $this->option('file'), TaskLandingData::text($configuration, 'worktree_root'));
            $this->line(TaskLandingData::json($holds->handle((string) $this->argument('action'), $request,
                (bool) $this->option('exclusive'), (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
