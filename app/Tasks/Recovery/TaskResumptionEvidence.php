<?php

declare(strict_types=1);

namespace App\Tasks\Recovery;

use App\Models\Delivery;
use App\Models\Task;
use App\Models\TaskRecovery;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final readonly class TaskResumptionEvidence
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph, private TaskRecoveryGit $git) {}

    public function verify(TaskWorkspace $workspace, TaskRecovery $recovery, string $expectedHead): TaskRecoveryEvidence
    {
        $record = TaskRecoveryEvidence::object($recovery->toArray());
        $recoveryId = TaskRecoveryEvidence::string($record, 'id');
        $recordedAt = $recovery->getAttribute('created_at');
        if (! $recordedAt instanceof CarbonImmutable) {
            throw new LogicException('The recovery recording timestamp is missing.');
        }
        $evidence = TaskRecoveryEvidence::load(TaskRecoveryEvidence::string($record, 'evidence_path'), TaskRecoveryEvidence::string($record, 'backup_path'));
        foreach (['evidence_sha256' => $evidence->sha256, 'source_path' => $evidence->sourcePath,
            'source_sha256' => $evidence->sourceSha256, 'backup_sha256' => $evidence->backupSha256] as $key => $value) {
            if (($record[$key] ?? null) !== $value) {
                throw new LogicException('Recovery package, source, or backup identity changed.');
            }
        }
        if (! hash_equals($evidence->backupSha256, hash('sha256', TaskRecoveryEvidence::contents($evidence->backupPath)))) {
            throw new LogicException('The original backup digest changed.');
        }
        $provenance = TaskRecoveryEvidence::object($record['provenance'] ?? null);
        if (($record['root_task_id'] ?? null) !== $workspace->root_task_id
            || ($provenance['kind'] ?? null) !== 'accepted-child recovery, not original event replay'
            || ($provenance['source_snapshot'] ?? null) !== $evidence->sourceSnapshot
            || ($provenance['manifest'] ?? null) !== $evidence->manifest
            || ($provenance['source_workspace_id'] ?? null) !== ($evidence->workspace['id'] ?? null)
            || ($provenance['recovered_workspace_id'] ?? null) !== $workspace->id) {
            throw new LogicException('Persisted recovery provenance does not match its source and workspace.');
        }
        foreach (['root_task_id', 'project_id', 'source_key', 'repository', 'worktree', 'base_sha', 'manifest_hash', 'configuration'] as $key) {
            if ($workspace->getAttribute($key) !== ($evidence->workspace[$key] ?? null)) {
                throw new LogicException('The recovered workspace assignment or configuration changed: '.$key);
            }
        }
        $this->configuration($workspace);
        $root = $workspace->root()->firstOrFail();
        if ($root->status !== TaskStatus::Pending || $root->accepted_task_run_id !== null || $root->completed_at !== null || $root->runs()->exists()
            || $this->plans->manifest($root) !== $evidence->manifest || $this->plans->hash($root) !== $evidence->manifestHash) {
            throw new LogicException('Resume requires the unchanged pending root and admitted manifest.');
        }
        if ($workspace->attention !== self::hold(TaskRecoveryEvidence::string($record, 'id'))
            || $workspace->herdr_workspace !== null || $workspace->reviewer_session !== null
            || $workspace->final_check !== null || $workspace->final_result !== null || $workspace->dispatches()->exists()) {
            throw new LogicException('Resume requires the exact recovery hold, empty operational sessions and final results, and no dispatches.');
        }
        $connection = $workspace->getConnectionName();
        if (Delivery::on($connection)->active()->where(function (Builder $query) use ($workspace): void {
            $query->where('worktree_path', $workspace->worktree)->orWhere('external_issue_key', $workspace->source_key);
        })->exists()) {
            throw new LogicException('An active Delivery already owns the recovered source or worktree.');
        }
        $children = $this->graph->orderedChildren($root);
        $mappings = $provenance['children'] ?? null;
        if (! is_array($mappings) || ! array_is_list($mappings) || count($mappings) !== count($children)
            || array_column($evidence->children, 'task_id') !== array_map(fn (Task $task): int => $task->id, $children)
            || TaskRun::on($connection)->where('root_task_id', $root->id)->count() !== count($children)) {
            throw new LogicException('The recovered accepted-child mapping is incomplete or has extra attempts.');
        }
        foreach ($children as $index => $child) {
            $mapping = TaskRecoveryEvidence::object($mappings[$index]);
            $accepted = $evidence->children[$index];
            $run = $child->acceptedRun()->firstOrFail();
            $review = $run->reviews()->sole();
            $origin = ['kind' => 'recovered_acceptance', 'recovery_id' => $recoveryId,
                'source_run_id' => $accepted['run_id'], 'source_review_id' => $accepted['review_id'],
                'timestamps' => 'Current recovery recording time, not original execution time.'];
            $approval = TaskRecoveryEvidence::object($accepted['provenance']['approval']);
            $output = ['summary' => 'Prior accepted work recovered from verified Git, approval, and acknowledgment evidence. No implementation or review was rerun.',
                'evidence' => "RECOVERED ORIGINAL APPROVAL; these checks were not rerun during recovery.\n\n"
                    .TaskRecoveryEvidence::string($approval, 'summary')."\n\n".TaskRecoveryEvidence::string($approval, 'evidence_ref'),
                'recovery' => $origin];
            if ($mapping !== ['task_id' => $child->id, 'recovered_run_id' => $run->id, 'recovered_review_id' => $review->id, 'source' => $accepted['provenance']]
                || $child->status !== TaskStatus::Completed || $child->completed_at === null || $child->runs()->count() !== 1
                || $run->status !== TaskRunStatus::Completed || $run->task_id !== $child->id || $run->root_task_id !== $root->id
                || $run->attempt !== 1 || $run->idempotency_key !== 'recovery:'.$recoveryId.':'.$child->id
                || $run->active_task_id !== null || $run->active_root_task_id !== null || $run->failure_message !== null
                || $run->worker_ref !== $accepted['worker_ref'] || $run->reviewer_ref !== $accepted['reviewer_ref']
                || $run->base_sha !== $accepted['parent'] || $run->commit_sha !== $accepted['commit']
                || $run->input !== ['recovery' => $origin] || $run->output !== $output
                || $review->verdict !== TaskReviewVerdict::Pass || $review->round !== 1 || $review->tree_sha !== $accepted['tree']
                || $review->summary !== $output['summary'] || $review->evidence_ref !== 'task-recovery:'.$recoveryId || $review->output !== $output
                || $run->started_at->ne($recordedAt) || $run->finished_at?->ne($run->started_at) !== false
                || $child->completed_at->ne($run->started_at) || $review->requested_at->ne($run->started_at)
                || $review->reviewed_at?->ne($run->started_at) !== false) {
                throw new LogicException('Recovered child acceptance or review no longer matches immutable provenance.');
            }
        }
        if ($this->git->verifyResumable($evidence) !== $expectedHead) {
            throw new LogicException('The expected resume HEAD is not the final accepted child commit.');
        }

        return $evidence;
    }

    public static function hold(string $id): string
    {
        return 'Recovery '.$id.': accepted children recorded from retained evidence. Runtime remains quarantined; final checks and review are pending.';
    }

    private function configuration(TaskWorkspace $workspace): void
    {
        $configuration = $workspace->configuration;
        foreach (['repository', 'worktree_root', 'socket', 'agent_kind', 'instructions'] as $key) {
            TaskRecoveryEvidence::string($configuration, $key);
        }
        $allowedRoot = TaskRecoveryEvidence::string($configuration, 'worktree_root');
        if ($allowedRoot === '/' || realpath($allowedRoot) !== $allowedRoot || ! is_dir($allowedRoot)
            || ! str_starts_with($workspace->worktree, $allowedRoot.'/') || ! str_starts_with(TaskRecoveryEvidence::string($configuration, 'socket'), '/')
            || ($configuration['flow_version'] ?? null) !== 1 || ($configuration['repository'] ?? null) !== $workspace->repository
            || ! is_int($configuration['final_timeout'] ?? null) || $configuration['final_timeout'] < 1 || $configuration['final_timeout'] > 3600) {
            throw new LogicException('The recovered flow configuration or permitted worktree root is unsupported.');
        }
        foreach (['final_command', 'agent_arguments'] as $key) {
            $arguments = $configuration[$key] ?? null;
            if (! is_array($arguments) || ! array_is_list($arguments) || ($key === 'final_command' && ($arguments === [] || ($arguments[0] ?? '') === ''))
                || array_any($arguments, fn (mixed $argument): bool => ! is_string($argument) || str_contains($argument, "\0"))) {
                throw new LogicException('The recovered flow requires valid command argument lists.');
            }
        }
    }
}
