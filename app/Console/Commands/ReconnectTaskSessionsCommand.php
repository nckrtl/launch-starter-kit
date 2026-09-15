<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskWorkspace;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\ReconnectTaskSessions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:reconnect-sessions {workspace} {--file=} {--request=} {--exclusive} {--apply}')]
#[Description('Preview or record exact same-conversation terminal reconnection without starting or prompting agents')]
final class ReconnectTaskSessionsCommand extends Command
{
    public function handle(ReconnectTaskSessions $reconnect): int
    {
        try {
            $id = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive task workspace ID.');
            }
            $workspace = TaskWorkspace::query()->findOrFail($id);
            $request = TaskLandingData::readRequest((string) $this->option('file'), $workspace->worktree);
            $hash = $this->option('request');
            $this->line(TaskLandingData::json($reconnect->handle($id, $request, (bool) $this->option('exclusive'),
                is_string($hash) ? $hash : null, (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
