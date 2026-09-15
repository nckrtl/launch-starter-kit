<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskLanding;
use App\Tasks\Landing\AmendTaskLanding;
use App\Tasks\Landing\TaskLandingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('tasks:landing-amend {landing} {--file=} {--proposal=} {--exclusive} {--apply}')]
#[Description('Preview or record one audited PR-body correction; never publish, prompt, run checks or merge')]
final class AmendTaskLandingCommand extends Command
{
    public function handle(AmendTaskLanding $amend): int
    {
        try {
            $id = filter_var($this->argument('landing'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! is_int($id)) {
                throw new LogicException('Use a positive predecessor landing identifier.');
            }
            $landing = TaskLanding::query()->findOrFail($id);
            $request = TaskLandingData::readRequest((string) $this->option('file'), $landing->workspace()->firstOrFail()->worktree);
            $hash = $this->option('proposal');
            $this->line(TaskLandingData::json($amend->handle($id, $request, (bool) $this->option('exclusive'),
                is_string($hash) ? $hash : null, (bool) $this->option('apply'))));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
