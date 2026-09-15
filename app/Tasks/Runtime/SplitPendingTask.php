<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskManifestAmendment;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

final readonly class SplitPendingTask
{
    public function __construct(private TaskRuntimeLock $lock, private TaskMutation $mutation,
        private TaskRuntimePlan $plans, private TaskGraph $graph, private TaskManifestAmendmentHistory $history,
        private GitTaskWorktree $git) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $workspaceId, int $targetId, int $runId, int $dispatchId, string $manifestHash,
        string $targetVersion, string $amendmentKey, array $request, bool $exclusive,
        ?string $proposalHash = null, bool $apply = false): array
    {
        if (min($workspaceId, $targetId, $runId, $dispatchId) < 1 || ! $exclusive
            || preg_match('/\A[a-f0-9]{64}\z/', $manifestHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $targetVersion) !== 1
            || trim($amendmentKey) === '' || $amendmentKey !== trim($amendmentKey) || mb_strlen($amendmentKey) > 100) {
            throw new InvalidArgumentException('Pin the workspace, target, active run, dispatch, manifest, target version, amendment key, and exclusive ownership.');
        }
        $normalized = $this->request($request);
        $request = ['reason' => $normalized['reason'], 'evidence' => $normalized['evidence'],
            'replacements' => $normalized['replacements'], 'amendment_key' => $amendmentKey,
            'expected_target_version' => $targetVersion];
        $requestHash = TaskManifestAmendmentHistory::requestHash($workspaceId, $targetId, $runId, $dispatchId,
            $manifestHash, $targetVersion, $request);
        if ($apply && (! is_string($proposalHash) || ! hash_equals($requestHash, $proposalHash))) {
            throw new InvalidArgumentException('Apply requires the exact proposal_hash returned by preview.');
        }
        if ($apply && config('task-runtime.enabled') !== true) {
            throw new LogicException('Enable the task runtime before applying a manifest amendment.');
        }
        $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
        if (($existing = $this->recorded($workspaceId, $amendmentKey, $requestHash)) !== null) {
            return $existing;
        }
        $preview = $this->split($workspace, $targetId, $runId, $dispatchId, $manifestHash, $targetVersion,
            $request, $requestHash, false);
        if (! $apply) {
            return $preview;
        }

        return $this->lock->handle($workspaceId, fn (): array => $this->mutation->handle($workspace->project_id,
            function () use ($workspaceId, $targetId, $runId, $dispatchId, $manifestHash, $targetVersion,
                $amendmentKey, $request, $requestHash): array {
                if (($existing = $this->recorded($workspaceId, $amendmentKey, $requestHash)) !== null) {
                    return $existing;
                }

                return $this->split(TaskWorkspace::query()->findOrFail($workspaceId), $targetId, $runId, $dispatchId,
                    $manifestHash, $targetVersion, $request, $requestHash, true);
            }));
    }

    /** @param array{reason:string,evidence:string,replacements:list<array{title:string,description:string,acceptance_criteria:string}>,amendment_key:string,expected_target_version:string} $request
     * @return array<string, mixed>
     */
    private function split(TaskWorkspace $workspace, int $targetId, int $runId, int $dispatchId,
        string $manifestHash, string $targetVersion, array $request, string $requestHash, bool $apply): array
    {
        $root = $workspace->root()->firstOrFail();
        $target = Task::query()->forProject($workspace->project_id)->findOrFail($targetId);
        $run = TaskRun::query()->findOrFail($runId);
        $dispatch = TaskAgentDispatch::query()->findOrFail($dispatchId);
        $currentDispatch = $workspace->dispatches()->orderByDesc('id')->first();
        $children = $this->graph->orderedChildren($root);
        $tail = $children === [] ? null : $children[array_key_last($children)];
        $previous = count($children) > 1 ? $children[count($children) - 2] : null;
        $before = $this->plans->manifest($root);
        $expectedStatus = match ($dispatch->kind) {
            'implement' => $dispatch->round === 0 ? TaskStatus::Running : TaskStatus::ChangesRequested,
            'review' => TaskStatus::AwaitingReview,
            'commit' => TaskStatus::AwaitingCommit,
            default => null,
        };
        if ($workspace->project_id !== $root->project_id || $root->status !== TaskStatus::Pending
            || $workspace->attention !== null || $workspace->final_result !== null
            || $target->parent_id !== $root->id || $target->kind !== TaskKind::Executable
            || $tail?->id !== $target->id || $target->status !== TaskStatus::Pending || $target->runs()->exists()
            || $target->children()->exists() || $target->dependents()->exists() || $previous === null
            || $target->dependencies()->count() !== 1 || $target->dependencies()->first()?->id !== $previous->id
            || $run->id !== $runId || $run->task_id !== $previous->id || $run->root_task_id !== $root->id
            || $run->status !== TaskRunStatus::Running || $run->active_task_id !== $previous->id
            || $run->active_root_task_id !== $root->id || $previous->status !== $expectedStatus
            || $currentDispatch?->id !== $dispatch->id || $dispatch->task_workspace_id !== $workspace->id
            || $dispatch->task_run_id !== $run->id || $dispatch->state !== 'sent' || $dispatch->session === null
            || $dispatch->result !== null || $dispatch->error !== null) {
            throw new LogicException('Only the untouched tail immediately after the current unambiguous active task may be split.');
        }
        if ($target->contentVersion() !== $targetVersion || ! hash_equals($this->plans->effectiveHash($workspace), $manifestHash)
            || ! hash_equals(TaskManifestAmendmentHistory::hash($before), $manifestHash)) {
            throw new LogicException('The pinned task brief or effective manifest changed.');
        }
        if ($workspace->dispatches()->where('kind', 'final_review')->exists()
            || (Schema::hasTable('task_final_continuations') && DB::table('task_final_continuations')->where('task_workspace_id', $workspace->id)->exists())
            || (Schema::hasTable('task_reattempts') && DB::table('task_reattempts')->where('task_workspace_id', $workspace->id)->exists())
            || (Schema::hasTable('task_reattempt_checkpoints') && DB::table('task_reattempt_checkpoints')->where('task_workspace_id', $workspace->id)->exists())
            || (Schema::hasTable('task_recoveries') && DB::table('task_recoveries')->where('root_task_id', $root->id)->exists())) {
            throw new LogicException('Recovered, reattempted, or final-review workspaces cannot amend their implementation manifest.');
        }
        $this->history->ledger($workspace, $before);
        $head = $this->git->inspectAssignment($workspace->repository, $workspace->worktree);
        if (! $apply) {
            return ['applied' => false, 'recorded' => false, 'proposal_hash' => $requestHash,
                'previous_manifest_hash' => $manifestHash, 'target_task_id' => $target->id,
                'replacement_count' => count($request['replacements']), 'observed_head' => $head];
        }
        $briefs = $request['replacements'];
        $first = $briefs[0] ?? throw new LogicException('The validated replacement list is empty.');
        $target->update($first);
        $prior = $target;
        foreach (array_slice($briefs, 1) as $brief) {
            $next = Task::query()->create(['project_id' => $root->project_id, 'parent_id' => $root->id,
                'kind' => TaskKind::Executable, ...$brief]);
            $next->dependencies()->attach($prior->id);
            $prior = $next;
        }
        $root->refresh();
        $after = $this->plans->manifest($root);
        $sequence = TaskManifestAmendment::query()->where('task_workspace_id', $workspace->id)->count() + 1;
        $values = ['task_workspace_id' => $workspace->id,
            'active_task_run_id' => $run->id, 'task_agent_dispatch_id' => $dispatch->id, 'target_task_id' => $target->id,
            'sequence' => $sequence, 'amendment_key' => $request['amendment_key'], 'proposal_hash' => $requestHash,
            'request_hash' => $requestHash, 'head' => $head, 'previous_manifest_hash' => $manifestHash,
            'manifest_hash' => TaskManifestAmendmentHistory::hash($after), 'previous_manifest' => $before,
            'manifest' => $after, 'request' => $request];
        $audit = TaskManifestAmendment::query()->create([...$values,
            'audit_hash' => TaskManifestAmendmentHistory::auditHash($values), 'created_at' => now()]);
        $this->history->ledger($workspace, $after);

        return ['applied' => true, 'recorded' => true, 'proposal_hash' => $requestHash,
            'audit' => $audit->toArray(), 'ordered_task_ids' => array_column($after['children'], 'id')];
    }

    /** @return array<string, mixed>|null */
    private function recorded(int $workspaceId, string $key, string $requestHash): ?array
    {
        if (! Schema::hasTable('task_manifest_amendments')) {
            return null;
        }
        $audit = TaskManifestAmendment::query()->where('task_workspace_id', $workspaceId)
            ->where('amendment_key', $key)->first();
        if ($audit === null) {
            return null;
        }
        if (! hash_equals($audit->request_hash, $requestHash)) {
            throw new LogicException('This amendment key already records a conflicting request.');
        }

        return ['applied' => false, 'recorded' => true, 'proposal_hash' => $requestHash, 'audit' => $audit->toArray()];
    }

    /** @param array<string, mixed> $request
     * @return array{reason:string,evidence:string,replacements:list<array{title:string,description:string,acceptance_criteria:string}>}
     */
    private function request(array $request): array
    {
        if (array_diff(array_keys($request), ['reason', 'evidence', 'replacements']) !== []) {
            throw new InvalidArgumentException('Use only reason, evidence, and replacement briefs.');
        }
        $replacements = $request['replacements'] ?? null;
        if (! is_array($replacements) || ! array_is_list($replacements) || count($replacements) < 2 || count($replacements) > 8) {
            throw new InvalidArgumentException('Split the task into two to eight complete replacement briefs.');
        }
        $briefs = [];
        foreach ($replacements as $brief) {
            if (! is_array($brief) || array_is_list($brief)
                || array_diff(array_keys($brief), ['title', 'description', 'acceptance_criteria']) !== []) {
                throw new InvalidArgumentException('Each replacement needs only title, description, and acceptance_criteria.');
            }
            $briefs[] = ['title' => $this->text($brief, 'title', 255),
                'description' => $this->text($brief, 'description', 50_000),
                'acceptance_criteria' => $this->text($brief, 'acceptance_criteria', 50_000)];
        }

        return ['reason' => $this->text($request, 'reason', 10_000),
            'evidence' => $this->text($request, 'evidence', 50_000), 'replacements' => $briefs];
    }

    /** @param array<array-key, mixed> $values */
    private function text(array $values, string $key, int $limit): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException('A nonempty bounded '.$key.' is required.');
        }

        return trim($value);
    }
}
