<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskWorkspace;
use App\Tasks\Runtime\SplitPendingTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:split-pending {workspace} {target} {--run=} {--dispatch=} {--manifest=} {--target-version=} {--key=} {--file=} {--proposal=} {--exclusive} {--apply}')]
#[Description('Preview or apply an audited split of the untouched pending implementation tail')]
final class SplitPendingTaskCommand extends Command
{
    public function handle(SplitPendingTask $split): int
    {
        try {
            $workspaceId = $this->positive($this->argument('workspace'));
            $targetId = $this->positive($this->argument('target'));
            $runId = $this->positive($this->option('run'));
            $dispatchId = $this->positive($this->option('dispatch'));
            $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
            $file = $this->option('file');
            if (! is_string($file) || ! str_starts_with($file, '/') || ! is_file($file) || ! is_readable($file)
                || realpath($file) === false || str_starts_with((string) realpath($file), $workspace->worktree.'/')
                || filesize($file) > 262_144) {
                throw new LogicException('Use a bounded readable JSON file outside the assigned worktree.');
            }
            $contents = file_get_contents($file);
            $request = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($request) || array_is_list($request)) {
                throw new LogicException('The split request must be a JSON object.');
            }
            /** @var array<string, mixed> $request */
            $result = $split->handle($workspaceId, $targetId, $runId, $dispatchId,
                (string) $this->option('manifest'), (string) $this->option('target-version'), (string) $this->option('key'),
                $request, (bool) $this->option('exclusive'),
                is_string($this->option('proposal')) ? $this->option('proposal') : null, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function positive(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($id)) {
            throw new LogicException('Workspace, target, run, and dispatch identifiers must be positive integers.');
        }

        return $id;
    }
}
