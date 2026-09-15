<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskFinalContinuation;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\GitObjectId;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use InvalidArgumentException;
use LogicException;

final readonly class ContinueTaskFinal
{
    public function __construct(private TaskRuntimeLock $lock, private TaskMutation $mutation, private TaskRuntimePlan $plans,
        private TaskGraph $graph, private GitTaskWorktree $git, private TaskAgents $agents, private TaskManifestAmendmentHistory $history,
        private TaskArtifactReviews $artifacts) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function handle(int $workspaceId, int $dispatchId, string $head, string $manifest, array $request, bool $exclusive, bool $apply = false): array
    {
        if ($workspaceId < 1 || $dispatchId < 1 || ! $exclusive || preg_match('/\A[a-f0-9]{64}\z/', $manifest) !== 1) {
            throw new InvalidArgumentException('Pin the workspace, final dispatch, manifest and exclusive ownership.');
        }
        GitObjectId::validate($head);
        $request = $this->request($request);
        $hash = hash('sha256', json_encode([$workspaceId, $dispatchId, $head, $manifest, $request], JSON_THROW_ON_ERROR));
        if ($apply && config('task-runtime.enabled') !== true) {
            throw new LogicException('Enable the task runtime before applying a final continuation.');
        }
        $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
        $preview = $this->continue($workspace, $dispatchId, $head, $manifest, $request, $hash, false, true);
        if (! $apply || $preview['recorded'] === true) {
            return $preview;
        }
        $observed = $workspace->toArray();

        return $this->lock->handle($workspaceId, fn (): array => $this->mutation->handle($workspace->project_id,
            function () use ($workspaceId, $dispatchId, $head, $manifest, $request, $hash, $observed): array {
                if (($existing = $this->recorded($dispatchId, $hash)) !== null) {
                    return $existing;
                }
                $current = TaskWorkspace::query()->findOrFail($workspaceId);
                if ($current->toArray() !== $observed) {
                    throw new LogicException('Workspace ownership or final state changed during observation.');
                }

                return $this->continue($current, $dispatchId, $head, $manifest, $request, $hash, true, false);
            }));
    }

    /**
     * @param  array{mode:string,reason:string,evidence:string,main_sha?:string,task?:array{title:string,description:string,acceptance_criteria:string,root_criterion:string},environment_repair?:array{cause:string,change:string,verification:string}}  $request
     * @return array<string, mixed>
     */
    private function continue(TaskWorkspace $workspace, int $dispatchId, string $head, string $manifest, array $request, string $hash, bool $apply, bool $observe): array
    {
        if (($existing = $this->recorded($dispatchId, $hash)) !== null) {
            return $existing;
        }
        $root = $workspace->root()->firstOrFail();
        $dispatch = $workspace->dispatches()->orderByDesc('id')->first();
        if ($dispatch === null || $dispatch->id !== $dispatchId || $dispatch->kind !== 'final_review'
            || $root->status !== TaskStatus::Pending || $workspace->attention === null
            || ($workspace->final_result['verdict'] ?? null) === 'pass') {
            throw new LogicException('Only the current held final attempt of an incomplete feature can continue.');
        }
        $integration = $request['mode'] === 'integrate_main';
        $mainSha = $request['main_sha'] ?? null;
        if ($integration && $mainSha === null) {
            throw new LogicException('The integration main SHA is required.');
        }
        $check = $dispatch->final_check;
        if (! $integration && ($dispatch->final_check_version !== 1 || $check === null || ($check['candidate_unchanged'] ?? null) !== true || ($check['sha'] ?? null) !== $head
            || ($check['manifest_hash'] ?? null) !== $manifest || $workspace->final_check !== $check)) {
            throw new LogicException('Final continuation requires exact dispatch check evidence; legacy or changed candidates need inspection.');
        }
        $verdict = $dispatch->result['verdict'] ?? null;
        $artifactHold = ! $integration && $request['mode'] === 'append_correction'
            && $dispatch->state === 'check_failed' && ($check['exit_code'] ?? null) === 0
            && isset($check['artifact_input_mismatch']) && ! array_key_exists('native_proof', $check)
            && $dispatch->session === null && $dispatch->result === null
            && $workspace->project_id === 'orbit'
            && OrbitTaskProfile::forWorkspace($workspace) === ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]
            && $this->git->validate($workspace->repository, $workspace->worktree) === $head
            && $this->artifacts->publicationInputMismatch($workspace) === $check['artifact_input_mismatch'];
        $expectedAttention = match (true) {
            $integration && $dispatch->state === 'integration_required' => $dispatch->error,
            $artifactHold => $dispatch->error,
            $dispatch->state === 'check_failed' && is_int($check['exit_code'] ?? null) && $check['exit_code'] !== 0 => $dispatch->error,
            $dispatch->state === 'acknowledged' && in_array($verdict, ['revise', 'blocked'], true) => $dispatch->result['summary'] ?? null,
            default => null,
        };
        if ($integration && ($workspace->project_id !== 'orbit'
            || (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || $dispatch->state !== 'integration_required'
            || $dispatch->final_preflight_version !== 1 || $dispatch->session !== null || $dispatch->result !== null
            || ($dispatch->final_preflight['head'] ?? null) !== $head
            || ! is_string($dispatch->final_preflight['main_sha'] ?? null)
            || ($dispatch->final_preflight['main_is_ancestor'] ?? null) !== false
            || ($dispatch->final_preflight['manifest_hash'] ?? null) !== $manifest
            || $workspace->final_check !== $check
            || $workspace->dispatches()->whereKeyNot($dispatch->id)->whereNotIn('state', ['acknowledged', 'check_failed', 'integration_required'])->exists())) {
            throw new LogicException('Main integration requires its exact unprompted proof preflight hold.');
        }
        if ($integration && (TaskReattempt::query()->where('task_workspace_id', $workspace->id)->exists()
            || TaskReattemptCheckpoint::query()->where('task_workspace_id', $workspace->id)->exists())) {
            throw new LogicException('Main integration does not yet support reattempt or checkpoint workspaces; retain their recovery evidence.');
        }
        if (! is_string($expectedAttention) || $workspace->attention !== $expectedAttention
            || $dispatch->round !== $this->plans->finalRound($workspace)
            || ($verdict === 'revise' ? $workspace->final_result !== $dispatch->result : $workspace->final_result !== null)) {
            throw new LogicException('The final attempt is uncertain or its attention hold has changed.');
        }
        if (! hash_equals($this->plans->effectiveHash($workspace), $manifest) || ! hash_equals($this->plans->hash($root), $manifest)) {
            throw new LogicException('The approved effective manifest changed.');
        }
        $children = $this->graph->orderedChildren($root);
        $hasArtifacts = $this->artifacts->acceptedBindings($workspace) !== [];
        $tip = $workspace->base_sha;
        foreach ($children as $child) {
            if ($child->status !== TaskStatus::Completed) {
                throw new LogicException('Accept every existing child before continuing final verification.');
            }
            if (! $hasArtifacts) {
                continue;
            }
            $acceptedRun = $child->acceptedRun()->firstOrFail();
            $review = $acceptedRun->reviews()->orderByDesc('round')->firstOrFail();
            $artifact = $this->artifacts->accepted($workspace, $acceptedRun, $review);
            if ($acceptedRun->base_sha !== $tip || ($acceptedRun->commit_sha === null && $artifact === null)) {
                throw new LogicException('Final continuation requires the exact accepted code and artifact chain.');
            }
            $tip = $acceptedRun->commit_sha ?? $tip;
        }
        $tail = $children === [] ? null : $children[array_key_last($children)];
        $last = TaskRun::query()->where('root_task_id', $root->id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
        if ($tail === null || $last === null || $last->commit_sha !== $head
            || ($hasArtifacts ? $tip !== $head : $tail->accepted_task_run_id !== $last->id)
            || TaskRun::query()->where('active_root_task_id', $root->id)->exists()) {
            throw new LogicException('Continue only from the clean last accepted child commit, with no active run.');
        }
        $reviewer = $workspace->reviewer_session;
        if ($reviewer === null) {
            throw new LogicException('The retained reviewer must be reconciled before continuing.');
        }
        if ($observe) {
            if ($this->git->inspect($workspace->repository, $workspace->worktree) !== $head) {
                throw new LogicException('The worktree changed from the last accepted child commit.');
            }
            $this->agents->assertSession($workspace, $reviewer);
            if ($integration) {
                $main = $this->git->currentMain($workspace->repository, $workspace->worktree);
                if ($main['head'] !== $head || $main['main_sha'] !== $mainSha || $main['main_is_ancestor']) {
                    throw new LogicException('The pinned current main is stale or already integrated; the request cannot be applied.');
                }
                if (! $this->git->isAncestor($workspace->worktree, $dispatch->final_preflight['main_sha'], $main['main_sha'])) {
                    throw new LogicException('Current main no longer descends from the held main; force-push reconciliation is not supported.');
                }
            }
        }
        if (isset($request['task']) && ! str_contains($root->acceptance_criteria, $request['task']['root_criterion'])) {
            throw new LogicException('The correction must quote an existing root acceptance criterion without changing feature scope.');
        }
        if (! $apply) {
            return ['applied' => false, 'recorded' => false, 'mode' => $request['mode'], 'head' => $head,
                'manifest_hash' => $manifest, 'final_round' => $dispatch->round + 1];
        }
        $task = null;
        $before = $this->plans->manifest($root);
        $this->history->ledger($workspace, $before);
        if (isset($request['task']) || $integration) {
            $brief = $integration ? [
                'title' => 'Integrate current main before proof',
                'description' => 'Integrate the pinned origin/main '.$mainSha.' into the accepted feature tail '.$head.'. Resolve only integration conflicts and regressions. Preserve all accepted task commits and feature scope.',
                'acceptance_criteria' => 'The independently reviewed integration tree preserves all root acceptance criteria and accepted task behavior. Run the focused checks affected by integration. Leave exactly the pinned main in MERGE_HEAD and an index without conflicts. Do not commit; after review passes, the retained reviewer creates one merge commit with ordered parents ['.$head.', '.$mainSha.']. Commander must accept that exact reviewed tree and parent pair before proof.',
            ] : $request['task'];
            $task = Task::query()->create(['project_id' => $root->project_id, 'parent_id' => $root->id,
                'kind' => TaskKind::Executable, 'title' => $brief['title'], 'description' => $brief['description'],
                'acceptance_criteria' => $brief['acceptance_criteria']]);
            $task->dependencies()->attach($tail->id);
        }
        $audit = TaskFinalContinuation::query()->create(['task_workspace_id' => $workspace->id,
            'task_agent_dispatch_id' => $dispatch->id, 'task_id' => $task?->id, 'mode' => $request['mode'],
            'request_hash' => $hash, 'head' => $head, 'previous_manifest_hash' => $manifest,
            'manifest_hash' => $this->plans->hash($root), 'final_round' => $dispatch->round + 1,
            'previous_attention' => $workspace->attention, 'previous_result' => $workspace->final_result,
            'previous_manifest' => $before, 'manifest' => $this->plans->manifest($root),
            'request' => $request, 'created_at' => now()]);
        $workspace->continueFinal($audit);

        return ['applied' => true, 'recorded' => true, 'audit' => $audit->toArray()];
    }

    /** @return array<string, mixed>|null */
    private function recorded(int $dispatchId, string $hash): ?array
    {
        $existing = TaskFinalContinuation::query()->where('task_agent_dispatch_id', $dispatchId)->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->request_hash, $hash)) {
            throw new LogicException('This final attempt already has a conflicting continuation.');
        }

        return ['applied' => false, 'recorded' => true, 'audit' => $existing->toArray()];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array{mode:string,reason:string,evidence:string,main_sha?:string,task?:array{title:string,description:string,acceptance_criteria:string,root_criterion:string},environment_repair?:array{cause:string,change:string,verification:string}}
     */
    private function request(array $request): array
    {
        $mode = $request['mode'] ?? null;
        if (! in_array($mode, ['append_correction', 'retry_final_checks', 'integrate_main'], true)
            || array_diff(array_keys($request), ['mode', 'reason', 'evidence', 'task', 'environment_repair', 'main_sha']) !== []) {
            throw new InvalidArgumentException('Choose append_correction or retry_final_checks with bounded reason and evidence.');
        }
        $normalized = ['mode' => $mode, 'reason' => $this->text($request, 'reason'), 'evidence' => $this->text($request, 'evidence')];
        if ($mode === 'integrate_main') {
            if (array_key_exists('task', $request) || array_key_exists('environment_repair', $request)) {
                throw new InvalidArgumentException('Main integration cannot change the feature scope or append a custom task.');
            }
            $normalized['main_sha'] = $this->text($request, 'main_sha', 64);
            GitObjectId::validate($normalized['main_sha']);
        } elseif (array_key_exists('main_sha', $request)) {
            throw new InvalidArgumentException('Only an audited main integration may pin a second parent.');
        } elseif ($mode === 'append_correction') {
            $task = $request['task'] ?? null;
            if (! is_array($task) || array_key_exists('environment_repair', $request)
                || array_diff(array_keys($task), ['title', 'description', 'acceptance_criteria', 'root_criterion']) !== []) {
                throw new InvalidArgumentException('Append one complete corrective task brief.');
            }
            $normalized['task'] = ['title' => $this->text($task, 'title', 255), 'description' => $this->text($task, 'description'),
                'acceptance_criteria' => $this->text($task, 'acceptance_criteria'), 'root_criterion' => $this->text($task, 'root_criterion')];
        } else {
            $repair = $request['environment_repair'] ?? null;
            if (array_key_exists('task', $request) || ! is_array($repair)
                || array_diff(array_keys($repair), ['cause', 'change', 'verification']) !== []) {
                throw new InvalidArgumentException('A retry requires environment repair observations and cannot change implementation tasks.');
            }
            $normalized['environment_repair'] = ['cause' => $this->text($repair, 'cause'), 'change' => $this->text($repair, 'change'),
                'verification' => $this->text($repair, 'verification')];
        }

        return $normalized;
    }

    /** @param array<array-key, mixed> $values */
    private function text(array $values, string $key, int $limit = 100_000): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException('A nonempty bounded '.$key.' is required.');
        }

        return $value;
    }
}
