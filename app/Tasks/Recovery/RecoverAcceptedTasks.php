<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Models\Task;
use App\Models\TaskRecovery;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class RecoverAcceptedTasks
{
    public function __construct(private TaskRecoveryDatabase $databases, private TaskRecoveryGit $git,
        private TaskRuntimePlan $plans, private TaskGraph $graph) {}

    /** @return array<string, mixed> */
    public function handle(string $project, int $taskId, string $database, string $backup, string $evidencePath,
        bool $exclusive, bool $apply = false): array
    {
        if (config('task-runtime.enabled') !== false || ! $exclusive) {
            throw new LogicException('Disable the task runtime and explicitly confirm exclusive quarantine ownership.');
        }
        $this->databases->assertTarget($database, $backup);
        $evidence = TaskRecoveryEvidence::load($evidencePath, $backup);
        $name = 'task-recovery-'.Str::uuid();
        $this->databases->assertOfflineFile($database);
        $connection = DB::connectUsing($name, [
            'driver' => 'sqlite',
            'database' => $apply ? $database : 'file:'.str_replace('%2F', '/', rawurlencode($database)).'?mode=ro',
            'foreign_key_constraints' => true, 'busy_timeout' => 5000,
            'transaction_mode' => $apply ? 'IMMEDIATE' : 'DEFERRED',
        ]);

        try {
            $effective = $connection->scalar("SELECT file FROM pragma_database_list WHERE name = 'main'");
            if ($effective !== $database) {
                throw new LogicException('The recovery connection does not point to the explicit quarantine database.');
            }

            return $connection->transaction(function () use ($connection, $name, $evidence, $database, $project, $taskId, $apply): array {
                $migrated = $this->databases->assertBackupCopy($database, $evidence);
                $root = Task::on($name)->where('project_id', $project)->findOrFail($taskId);
                $this->graph->assertUnattempted($root);
                $manifest = $this->plans->manifest($root);
                if ($manifest !== $evidence->manifest || ! hash_equals($this->plans->hash($root), $evidence->manifestHash)
                    || ($evidence->workspace['project_id'] ?? null) !== $project
                    || ($evidence->workspace['root_task_id'] ?? null) !== $taskId
                    || ($evidence->workspace['manifest_hash'] ?? null) !== $evidence->manifestHash) {
                    throw new LogicException('The backup task content, order, or workspace differs from the admitted manifest.');
                }
                $children = $this->graph->orderedChildren($root);
                if (array_column($evidence->children, 'task_id') !== array_map(fn (Task $task): int => $task->id, $children)
                    || count(array_unique(array_column($evidence->children, 'worker_ref'))) !== count($children)
                    || count(array_unique(array_column($evidence->children, 'reviewer_ref'))) !== 1
                    || count(array_unique(array_column($evidence->children, 'run_id'))) !== count($children)
                    || count(array_unique(array_column($evidence->children, 'review_id'))) !== count($children)) {
                    throw new LogicException('Recovery requires every child in order with distinct workers and one retained reviewer.');
                }
                $configuration = TaskRecoveryEvidence::object($evidence->workspace['configuration'] ?? null);
                if (($configuration['flow_version'] ?? null) !== 1
                    || ($configuration['repository'] ?? null) !== ($evidence->workspace['repository'] ?? null)) {
                    throw new LogicException('Recovery requires the recorded supported project flow configuration.');
                }
                $head = $this->git->verify($evidence);
                $report = ['mode' => $apply ? 'apply' : 'dry-run', 'database' => $database,
                    'project' => $project, 'root_task_id' => $taskId, 'manifest_hash' => $evidence->manifestHash,
                    'source_sha256' => $evidence->sourceSha256, 'backup_sha256' => $evidence->backupSha256,
                    'accepted_children' => array_map(fn (array $child): array => [
                        'task_id' => $child['task_id'], 'source_run_id' => $child['run_id'], 'source_review_id' => $child['review_id'],
                        'parent' => $child['parent'], 'commit' => $child['commit'], 'tree' => $child['tree'],
                    ], $evidence->children),
                    'head' => $head, 'migration_required' => ! $migrated, 'root_completed' => false,
                    'final_check' => null, 'final_result' => null, 'dispatches_created' => 0];
                if (! $apply) {
                    return $report;
                }
                if (! $migrated) {
                    throw new LogicException('Apply the task recovery migration to the quarantine database before recovery.');
                }

                $recoveryId = (string) Str::uuid();
                $recordedAt = now();
                $mapping = [];
                foreach ($children as $index => $task) {
                    $this->graph->assertReady($task);
                    $accepted = $evidence->children[$index];
                    $origin = ['kind' => 'recovered_acceptance', 'recovery_id' => $recoveryId,
                        'source_run_id' => $accepted['run_id'], 'source_review_id' => $accepted['review_id'],
                        'timestamps' => 'Current recovery recording time, not original execution time.'];
                    $originalApproval = TaskRecoveryEvidence::object($accepted['provenance']['approval']);
                    $output = ['summary' => 'Prior accepted work recovered from verified Git, approval, and acknowledgment evidence. No implementation or review was rerun.',
                        'evidence' => "RECOVERED ORIGINAL APPROVAL; these checks were not rerun during recovery.\n\n"
                            .TaskRecoveryEvidence::string($originalApproval, 'summary')."\n\n"
                            .TaskRecoveryEvidence::string($originalApproval, 'evidence_ref'),
                        'recovery' => $origin];
                    $run = TaskRun::on($name)->create(['task_id' => $task->id, 'root_task_id' => $root->id,
                        'attempt' => 1, 'idempotency_key' => 'recovery:'.$recoveryId.':'.$task->id,
                        'worker_ref' => $accepted['worker_ref'], 'reviewer_ref' => $accepted['reviewer_ref'],
                        'base_sha' => $accepted['parent'], 'commit_sha' => $accepted['commit'],
                        'status' => TaskRunStatus::Completed, 'input' => ['recovery' => $origin], 'output' => $output,
                        'started_at' => $recordedAt, 'finished_at' => $recordedAt]);
                    $review = TaskRunReview::on($name)->create(['task_run_id' => $run->id, 'round' => 1,
                        'tree_sha' => $accepted['tree'], 'output' => $output, 'requested_at' => $recordedAt,
                        'verdict' => TaskReviewVerdict::Pass, 'summary' => $output['summary'],
                        'evidence_ref' => 'task-recovery:'.$recoveryId, 'reviewed_at' => $recordedAt]);
                    $task->fill(['status' => TaskStatus::Completed, 'accepted_task_run_id' => $run->id, 'completed_at' => $recordedAt])->save();
                    $mapping[] = ['task_id' => $task->id, 'recovered_run_id' => $run->id, 'recovered_review_id' => $review->id,
                        'source' => $accepted['provenance']];
                }
                $workspace = TaskWorkspace::on($name)->create(['root_task_id' => $root->id, 'project_id' => $project,
                    'source_key' => TaskRecoveryEvidence::string($evidence->workspace, 'source_key'),
                    'repository' => TaskRecoveryEvidence::string($evidence->workspace, 'repository'),
                    'worktree' => TaskRecoveryEvidence::string($evidence->workspace, 'worktree'),
                    'base_sha' => TaskRecoveryEvidence::string($evidence->workspace, 'base_sha'),
                    'manifest_hash' => $evidence->manifestHash, 'configuration' => $configuration,
                    'attention' => 'Recovery '.$recoveryId.': accepted children recorded from retained evidence. Runtime remains quarantined; final checks and review are pending.']);
                TaskRecovery::on($name)->create(['id' => $recoveryId, 'root_task_id' => $root->id,
                    'evidence_path' => $evidence->path, 'evidence_sha256' => $evidence->sha256,
                    'source_path' => $evidence->sourcePath, 'source_sha256' => $evidence->sourceSha256,
                    'backup_path' => $evidence->backupPath, 'backup_sha256' => $evidence->backupSha256,
                    'created_at' => $recordedAt, 'provenance' => [
                        'kind' => 'accepted-child recovery, not original event replay',
                        'source_snapshot' => $evidence->sourceSnapshot,
                        'source_snapshot_policy' => 'Original source retained with credential and prompt fields redacted. Historical observations and gaps are evidence, not restored operational state.',
                        'manifest' => $evidence->manifest,
                        'source_workspace_id' => $evidence->workspace['id'] ?? null,
                        'recovered_workspace_id' => $workspace->id,
                        'operational_fields' => 'IDs, attempt and round numbers, idempotency keys, and timestamps belong to this recovery recording. Original observations exist only in source provenance.',
                        'children' => $mapping,
                    ]]);
                if ($connection->select('PRAGMA foreign_key_check') !== []
                    || $connection->scalar('PRAGMA integrity_check') !== 'ok') {
                    throw new LogicException('Recovered records failed SQLite integrity checks.');
                }
                if (! hash_equals($evidence->sha256, (string) hash_file('sha256', $evidence->path))
                    || ! hash_equals($evidence->sourceSha256, (string) hash_file('sha256', $evidence->sourcePath))
                    || ! hash_equals($evidence->backupSha256, (string) hash_file('sha256', $evidence->backupPath))
                    || $this->git->verify($evidence) !== $head) {
                    throw new LogicException('Recovery evidence or Git state changed during recording.');
                }

                return [...$report, 'recovery_id' => $recoveryId, 'workspace_id' => $workspace->id,
                    'attention' => $workspace->attention];
            }, 1);
        } finally {
            DB::purge($name);
        }
    }
}
