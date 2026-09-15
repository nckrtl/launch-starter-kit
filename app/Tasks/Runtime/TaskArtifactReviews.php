<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Delivery;
use App\Models\TaskArtifactReviewBinding;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\TaskEvidenceSafety;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\Proof\TaskProofContract;
use App\Tasks\TaskGraph;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class TaskArtifactReviews
{
    public function __construct(private TaskRuntimeLock $lock, private TaskRuntimePlan $plans, private GitTaskWorktree $git,
        private TaskProofContract $contracts, private TaskEvidenceSafety $safety, private TaskMainIntegration $integrations,
        private TaskGraph $graph) {}

    public function active(TaskRun $run): bool
    {
        return $run->getConnection()->getSchemaBuilder()->hasTable('task_artifact_review_bindings')
            && TaskArtifactReviewBinding::on($run->getConnectionName())->where('task_run_id', $run->id)->exists();
    }

    /** @return array<string, mixed> */
    public function capture(int $workspaceId, int $runId, int $dispatchId, int $round, string $manifest,
        string $reason, bool $exclusive, bool $drained, ?string $expectedHash = null, bool $apply = false): array
    {
        if (! $exclusive || ! $drained || min($workspaceId, $runId, $dispatchId, $round) < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $manifest) !== 1 || trim($reason) === '' || strlen($reason) > 5000
            || ! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0")
            || ($apply && (config('task-runtime.enabled') !== true || preg_match('/\A[a-f0-9]{64}\z/D', $expectedHash ?? '') !== 1))) {
            throw new LogicException('Pin workspace/run/implementation/round/manifest, reason and exclusive drained ownership; apply requires the preview binding hash.');
        }

        return $this->lock->handle($workspaceId, function () use ($workspaceId, $runId, $dispatchId, $round, $manifest, $reason, $expectedHash, $apply): array {
            $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
            $run = TaskRun::query()->findOrFail($runId);
            $review = $run->reviews()->where('round', $round)->firstOrFail();
            $existing = $this->find($run, $round);
            if ($existing !== null) {
                $this->provenance($workspace, $run, $review, $existing);
                if ($existing->task_agent_dispatch_id !== $dispatchId || ($existing->binding['manifest_hash'] ?? null) !== $manifest
                    || ($existing->binding['reason'] ?? null) !== $reason
                    || ($expectedHash !== null && ! hash_equals($existing->binding_hash, $expectedHash))) {
                    throw new LogicException('This artifact round already has a conflicting binding.');
                }

                return ['recorded' => true, 'applied' => false, 'binding_id' => $existing->id, 'binding_hash' => $existing->binding_hash];
            }
            $this->supported($workspace, $run);
            if (Delivery::query()->active()->where(function (Builder $query) use ($workspace): void {
                $query->where('worktree_path', $workspace->worktree)->orWhere('external_issue_key', $workspace->source_key);
            })->exists() || TaskWorkspace::query()->whereKeyNot($workspace->id)->where(function (Builder $query) use ($workspace): void {
                $query->where('worktree', $workspace->worktree)->orWhere('source_key', $workspace->source_key);
            })->exists()) {
                throw new LogicException('Another controller owns the artifact source or worktree.');
            }
            $dispatch = $workspace->dispatches()->orderByDesc('id')->first();
            $task = $run->task()->firstOrFail();
            if ($dispatch === null || $dispatch->id !== $dispatchId || $dispatch->task_run_id !== $run->id
                || $dispatch->kind !== 'implement' || $dispatch->state !== 'acknowledged' || $dispatch->round + 1 !== $round
                || $dispatch->result === null || isset($dispatch->result['verdict']) || $dispatch->session === null
                || ($dispatch->session['agentName'] ?? null) !== $run->worker_ref || $review->output !== $dispatch->result
                || $review->verdict !== null || $run->reviews()->orderByDesc('round')->first()?->id !== $review->id
                || $run->status !== TaskRunStatus::Running || $run->active_task_id !== $task->id
                || $run->active_root_task_id !== $workspace->root_task_id || $task->status !== TaskStatus::AwaitingReview
                || $task->runs()->orderByDesc('attempt')->first()?->id !== $run->id
                || ($workspace->attention !== null && $workspace->attention !== $this->hold($run, $round))
                || $workspace->final_check !== null || $workspace->final_result !== null
                || $manifest !== $this->plans->effectiveHash($workspace) || $manifest !== $this->plans->hash($workspace->root()->firstOrFail())) {
                throw new LogicException('Bind only the genuine current acknowledged implementation handoff before review dispatch.');
            }
            $base = $run->base_sha ?? throw new LogicException('The artifact task must retain its original code base.');
            $tree = $this->git->tree($workspace->worktree, $base);
            if ($this->git->validate($workspace->repository, $workspace->worktree) !== $base || $review->tree_sha !== $tree) {
                throw new LogicException('Artifact acceptance requires unchanged tracked code at the original base tree.');
            }
            $contract = $this->contracts->read($workspace);
            $binding = ['schema' => 1, 'workspace_id' => $workspace->id, 'run_id' => $run->id,
                'implementation_dispatch_id' => $dispatch->id, 'review_id' => $review->id, 'round' => $round,
                'manifest_hash' => $manifest, 'workspace_manifest_hash' => $workspace->manifest_hash,
                'run_input_hash' => TaskLandingData::hash([$run->task_id, $run->root_task_id, $run->attempt, $run->input, $run->worker_ref, $run->reviewer_ref]),
                'base_sha' => $base, 'tree_sha' => $tree, 'handoff_hash' => TaskLandingData::hash($dispatch->result),
                'contract' => $contract, 'artifact_modes' => $this->modes($workspace, $contract),
                'reason' => $reason, 'exclusive' => true, 'drained' => true];
            $this->safety->assertNoSecrets($workspace, $binding);
            $hash = TaskLandingData::hash($binding);
            if ($expectedHash !== null && ! hash_equals($hash, $expectedHash)) {
                throw new LogicException('The artifact package or assignment changed since preview.');
            }
            if (! $apply) {
                return ['recorded' => false, 'applied' => false, 'binding_hash' => $hash,
                    'base_sha' => $base, 'tree_sha' => $tree, 'files' => array_map(fn (array $file): array => array_diff_key($file, ['contents' => true]), $contract)];
            }
            $audit = TaskArtifactReviewBinding::query()->create(['task_workspace_id' => $workspace->id, 'task_run_id' => $run->id,
                'task_agent_dispatch_id' => $dispatch->id, 'task_run_review_id' => $review->id, 'round' => $round,
                'binding_hash' => $hash, 'binding' => $binding, 'created_at' => now()]);
            if ($workspace->attention === $this->hold($run, $round)) {
                $workspace->update(['attention' => null]);
            }

            return ['recorded' => true, 'applied' => true, 'binding_id' => $audit->id, 'binding_hash' => $hash];
        });
    }

    public function hold(TaskRun $run, int $round): string
    {
        return 'Artifact run '.$run->id.' round '.$round.' requires tasks:bind-artifacts before review.';
    }

    public function find(TaskRun $run, int $round): ?TaskArtifactReviewBinding
    {
        return $this->active($run) ? TaskArtifactReviewBinding::on($run->getConnectionName())->where('task_run_id', $run->id)->where('round', $round)->first() : null;
    }

    public function verifyLive(TaskWorkspace $workspace, TaskRun $run, TaskRunReview $review): TaskArtifactReviewBinding
    {
        $audit = $this->find($run, $review->round) ?? throw new LogicException($this->hold($run, $review->round));
        $this->provenance($workspace, $run, $review, $audit);
        if ($this->plans->effectiveHash($workspace) !== $audit->binding['manifest_hash']
            || $this->plans->hash($workspace->root()->firstOrFail()) !== $audit->binding['manifest_hash']
            || $this->git->validate($workspace->repository, $workspace->worktree) !== $run->base_sha) {
            throw new LogicException('Artifact review assignment or unchanged code base changed.');
        }
        $this->assertContract($workspace, $audit, $this->contracts->read($workspace));

        return $audit;
    }

    public function assertAcceptance(TaskRun $run, TaskRunReview $review): void
    {
        $audit = $this->find($run, $review->round) ?? throw new LogicException('No artifact result authorizes null-commit acceptance.');
        $workspace = TaskWorkspace::on($run->getConnectionName())->findOrFail($audit->task_workspace_id);
        $this->verifyLive($workspace, $run, $review);
        $dispatch = $workspace->dispatches()->orderByDesc('id')->first();
        if ($dispatch === null || $dispatch->kind !== 'review' || $dispatch->state !== 'submitting'
            || $dispatch->task_run_id !== $run->id || $dispatch->round !== $review->round
            || ($dispatch->session['agentName'] ?? null) !== $run->reviewer_ref || $run->worker_ref === $run->reviewer_ref) {
            throw new LogicException('Artifact acceptance belongs atomically to the independent reviewer handoff, never a commit instruction.');
        }
    }

    /** @return array<string, mixed>|null */
    public function accepted(TaskWorkspace $workspace, TaskRun $run, TaskRunReview $review): ?array
    {
        if (! $this->active($run)) {
            return null;
        }
        $audit = $this->find($run, $review->round) ?? throw new LogicException('The accepted artifact round has no binding.');
        $this->provenance($workspace, $run, $review, $audit);
        $dispatch = $workspace->dispatches()->where('task_run_id', $run->id)->where('kind', 'review')->where('round', $review->round)->first();
        if ($run->status !== TaskRunStatus::Completed || $run->commit_sha !== null || $review->verdict !== TaskReviewVerdict::Pass
            || $run->output !== $review->output || $dispatch === null || $dispatch->state !== 'acknowledged'
            || ($dispatch->session['agentName'] ?? null) !== $run->reviewer_ref
            || $dispatch->result != ['summary' => $review->summary, 'evidence' => $review->evidence_ref, 'verdict' => 'pass']) {
            throw new LogicException('An artifact result requires its exact independent accepted review and no commit.');
        }

        return ['id' => $audit->id, 'binding_hash' => $audit->binding_hash, 'binding' => $audit->binding];
    }

    /** @return list<array<string, mixed>> */
    public function acceptedBindings(TaskWorkspace $workspace): array
    {
        if (! $workspace->getConnection()->getSchemaBuilder()->hasTable('task_artifact_review_bindings')
            || ! TaskArtifactReviewBinding::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->exists()) {
            return [];
        }
        $bindings = [];
        foreach ($this->graph->orderedChildren($workspace->root()->firstOrFail()) as $child) {
            $run = $child->acceptedRun()->first();
            $review = $run?->reviews()->orderByDesc('round')->first();
            if ($run !== null && $review !== null && ($binding = $this->accepted($workspace, $run, $review)) !== null) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    /** @param list<array{path:string,mode:string,sha256:string,contents:string}> $contract */
    public function assertPublication(TaskWorkspace $workspace, array $contract): void
    {
        $bindings = $this->acceptedBindings($workspace);
        if ($bindings === []) {
            return;
        }
        $latest = $bindings[array_key_last($bindings)];
        if (! is_int($latest['id'] ?? null)) {
            throw new LogicException('The accepted artifact binding identity is invalid.');
        }
        $audit = TaskArtifactReviewBinding::on($workspace->getConnectionName())->findOrFail($latest['id']);
        $this->assertContract($workspace, $audit, $contract);
        $this->assertContract($workspace, $audit, $this->contracts->read($workspace));
    }

    public function verifyAcceptedArtifacts(TaskWorkspace $workspace): void
    {
        if ($this->acceptedBindings($workspace) !== []) {
            $this->assertPublication($workspace, $this->contracts->read($workspace));
        }
    }

    /** @return array<string, mixed>|null */
    public function publicationInputMismatch(TaskWorkspace $workspace): ?array
    {
        $bindings = $this->acceptedBindings($workspace);
        if ($bindings === []) {
            return null;
        }
        $latest = $bindings[array_key_last($bindings)];
        if (! is_int($latest['id'] ?? null)) {
            throw new LogicException('The accepted artifact binding identity is invalid.');
        }
        $audit = TaskArtifactReviewBinding::on($workspace->getConnectionName())->findOrFail($latest['id']);
        $contract = $this->contracts->read($workspace);
        $bound = $audit->binding['contract'] ?? null;
        if (! is_array($bound) || ! array_is_list($bound)
            || ($audit->binding['artifact_modes'] ?? null) !== $this->modes($workspace, $contract)) {
            throw new LogicException('The proof package differs from the independently bound artifact result.');
        }
        $expected = [];
        foreach ($bound as $file) {
            $file = TaskLandingData::object($file);
            $expected[TaskLandingData::text($file, 'path')] = $file;
        }
        $actual = array_column($contract, null, 'path');
        $changes = [];
        $entries = [];
        foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $path) {
            $before = $expected[$path] ?? null;
            $after = $actual[$path] ?? null;
            if ($before === $after) {
                continue;
            }
            if (str_starts_with($path, '.loop/')) {
                throw new LogicException('The proof package differs from the independently bound artifact result.');
            }
            if ($before === null || $after === null) {
                throw new LogicException('Proof input inventory changes require inspection, not a candidate-input correction hold.');
            }
            $entries[] = $after['mode'].' blob '.hash('sha1', 'blob '.strlen($after['contents'])."\0".$after['contents'])."\t".$path;
            $changes[] = ['path' => $path,
                'expected' => ['mode' => $before['mode'], 'sha256' => $before['sha256']],
                'actual' => ['mode' => $after['mode'], 'sha256' => $after['sha256']]];
        }
        if ($changes === []) {
            $this->assertContract($workspace, $audit, $contract);

            return null;
        }
        $result = Process::path($workspace->worktree)->timeout(30)->env([...TaskProcessEnvironment::isolated(),
            'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
            'GIT_NO_LAZY_FETCH' => '1', 'GIT_LITERAL_PATHSPECS' => '1', 'GIT_OPTIONAL_LOCKS' => '0',
        ])->run(['git', '--no-replace-objects', 'ls-tree', '-z', $this->git->head($workspace->worktree), '--', ...array_column($changes, 'path')]);
        $observed = $result->output() === '' ? [] : explode("\0", rtrim($result->output(), "\0"));
        sort($entries, SORT_STRING);
        sort($observed, SORT_STRING);
        if ($result->failed() || $observed !== $entries) {
            throw new LogicException('Changed proof inputs must match their exact candidate blobs and modes.');
        }

        return ['schema' => 1, 'binding_id' => $audit->id, 'binding_hash' => $audit->binding_hash,
            'expected_contract_hash' => TaskLandingData::hash($bound), 'actual_contract_hash' => TaskLandingData::hash($contract),
            'changes' => $changes];
    }

    private function supported(TaskWorkspace $workspace, TaskRun $run): void
    {
        if ($workspace->project_id !== 'orbit' || $run->root_task_id !== $workspace->root_task_id
            || ($run->input['workspace_id'] ?? null) !== $workspace->id || $run->attempt !== 1
            || $run->worker_ref === $run->reviewer_ref || $this->integrations->binding($run) !== null
            || OrbitTaskProfile::forWorkspace($workspace) !== ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]) {
            throw new LogicException('Artifact results require an original Orbit replacement-proof task, without a main-integration assignment.');
        }
        foreach (['task_reattempts', 'task_reattempt_checkpoints'] as $table) {
            if ($workspace->getConnection()->getSchemaBuilder()->hasTable($table)
                && $workspace->getConnection()->table($table)->where('task_workspace_id', $workspace->id)->exists()) {
                throw new LogicException('Artifact results do not support recovered or reattempt workspaces.');
            }
        }
        if ($workspace->getConnection()->getSchemaBuilder()->hasTable('task_recoveries')
            && $workspace->getConnection()->table('task_recoveries')->where('root_task_id', $workspace->root_task_id)->exists()) {
            throw new LogicException('Artifact results do not support recovered workspaces.');
        }
    }

    private function provenance(TaskWorkspace $workspace, TaskRun $run, TaskRunReview $review, TaskArtifactReviewBinding $audit): void
    {
        $this->supported($workspace, $run);
        $binding = $audit->binding;
        $origin = $workspace->dispatches()->find($audit->task_agent_dispatch_id);
        if (! hash_equals($audit->binding_hash, TaskLandingData::hash($binding))
            || ($binding['schema'] ?? null) !== 1 || ($binding['exclusive'] ?? null) !== true || ($binding['drained'] ?? null) !== true
            || $audit->task_workspace_id !== $workspace->id || $audit->task_run_id !== $run->id
            || $audit->task_run_review_id !== $review->id || $audit->round !== $review->round
            || ($binding['workspace_id'] ?? null) !== $workspace->id || ($binding['run_id'] ?? null) !== $run->id
            || ($binding['review_id'] ?? null) !== $review->id || ($binding['round'] ?? null) !== $review->round
            || ($binding['implementation_dispatch_id'] ?? null) !== $audit->task_agent_dispatch_id
            || ($binding['workspace_manifest_hash'] ?? null) !== $workspace->manifest_hash
            || ! is_string($binding['manifest_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $binding['manifest_hash']) !== 1
            || ($binding['run_input_hash'] ?? null) !== TaskLandingData::hash([$run->task_id, $run->root_task_id, $run->attempt, $run->input, $run->worker_ref, $run->reviewer_ref])
            || ($binding['base_sha'] ?? null) !== $run->base_sha || ($binding['tree_sha'] ?? null) !== $review->tree_sha
            || $run->base_sha === null || $this->git->tree($workspace->worktree, $run->base_sha) !== $review->tree_sha
            || $origin === null || $origin->kind !== 'implement' || $origin->state !== 'acknowledged'
            || $origin->task_run_id !== $run->id || $origin->round + 1 !== $review->round
            || ($origin->session['agentName'] ?? null) !== $run->worker_ref || $origin->result !== $review->output
            || ($binding['handoff_hash'] ?? null) !== TaskLandingData::hash($origin->result)) {
            throw new LogicException('The immutable artifact result no longer matches its original assignment and handoff.');
        }
        $this->safety->assertNoSecrets($workspace, $binding);
    }

    /** @param list<array{path:string,mode:string,sha256:string,contents:string}> $contract */
    private function assertContract(TaskWorkspace $workspace, TaskArtifactReviewBinding $audit, array $contract): void
    {
        if (($audit->binding['contract'] ?? null) !== $contract || ($audit->binding['artifact_modes'] ?? null) !== $this->modes($workspace, $contract)) {
            throw new LogicException('The proof package differs from the independently bound artifact result.');
        }
    }

    /** @param list<array{path:string,mode:string,sha256:string,contents:string}> $contract
     * @return array<string, string>
     */
    private function modes(TaskWorkspace $workspace, array $contract): array
    {
        $modes = [];
        foreach ($contract as $file) {
            $path = $file['path'];
            if (! str_starts_with($path, '.loop/proof/')) {
                continue;
            }
            clearstatcache(true, $workspace->worktree.'/'.$path);
            $stat = lstat($workspace->worktree.'/'.$path);
            if ($stat === false || ($stat['mode'] & 0177777) !== 0100644) {
                throw new LogicException('Artifact proof files must retain regular mode 0644.');
            }
            $modes[$path] = '644';
        }
        $this->git->assertIgnored($workspace->worktree, array_keys($modes));

        return $modes;
    }
}
