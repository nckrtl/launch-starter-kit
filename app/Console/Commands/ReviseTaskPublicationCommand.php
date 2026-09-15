<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskLanding;
use App\Tasks\Closeout\ReviseTaskPublication;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:revise-publication {landing} {--package=} {--file=} {--exclusive} {--apply}')]
#[Description('Preview or explicitly revise one pinned existing Orbit PR to an independently approved Tasks package')]
final class ReviseTaskPublicationCommand extends Command
{
    public function handle(ReviseTaskPublication $revise): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive landing identifier.');
            }
            $landing = TaskLanding::query()->findOrFail($id);
            $request = TaskLandingData::readRequest((string) $this->option('file'), $landing->workspace()->firstOrFail()->worktree);
            $this->line(TaskLandingData::json($revise->handle($id, (string) $this->option('package'), $request,
                (bool) $this->option('exclusive'), (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
