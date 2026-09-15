<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskWorkspace;
use App\Tasks\Runtime\PrepareTaskReattempt;
use App\Tasks\Runtime\TaskReattemptInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:prepare-reattempt {workspace} {--dispatch=} {--head=} {--manifest=} {--state=} {--file=} {--exclusive} {--apply}')]
#[Description('Preview or preserve the exact first blocked implementation before separate upstream integration')]
final class PrepareTaskReattemptCommand extends Command
{
    public function handle(PrepareTaskReattempt $prepare, TaskReattemptInput $input): int
    {
        try {
            $workspaceId = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $dispatch = filter_var($this->option('dispatch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($workspaceId) || ! is_int($dispatch)) {
                throw new LogicException('Use positive workspace and dispatch identifiers.');
            }
            $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
            $request = $input->read((string) $this->option('file'), $workspace->worktree);
            $state = $this->option('state');
            $result = $prepare->handle($workspaceId, $dispatch, (string) $this->option('head'), (string) $this->option('manifest'),
                $request, (bool) $this->option('exclusive'), is_string($state) ? $state : null, (bool) $this->option('apply'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
