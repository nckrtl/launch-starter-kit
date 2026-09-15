<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AdvanceTaskRunner;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\ContinueTaskFinal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:continue-final {workspace} {--dispatch=} {--head=} {--manifest=} {--file=} {--exclusive} {--apply} {--advance}')]
#[Description('Preview or record one scoped correction or environment retry for the exact held final attempt')]
final class ContinueTaskFinalCommand extends Command
{
    public function handle(ContinueTaskFinal $continue): int
    {
        try {
            $id = filter_var($this->argument('workspace'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $dispatch = filter_var($this->option('dispatch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id) || ! is_int($dispatch) || ($this->option('advance') && ! $this->option('apply'))) {
                throw new LogicException('Use positive workspace and dispatch identifiers; --advance requires --apply.');
            }
            $workspace = TaskWorkspace::query()->findOrFail($id);
            $file = $this->option('file');
            if (! is_string($file) || ! str_starts_with($file, '/') || ! is_file($file) || ! is_readable($file)
                || realpath($file) === false || str_starts_with((string) realpath($file), $workspace->worktree.'/') || filesize($file) > 262_144) {
                throw new LogicException('Use a bounded readable JSON file outside the assigned worktree.');
            }
            $contents = file_get_contents($file);
            $request = $contents === false ? null : json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($request) || array_is_list($request)) {
                throw new LogicException('The final continuation request must be a JSON object.');
            }
            foreach (array_keys($request) as $key) {
                if (! is_string($key)) {
                    throw new LogicException('Use named final continuation fields.');
                }
            }
            /** @var array<string, mixed> $request */
            $result = $continue->handle($id, $dispatch, (string) $this->option('head'), (string) $this->option('manifest'),
                $request, (bool) $this->option('exclusive'), (bool) $this->option('apply'));
            if ($result['applied'] === true && $this->option('advance')) {
                try {
                    AdvanceTaskRunner::dispatch($id);
                    $result['advancement'] = 'queued';
                } catch (Throwable) {
                    $result['advancement'] = 'pending';
                    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $this->error('Continuation recorded; enqueue failed. Inspect it, then use tasks:advance.');

                    return self::FAILURE;
                }
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
