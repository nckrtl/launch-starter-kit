<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Tasks\Preparation\PrepareTaskWorktree;
use App\Tasks\TaskCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:prepare-worktree {project} {task} {--source=} {--manifest=} {--exclusive : Attest that no other controller owns this source or worktree}')]
#[Description('Prepare one fresh Orbit discovery worktree in the foreground without admitting a task')]
final class PrepareTaskWorktreeCommand extends Command
{
    public function handle(TaskCatalog $tasks, PrepareTaskWorktree $prepare): int
    {
        try {
            $root = $tasks->find((string) $this->argument('project'), (string) $this->argument('task'));
            $source = (string) $this->option('source');
            $manifest = (string) $this->option('manifest');
            $result = $prepare->handle($root, $source, $manifest, (bool) $this->option('exclusive'),
                function (string $type, string $buffer): void {
                    if ($type === 'info') {
                        $this->line($buffer);
                    } else {
                        $this->output->write($buffer);
                    }
                });
            $this->info('Worktree prepared; task admission is a separate operation. Landing readiness is not established.');
            $this->line('php artisan tasks:start '.escapeshellarg($root->project_id).' '.$root->id
                .' --worktree='.escapeshellarg($result['worktree']).' --source='.escapeshellarg($source)
                .' --manifest='.escapeshellarg($manifest).' --exclusive');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
