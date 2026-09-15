<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AdvanceTaskRunner;
use App\Tasks\Runtime\StartTaskWorkspace;
use App\Tasks\TaskCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:start {project} {task} {--worktree=} {--manifest=} {--source=} {--exclusive : Confirm no other controller owns this source or worktree} {--orbit-flow= : Orbit discovery or proof flow; defaults to discovery} {--snapshot-replacement : Record snapshot replacement intent; requires Orbit proof flow}')]
#[Description('Explicitly adopt an approved feature into the opt-in sequential task runner')]
final class StartTaskRuntimeCommand extends Command
{
    public function handle(TaskCatalog $tasks, StartTaskWorkspace $start): int
    {
        try {
            $workspace = $start->handle($tasks->find((string) $this->argument('project'), (string) $this->argument('task')),
                (string) $this->option('worktree'), (string) $this->option('manifest'),
                (string) $this->option('source'), (bool) $this->option('exclusive'),
                $this->input->hasParameterOption('--orbit-flow') ? (string) $this->option('orbit-flow') : null,
                (bool) $this->option('snapshot-replacement'));
            AdvanceTaskRunner::dispatch($workspace->id);
            $this->info('Task workspace '.$workspace->id.' admitted; advancement queued.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
