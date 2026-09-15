<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskWorkspace;
use App\Tasks\Landing\PrepareTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:landing-prepare {workspace} {--file=} {--proposal=} {--exclusive} {--apply}')]
#[Description('Preview or freeze and publish an exact completed Orbit Tasks package; never merge or clean up')]
final class PrepareTaskLandingCommand extends Command
{
    public function handle(PrepareTaskLanding $prepare): int
    {
        try {
            $id = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive workspace identifier.');
            }
            $workspace = TaskWorkspace::query()->findOrFail($id);
            $request = TaskLandingData::readRequest((string) $this->option('file'), $workspace->worktree);
            $hash = $this->option('proposal');
            $this->line(TaskLandingData::json($prepare->handle($id, $request, (bool) $this->option('exclusive'),
                is_string($hash) ? $hash : null, (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
