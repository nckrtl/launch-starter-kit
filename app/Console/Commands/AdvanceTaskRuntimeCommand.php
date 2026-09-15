<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskWorkspace;
use App\Tasks\Runtime\AdvanceTaskWorkspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:advance {workspace}')]
#[Description('Reconcile one task workspace without replaying an attempted dispatch')]
final class AdvanceTaskRuntimeCommand extends Command
{
    public function handle(AdvanceTaskWorkspace $advance): int
    {
        try {
            $intent = $advance->handle(TaskWorkspace::query()->findOrFail((int) $this->argument('workspace')));
            $this->line($intent === null ? 'No next instruction.' : 'Dispatch '.$intent->id.': '.$intent->state);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
