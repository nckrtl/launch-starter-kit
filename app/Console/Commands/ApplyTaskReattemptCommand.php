<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AdvanceTaskRunner;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\ApplyTaskReattempt;
use App\Tasks\Runtime\TaskReattemptInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:reattempt {checkpoint} {--state=} {--file=} {--exclusive} {--apply} {--advance}')]
#[Description('Preview or audit one new-base attempt after exact restoration of a preserved first blocked task')]
final class ApplyTaskReattemptCommand extends Command
{
    public function handle(ApplyTaskReattempt $reattempt, TaskReattemptInput $input): int
    {
        try {
            $id = filter_var($this->argument('checkpoint'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id) || ($this->option('advance') && ! $this->option('apply'))) {
                throw new LogicException('Use a positive checkpoint identifier; --advance requires --apply.');
            }
            $checkpoint = TaskReattemptCheckpoint::query()->findOrFail($id);
            $workspace = TaskWorkspace::query()->findOrFail($checkpoint->task_workspace_id);
            $request = $input->read((string) $this->option('file'), $workspace->worktree);
            $state = $this->option('state');
            $result = $reattempt->handle($id, $request, (bool) $this->option('exclusive'), is_string($state) ? $state : null, (bool) $this->option('apply'));
            if ($result['applied'] === true && $this->option('advance')) {
                try {
                    AdvanceTaskRunner::dispatch($workspace->id);
                    $result['advancement'] = 'queued';
                } catch (Throwable) {
                    $result['advancement'] = 'pending';
                    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $this->error('Reattempt recorded; enqueue failed. Inspect it, then use tasks:advance.');

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
