<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Orbit\OrbitTaskProfile;
use LogicException;

final readonly class TaskProofPreflight
{
    public function __construct(private GitTaskWorktree $git, private TaskRuntimePlan $plans, private TaskRuntimeLock $lock) {}

    /** @param array<string, mixed>|null $check */
    public function holdIfNeeded(TaskWorkspace $workspace, TaskAgentDispatch $dispatch, ?array $check = null): bool
    {
        if ($dispatch->final_preflight_version !== 1) {
            return false;
        }
        $workspace->refresh();
        $dispatch->refresh();
        $observed = $this->state($workspace, $dispatch);
        $main = $this->git->currentMain($workspace->repository, $workspace->worktree);
        $last = TaskRun::query()->where('root_task_id', $workspace->root_task_id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
        if ($last?->commit_sha !== $main['head']) {
            throw new LogicException('Preflight requires the clean last accepted task commit.');
        }

        return $this->lock->handle($workspace->id, function () use ($workspace, $dispatch, $observed, $main, $check): bool {
            $workspace->refresh();
            $dispatch->refresh();
            if ($this->state($workspace, $dispatch) !== $observed) {
                throw new LogicException('The final boundary changed during current-main observation.');
            }
            if ($main['main_is_ancestor']) {
                return false;
            }
            $message = 'Final dispatch '.$dispatch->id.' requires an audited current-main integration before proof.';
            $dispatch->update(['state' => 'integration_required', 'error' => $message,
                'final_preflight' => [...$main, 'manifest_hash' => $this->plans->effectiveHash($workspace)],
                ...($check === null ? [] : ['final_check' => $check])]);
            $workspace->update(['attention' => $message, ...($check === null ? [] : ['final_check' => $check])]);

            return true;
        }, transactionAttempts: 3);
    }

    /** @return array<string, mixed> */
    private function state(TaskWorkspace $workspace, TaskAgentDispatch $dispatch): array
    {
        $root = $workspace->root()->firstOrFail();
        $manifest = $this->plans->manifest($root);
        if ($workspace->project_id !== 'orbit' || (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || $root->status !== TaskStatus::Pending || $workspace->attention !== null || $workspace->final_result !== null
            || $dispatch->kind !== 'final_review' || $dispatch->state !== 'sending' || $dispatch->session !== null
            || $dispatch->final_preflight !== null || $dispatch->final_check !== null
            || $workspace->dispatches()->orderByDesc('id')->first()?->id !== $dispatch->id
            || $dispatch->round !== $this->plans->finalRound($workspace)
            || $workspace->dispatches()->whereKeyNot($dispatch->id)->whereNotIn('state', ['acknowledged', 'check_failed', 'integration_required'])->exists()
            || $root->children()->where('status', '!=', TaskStatus::Completed)->exists()
            || TaskRun::query()->where('active_root_task_id', $root->id)->exists()
            || $this->plans->effectiveHash($workspace) !== TaskManifestAmendmentHistory::hash($manifest)) {
            throw new LogicException('Current-main preflight requires an unprompted exact clean final boundary.');
        }

        return ['workspace' => $workspace->toArray(), 'dispatch' => $dispatch->toArray(), 'manifest' => $manifest,
            'runs' => TaskRun::query()->where('root_task_id', $root->id)->orderBy('id')->get()->toArray()];
    }
}
