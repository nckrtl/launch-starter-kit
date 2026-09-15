<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use LogicException;

final readonly class ReconcileTaskReattempt
{
    public function __construct(private TaskReattemptGuard $guard, private TaskReattemptGit $git, private TaskReattemptInput $input) {}

    /** @return array<string, mixed>|null */
    public function beforeSend(TaskWorkspace $workspace, TaskAgentDispatch $dispatch): ?array
    {
        $audit = $this->audit($dispatch);
        if ($audit === null) {
            return null;
        }
        $checkpoint = TaskReattemptCheckpoint::query()->findOrFail($audit->task_reattempt_checkpoint_id);
        $this->assertCurrent($workspace, $dispatch);
        $request = $this->input->checkpoint($checkpoint->request, $workspace->worktree);
        $this->input->restoration($audit->request, $workspace->worktree);
        if (($request['integration'] ?? null) === 'preserve_history') {
            $prerequisite = $request['prerequisite'];
            $this->git->prerequisite($workspace->worktree, $checkpoint->observation['head'], $prerequisite['candidate'], $prerequisite['merge'], $prerequisite['main'],
                preserveHistory: true);
        }
        $this->guard->sessions($workspace, $checkpoint->binding);
        if ($this->git->observe($workspace->repository, $workspace->worktree) !== $audit->observation) {
            throw new LogicException('Reattempt worktree changed after cutover; no worker will be prompted.');
        }

        return $checkpoint->binding['worker_session'];
    }

    public function assertCurrent(TaskWorkspace $workspace, TaskAgentDispatch $dispatch): void
    {
        $audit = $this->audit($dispatch);
        if ($audit === null) {
            return;
        }
        $checkpoint = TaskReattemptCheckpoint::query()->findOrFail($audit->task_reattempt_checkpoint_id);
        $binding = $checkpoint->binding;
        $expected = $binding['workspace'];
        $actual = $workspace->toArray();
        foreach (['attention', 'updated_at'] as $key) {
            unset($expected[$key], $actual[$key]);
        }
        $run = $dispatch->run()->firstOrFail();
        $task = $run->task()->firstOrFail();
        $old = TaskRun::query()->findOrFail($binding['run_id']);
        $origin = TaskAgentDispatch::query()->findOrFail($checkpoint->task_agent_dispatch_id);
        if ($actual !== $expected || $workspace->attention !== null || $workspace->root()->firstOrFail()->status !== TaskStatus::Pending
            || $workspace->dispatches()->orderByDesc('id')->first()?->id !== $dispatch->id
            || $origin->toArray() !== $binding['dispatch'] || $dispatch->kind !== 'implement' || $dispatch->round !== 0
            || $dispatch->session !== $binding['worker_session'] || $dispatch->task_run_id !== $audit->task_run_id
            || $run->base_sha !== $audit->observation['head'] || $run->status !== TaskRunStatus::Running || $run->attempt !== 2
            || $run->worker_ref !== $old->worker_ref || $run->reviewer_ref !== $old->reviewer_ref
            || $run->input !== ['workspace_id' => $workspace->id, 'reattempt_checkpoint_id' => $checkpoint->id]
            || $task->id !== $binding['task_id'] || $task->status !== TaskStatus::Running || $task->accepted_task_run_id !== null
            || $run->active_task_id !== $task->id || $run->active_root_task_id !== $workspace->root_task_id
            || $task->runs()->orderByDesc('attempt')->first()?->id !== $run->id || $run->reviews()->exists()
            || $old->status !== TaskRunStatus::Failed || $old->base_sha !== $checkpoint->observation['head']
            || ($old->output['result'] ?? null) !== ['checkpoint_id' => $checkpoint->id, 'blocked_dispatch_id' => $checkpoint->task_agent_dispatch_id]) {
            throw new LogicException('Reattempt assignment or retained worker ownership changed; no replacement is allowed.');
        }
        $this->guard->manifest($workspace, $binding['manifest']);
        $this->git->assertRetained($workspace->worktree, $checkpoint->request_hash, $checkpoint->observation);
        $request = $this->input->checkpoint($checkpoint->request, $workspace->worktree);
        $prerequisite = $request['prerequisite'];
        $preserveHistory = ($request['integration'] ?? null) === 'preserve_history';
        $this->git->prerequisite($workspace->worktree, $checkpoint->observation['head'], $prerequisite['candidate'], $prerequisite['merge'], $prerequisite['main'],
            remote: false, preserveHistory: $preserveHistory);
        $base = $prerequisite['main'];
        if ($preserveHistory) {
            $this->git->integration($workspace->worktree, $checkpoint->observation['head'], $prerequisite['main'], $audit->observation['head']);
            $base = $audit->observation['head'];
        }
        $expected = $this->git->restored($workspace->worktree, $checkpoint->observation, $base);
        if ($audit->observation['head'] !== $base || $audit->observation['tree'] !== $expected['tree'] || $audit->observation['index_tree'] !== $expected['index_tree']) {
            throw new LogicException('The reattempt no longer matches its restored checkpoint.');
        }
        $restoration = $this->input->restoration($audit->request, $workspace->worktree);
        if ($request !== $checkpoint->request || $restoration !== $audit->request
            || $checkpoint->request_hash !== $this->input->hash([$workspace->id, $origin->id, $workspace->base_sha,
                $binding['manifest'], $request, $this->input->hash($checkpoint->observation)])
            || $audit->request_hash !== $this->input->hash([$checkpoint->id, $restoration, $this->input->hash($audit->observation)])) {
            throw new LogicException('Reattempt checkpoint or cutover request identity changed; no worker will be prompted.');
        }
    }

    private function audit(TaskAgentDispatch $dispatch): ?TaskReattempt
    {
        $exists = $dispatch->getConnection()->getSchemaBuilder()->hasTable('task_reattempts');
        $audit = $exists ? TaskReattempt::query()->where('task_agent_dispatch_id', $dispatch->id)->first() : null;
        if ($audit === null && $dispatch->kind === 'implement' && $dispatch->round === 0
            && isset($dispatch->run()->first()?->input['reattempt_checkpoint_id'])) {
            throw new LogicException('The reattempt dispatch is missing its immutable audit.');
        }

        return $audit;
    }
}
