<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskWorkspace;
use App\Tasks\GitObjectId;
use InvalidArgumentException;
use LogicException;

final readonly class PrepareTaskReattempt
{
    public function __construct(private TaskReattemptGuard $guard, private TaskReattemptGit $git,
        private TaskReattemptInput $input, private TaskRuntimeLock $lock) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $workspaceId, int $dispatchId, string $head, string $manifest, array $request,
        bool $exclusive, ?string $state = null, bool $apply = false): array
    {
        if ($workspaceId < 1 || $dispatchId < 1 || ! $exclusive) {
            throw new InvalidArgumentException('Pin the workspace, blocked dispatch and exclusive ownership.');
        }
        GitObjectId::validate($head);
        $this->input->pin($manifest);
        if ($apply && (config('task-runtime.enabled') !== true || $state === null)) {
            throw new LogicException('Applying requires enabled runtime and the preview state pin.');
        }
        if ($state !== null) {
            $this->input->pin($state);
        }
        $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
        $request = $this->input->checkpoint($request, $workspace->worktree);
        $hash = $this->input->hash([$workspaceId, $dispatchId, $head, $manifest, $request, $state]);
        if (($existing = $this->recorded($dispatchId, $hash)) !== null) {
            return $existing;
        }
        $binding = $this->guard->origin($workspace, $dispatchId, $head, $manifest);
        $prerequisite = $request['prerequisite'];
        if ($prerequisite['source'] === $workspace->source_key) {
            throw new LogicException('The repair must be separately owned prerequisite work.');
        }
        $this->git->prerequisite($workspace->worktree, $head, $prerequisite['candidate'], $prerequisite['merge'], $prerequisite['main'],
            preserveHistory: ($request['integration'] ?? null) === 'preserve_history');
        $this->guard->sessions($workspace, $binding);
        $observation = $this->git->observe($workspace->repository, $workspace->worktree);
        $observedState = $this->input->hash($observation);
        if ($observation['head'] !== $head || ($state !== null && ! hash_equals($state, $observedState))) {
            throw new LogicException('The original working tree changed from the preview.');
        }
        if (! $apply) {
            return ['applied' => false, 'recorded' => false, 'state_hash' => $observedState, 'observation' => $observation];
        }
        if ($this->git->observe($workspace->repository, $workspace->worktree, $hash) !== $observation
            || $this->git->observe($workspace->repository, $workspace->worktree) !== $observation) {
            throw new LogicException('The task changed while retaining its checkpoint.');
        }

        return $this->lock->handle($workspaceId, function () use ($workspaceId, $dispatchId, $head, $manifest, $request, $hash, $binding, $observation): array {
            if (($existing = $this->recorded($dispatchId, $hash)) !== null) {
                return $existing;
            }
            $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
            if ($this->guard->origin($workspace, $dispatchId, $head, $manifest) !== $binding) {
                throw new LogicException('Ledger or session ownership changed while observing the checkpoint.');
            }
            $this->git->assertRetained($workspace->worktree, $hash, $observation);
            $checkpoint = TaskReattemptCheckpoint::query()->create(['task_workspace_id' => $workspaceId,
                'task_agent_dispatch_id' => $dispatchId, 'request_hash' => $hash, 'binding' => $binding,
                'observation' => $observation, 'request' => $request, 'created_at' => now()]);

            return ['applied' => true, 'recorded' => true, 'checkpoint' => $checkpoint->toArray()];
        });
    }

    /** @return array<string, mixed>|null */
    private function recorded(int $dispatchId, string $hash): ?array
    {
        $checkpoint = TaskReattemptCheckpoint::query()->where('task_agent_dispatch_id', $dispatchId)->first();
        if ($checkpoint === null) {
            return null;
        }
        if (! hash_equals($checkpoint->request_hash, $hash)) {
            throw new LogicException('This blocked dispatch already has a conflicting checkpoint.');
        }
        $workspace = TaskWorkspace::query()->findOrFail($checkpoint->task_workspace_id);
        $this->git->assertRetained($workspace->worktree, $checkpoint->request_hash, $checkpoint->observation);

        return ['applied' => false, 'recorded' => true, 'checkpoint' => $checkpoint->toArray()];
    }
}
