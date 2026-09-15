<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRecovery;
use App\Models\TaskRecoveryResumption;
use App\Models\TaskSessionReconnection;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('tasks:inspect {project} {task}')]
#[Description('Inspect a feature manifest and its task runner state without dispatching work')]
final class InspectTaskRuntimeCommand extends Command
{
    public function handle(TaskCatalog $tasks, TaskRuntimePlan $plans): int
    {
        $task = $tasks->find((string) $this->argument('project'), (string) $this->argument('task'));
        $workspace = TaskWorkspace::query()->where('root_task_id', $task->id)->first();
        $recovery = Schema::hasTable('task_recoveries')
            ? TaskRecovery::query()->where('root_task_id', $task->id)->first() : null;
        $resumption = $workspace !== null && Schema::hasTable('task_recovery_resumptions')
            ? TaskRecoveryResumption::query()->where('task_workspace_id', $workspace->id)->first() : null;
        $this->line(json_encode(['manifest_hash' => $plans->hash($task), 'manifest' => $plans->manifest($task),
            'effective_manifest_hash' => $workspace === null ? null : $plans->effectiveHash($workspace),
            'manifest_amendments' => $workspace === null || ! Schema::hasTable('task_manifest_amendments') ? []
                : TaskManifestAmendment::query()->where('task_workspace_id', $workspace->id)->orderBy('sequence')->get()->toArray(),
            'final_continuations' => $workspace === null || ! Schema::hasTable('task_final_continuations') ? []
                : TaskFinalContinuation::query()->where('task_workspace_id', $workspace->id)->orderBy('final_round')->get()->toArray(),
            'workspace' => $workspace?->toArray(), 'dispatches' => $workspace?->dispatches()->orderBy('id')->get()->toArray(),
            'session_reconnections' => $workspace === null || ! Schema::hasTable('task_session_reconnections') ? []
                : TaskSessionReconnection::query()->where('task_workspace_id', $workspace->id)->orderBy('id')->get()->toArray(),
            'reattempt_checkpoint' => $workspace === null || ! Schema::hasTable('task_reattempt_checkpoints') ? null
                : TaskReattemptCheckpoint::query()->where('task_workspace_id', $workspace->id)->first()?->toArray(),
            'reattempt' => $workspace === null || ! Schema::hasTable('task_reattempts') ? null
                : TaskReattempt::query()->where('task_workspace_id', $workspace->id)->first()?->toArray(),
            'recovery' => $recovery?->toArray(), 'recovery_resumption' => $resumption?->toArray()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
