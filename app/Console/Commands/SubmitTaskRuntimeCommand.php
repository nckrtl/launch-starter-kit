<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AdvanceTaskRunner;
use App\Models\TaskAgentDispatch;
use App\Tasks\Runtime\SubmitTaskDispatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:submit {dispatch} {--file= : Absolute JSON handoff path outside the feature checkout}')]
#[Description('Record a dispatch-scoped task handoff; task updates, not Herdr idle, drive advancement')]
final class SubmitTaskRuntimeCommand extends Command
{
    public function handle(SubmitTaskDispatch $submit): int
    {
        try {
            $dispatch = TaskAgentDispatch::query()->findOrFail((int) $this->argument('dispatch'));
            $workspace = $dispatch->workspace()->firstOrFail();
            if (realpath((string) getcwd()) !== $workspace->worktree) {
                throw new LogicException('Submit from the exact assigned feature worktree.');
            }
            $file = $this->option('file');
            if (! is_string($file) || ! str_starts_with($file, '/') || ! is_file($file) || ! is_readable($file)
                || realpath($file) === false || str_starts_with((string) realpath($file), $workspace->worktree.'/') || filesize($file) > 262_144) {
                throw new LogicException('Use a readable, bounded JSON file outside the worktree.');
            }
            $contents = file_get_contents($file);
            $receipt = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($receipt) || array_is_list($receipt)) {
                throw new LogicException('The handoff must be a JSON object.');
            }
            foreach (array_keys($receipt) as $key) {
                if (! is_string($key)) {
                    throw new LogicException('Handoff field names must be strings.');
                }
            }
            /** @var array<string, mixed> $receipt Validated object with string field names. */
            $submit->handle($dispatch, $receipt);
            AdvanceTaskRunner::dispatch($workspace->id);
            $this->info('Handoff recorded. Stop work and wait for the next Commander instruction.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
