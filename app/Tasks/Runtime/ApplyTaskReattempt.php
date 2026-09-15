<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Actions\FailTaskRun;
use App\Tasks\Actions\StartTaskRun;
use App\Tasks\Enums\TaskStatus;
use InvalidArgumentException;
use LogicException;

final readonly class ApplyTaskReattempt
{
    public function __construct(private TaskReattemptGuard $guard, private TaskReattemptGit $git, private TaskReattemptInput $input,
        private TaskRuntimeLock $lock, private FailTaskRun $fail, private StartTaskRun $start, private TaskAgentPrompt $prompts) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $checkpointId, array $request, bool $exclusive, ?string $state = null, bool $apply = false): array
    {
        if ($checkpointId < 1 || ! $exclusive) {
            throw new InvalidArgumentException('Pin the checkpoint and exclusive worktree ownership.');
        }
        if ($apply && (config('task-runtime.enabled') !== true || $state === null)) {
            throw new LogicException('Applying requires enabled runtime and the preview state pin.');
        }
        if ($state !== null) {
            $this->input->pin($state);
        }
        $checkpoint = TaskReattemptCheckpoint::query()->findOrFail($checkpointId);
        $workspace = TaskWorkspace::query()->findOrFail($checkpoint->task_workspace_id);
        $request = $this->input->restoration($request, $workspace->worktree);
        $hash = $this->input->hash([$checkpointId, $request, $state]);
        if (($existing = $this->recorded($checkpointId, $hash)) !== null) {
            return $existing;
        }
        $binding = $checkpoint->binding;
        if ($this->guard->origin($workspace, $checkpoint->task_agent_dispatch_id, $checkpoint->observation['head'], $binding['manifest']) !== $binding) {
            throw new LogicException('The held ledger no longer matches its preservation checkpoint.');
        }
        $preservation = $this->input->checkpoint($checkpoint->request, $workspace->worktree);
        $prerequisite = $preservation['prerequisite'];
        $preserveHistory = ($preservation['integration'] ?? null) === 'preserve_history';
        $this->git->prerequisite($workspace->worktree, $checkpoint->observation['head'], $prerequisite['candidate'], $prerequisite['merge'], $prerequisite['main'],
            preserveHistory: $preserveHistory);
        $this->guard->sessions($workspace, $binding);
        $this->git->assertRetained($workspace->worktree, $checkpoint->request_hash, $checkpoint->observation);
        $observation = $this->git->observe($workspace->repository, $workspace->worktree);
        $base = $prerequisite['main'];
        if ($preserveHistory) {
            $this->git->integration($workspace->worktree, $checkpoint->observation['head'], $prerequisite['main'], $observation['head']);
            $base = $observation['head'];
        }
        $restored = $this->git->restored($workspace->worktree, $checkpoint->observation, $base);
        if ($observation['head'] !== $base || $observation['branch'] !== $checkpoint->observation['branch']
            || $observation['git_directory'] !== $checkpoint->observation['git_directory']
            || $observation['common_directory'] !== $checkpoint->observation['common_directory']
            || $observation['tree'] !== $restored['tree'] || $observation['index_tree'] !== $restored['index_tree']
            || ($state !== null && ! hash_equals($state, $this->input->hash($observation)))) {
            throw new LogicException('The worktree must contain only the exact restored task changes on the pinned upstream base.');
        }
        if (! $apply) {
            return ['applied' => false, 'recorded' => false, 'state_hash' => $this->input->hash($observation), 'observation' => $observation];
        }
        if ($this->git->observe($workspace->repository, $workspace->worktree) !== $observation) {
            throw new LogicException('Restored task state changed before cutover.');
        }

        return $this->lock->handle($workspace->id, function () use ($checkpoint, $request, $hash, $binding, $observation): array {
            if (($existing = $this->recorded($checkpoint->id, $hash)) !== null) {
                return $existing;
            }
            $workspace = TaskWorkspace::query()->findOrFail($checkpoint->task_workspace_id);
            if ($this->guard->origin($workspace, $checkpoint->task_agent_dispatch_id, $checkpoint->observation['head'], $binding['manifest']) !== $binding) {
                throw new LogicException('Ledger or session ownership changed before cutover.');
            }
            $this->git->assertRetained($workspace->worktree, $checkpoint->request_hash, $checkpoint->observation);
            $old = TaskRun::query()->findOrFail($binding['run_id']);
            $task = $old->task()->firstOrFail();
            $this->fail->handle($old, 0, TaskStatus::Running, 'Incomplete attempt superseded by audited prerequisite integration.',
                ['checkpoint_id' => $checkpoint->id, 'blocked_dispatch_id' => $checkpoint->task_agent_dispatch_id]);
            $run = $this->start->handle($task->refresh(), 'reattempt-checkpoint-'.$checkpoint->id, $old->worker_ref, $old->reviewer_ref,
                ['workspace_id' => $workspace->id, 'reattempt_checkpoint_id' => $checkpoint->id], $observation['head']);
            $token = bin2hex(random_bytes(32));
            $dispatch = $workspace->dispatches()->create(['task_run_id' => $run->id, 'step_key' => $run->id.':implement:0',
                'kind' => 'implement', 'round' => 0, 'token_hash' => hash('sha256', $token), 'handoff_token' => $token,
                'session' => $binding['worker_session'], 'prompt' => '']);
            $audit = TaskReattempt::query()->create(['task_reattempt_checkpoint_id' => $checkpoint->id, 'task_workspace_id' => $workspace->id,
                'task_run_id' => $run->id, 'task_agent_dispatch_id' => $dispatch->id, 'request_hash' => $hash,
                'observation' => $observation, 'request' => $request, 'created_at' => now()]);
            $dispatch->update(['prompt' => $this->prompts->render($workspace, $dispatch, $token, $run)]);
            $workspace->update(['attention' => null]);

            return ['applied' => true, 'recorded' => true, 'audit' => $audit->toArray()];
        });
    }

    /** @return array<string, mixed>|null */
    private function recorded(int $checkpointId, string $hash): ?array
    {
        $audit = TaskReattempt::query()->where('task_reattempt_checkpoint_id', $checkpointId)->first();
        if ($audit === null) {
            return null;
        }
        if (! hash_equals($audit->request_hash, $hash)) {
            throw new LogicException('This checkpoint already has a conflicting reattempt.');
        }
        $checkpoint = TaskReattemptCheckpoint::query()->findOrFail($checkpointId);
        $workspace = TaskWorkspace::query()->findOrFail($checkpoint->task_workspace_id);
        $this->git->assertRetained($workspace->worktree, $checkpoint->request_hash, $checkpoint->observation);

        return ['applied' => false, 'recorded' => true, 'audit' => $audit->toArray()];
    }
}
