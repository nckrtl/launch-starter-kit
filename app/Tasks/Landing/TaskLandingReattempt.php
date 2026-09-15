<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRecovery;
use App\Models\TaskRun;
use App\Models\TaskRunReview;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\GitObjectId;
use App\Tasks\Runtime\TaskReattemptInput;
use App\Tasks\Runtime\TaskRuntimePlan;
use LogicException;

final readonly class TaskLandingReattempt
{
    public function __construct(private TaskRuntimePlan $plans, private TaskReattemptInput $input, private TaskLandingReattemptGit $git) {}

    /** @return array<string, mixed>|null */
    public function ledger(TaskWorkspace $workspace, ?Task $first): ?array
    {
        $schema = $workspace->getConnection()->getSchemaBuilder();
        $checkpoints = $schema->hasTable('task_reattempt_checkpoints')
            ? TaskReattemptCheckpoint::query()->where('task_workspace_id', $workspace->id)->get() : collect();
        $audits = $schema->hasTable('task_reattempts')
            ? TaskReattempt::query()->where('task_workspace_id', $workspace->id)->get() : collect();
        $run = $first?->acceptedRun()->first();
        if ($checkpoints->isEmpty() && $audits->isEmpty() && $run?->base_sha === $workspace->base_sha
            && ! array_key_exists('reattempt_checkpoint_id', $run->input)) {
            return null;
        }
        if ($first === null || $run === null || $checkpoints->count() !== 1 || $audits->count() !== 1) {
            throw new LogicException('A changed admission base requires the exact first-child reattempt checkpoint and audit.');
        }
        $checkpoint = $checkpoints->sole();
        $audit = $audits->sole();
        if (! $checkpoint instanceof TaskReattemptCheckpoint || ! $audit instanceof TaskReattempt) {
            throw new LogicException('Missing typed reattempt audit records.');
        }
        $binding = TaskLandingData::object($checkpoint->binding);
        $original = TaskLandingData::object($binding['run'] ?? null);
        $oldId = $binding['run_id'] ?? null;
        if (! is_int($oldId) || $oldId < 1) {
            throw new LogicException('Missing original reattempt run identifier.');
        }
        $old = TaskRun::query()->findOrFail($oldId);
        $origin = TaskAgentDispatch::query()->findOrFail($checkpoint->task_agent_dispatch_id);
        $successor = TaskAgentDispatch::query()->findOrFail($audit->task_agent_dispatch_id);
        if ($checkpoint->task_workspace_id !== $workspace->id || $audit->task_workspace_id !== $workspace->id
            || $audit->task_reattempt_checkpoint_id !== $checkpoint->id || $audit->task_run_id !== $run->id
            || ($binding['task_id'] ?? null) !== $first->id || ($binding['manifest'] ?? null) !== $workspace->manifest_hash
            || $old->task_id !== $first->id || $old->root_task_id !== $workspace->root_task_id || $old->attempt !== 1
            || $old->status !== TaskRunStatus::Failed || $old->base_sha !== $workspace->base_sha || $old->commit_sha !== null
            || $old->active_task_id !== null || $old->active_root_task_id !== null || $old->finished_at === null || $old->reviews()->exists()
            || $old->failure_message !== 'Incomplete attempt superseded by audited prerequisite integration.'
            || $old->output !== ['handoff' => ['round' => 0, 'status' => 'running'],
                'result' => ['checkpoint_id' => $checkpoint->id, 'blocked_dispatch_id' => $origin->id]]
            || ($original['status'] ?? null) !== 'running' || ($original['active_task_id'] ?? null) !== $first->id
            || ($original['active_root_task_id'] ?? null) !== $workspace->root_task_id || ($original['commit_sha'] ?? null) !== null
            || ($original['output'] ?? null) !== null || ($original['failure_message'] ?? null) !== null || ($original['finished_at'] ?? null) !== null
            || $run->task_id !== $first->id || $run->root_task_id !== $workspace->root_task_id || $run->attempt !== 2
            || $run->idempotency_key !== 'reattempt-checkpoint-'.$checkpoint->id || $run->status !== TaskRunStatus::Completed
            || $run->worker_ref !== $old->worker_ref || $run->reviewer_ref !== $old->reviewer_ref
            || $run->input !== ['workspace_id' => $workspace->id, 'reattempt_checkpoint_id' => $checkpoint->id]
            || $first->runs()->count() !== 2 || TaskRun::query()->where('root_task_id', $workspace->root_task_id)->orderBy('id')->limit(2)->pluck('id')->all() !== [$old->id, $run->id]
            || $workspace->dispatches()->orderBy('id')->limit(2)->pluck('id')->all() !== [$origin->id, $successor->id]
            || ($schema->hasTable('task_recoveries') && TaskRecovery::query()->where('root_task_id', $workspace->root_task_id)->exists())) {
            throw new LogicException('The first accepted task does not retain the exact audited incomplete and replacement attempts.');
        }
        $current = $old->attributesToArray();
        foreach (['status', 'active_task_id', 'active_root_task_id', 'output', 'failure_message', 'finished_at', 'updated_at'] as $key) {
            unset($current[$key], $original[$key]);
        }
        if ($current !== $original) {
            throw new LogicException('The original incomplete run inputs or identity changed.');
        }
        $this->workspace($workspace, TaskLandingData::object($binding['workspace'] ?? null), $origin);
        $this->dispatches($workspace, $checkpoint, $origin, $successor, $old, $run);
        $preserved = $this->observation($checkpoint->observation);
        $restored = $this->observation($audit->observation);
        $request = $this->input->checkpoint($checkpoint->request, $workspace->worktree);
        $restoration = $this->input->restoration($audit->request, $workspace->worktree);
        $prerequisite = $request['prerequisite'];
        if ($request !== $checkpoint->request || $restoration !== $audit->request || $preserved['head'] !== $workspace->base_sha
            || $prerequisite['source'] === $workspace->source_key || $restored['head'] === $workspace->base_sha
            || (($request['integration'] ?? null) !== 'preserve_history' && $restored['head'] !== $prerequisite['main']) || $run->base_sha !== $restored['head']
            || $checkpoint->request_hash !== $this->input->hash([$workspace->id, $origin->id, $workspace->base_sha,
                $binding['manifest'], $request, $this->input->hash($preserved)])
            || $audit->request_hash !== $this->input->hash([$checkpoint->id, $restoration, $this->input->hash($restored)])) {
            throw new LogicException('Reattempt base, request hashes or prerequisite evidence are inconsistent.');
        }
        foreach (['branch', 'git_directory', 'common_directory'] as $key) {
            if ($preserved[$key] !== $restored[$key]) {
                throw new LogicException('Reattempt restoration changed its Git assignment.');
            }
        }
        $continuations = $this->manifestHistory($workspace);

        return ['schema' => 1, 'checkpoint_id' => $checkpoint->id, 'audit_id' => $audit->id,
            'admission_base' => $workspace->base_sha, 'base_sha' => $run->base_sha, 'original_manifest' => $binding['manifest'],
            'origin' => ['run_id' => $old->id, 'dispatch_id' => $origin->id, 'attempt' => 1,
                'input_hash' => $this->input->hash($old->input), 'receipt' => $origin->result, 'failure' => $old->failure_message],
            'replacement' => ['run_id' => $run->id, 'dispatch_id' => $successor->id, 'attempt' => 2,
                'worker_ref' => $run->worker_ref, 'reviewer_ref' => $run->reviewer_ref,
                'worker_identity' => HerdrTaskLandingReviewer::identity(TaskLandingData::object($binding['worker_session'] ?? null))],
            'checkpoint' => ['request_hash' => $checkpoint->request_hash, 'observation' => $preserved, 'request' => $request],
            'cutover' => ['request_hash' => $audit->request_hash, 'observation' => $restored, 'request' => $restoration],
            'manifest_history' => $continuations,
            'ledger_hash' => $this->input->hash([$checkpoint->toArray(), $audit->toArray(), $old->toArray(), $run->toArray(),
                $origin->toArray(), $successor->toArray(), $continuations])];
    }

    public function accepted(TaskRun $run, TaskRunReview $review): void
    {
        if ($run->active_task_id !== null || $run->active_root_task_id !== null || $run->finished_at === null
            || $run->failure_message !== null
            || $run->output !== $review->output || $review->reviewed_at === null || $review->round < 1
            || $run->task()->firstOrFail()->runs()->orderByDesc('attempt')->first()?->id !== $run->id) {
            throw new LogicException('The reattempt chain requires each current completed run and its exact accepted review output.');
        }
    }

    /** @param array<string, mixed> $database
     * @return array<string, mixed>|null
     */
    public function proof(TaskWorkspace $workspace, array $database): ?array
    {
        if (! array_key_exists('reattempt', $database)) {
            return null;
        }
        $accepted = $database['accepted_tasks'] ?? null;
        if (! is_array($accepted) || ! array_is_list($accepted) || $accepted === []) {
            throw new LogicException('Missing accepted reattempt chain.');
        }

        return $this->git->proof($workspace, TaskLandingData::object($database['reattempt']), array_map(TaskLandingData::object(...), $accepted));
    }

    /** @param array<string, mixed> $expected */
    private function workspace(TaskWorkspace $workspace, array $expected, TaskAgentDispatch $origin): void
    {
        if (($expected['attention'] ?? null) !== ($origin->result['summary'] ?? null)
            || ($expected['final_check'] ?? null) !== null || ($expected['final_result'] ?? null) !== null) {
            throw new LogicException('The original checkpoint did not preserve the initial blocked hold.');
        }
        if (($expected['reviewer_session'] ?? null) !== null) {
            HerdrTaskLandingReviewer::assertIdentity(TaskLandingData::object($expected['reviewer_session']), $workspace->reviewer_session ?? []);
        }
        $current = $workspace->attributesToArray();
        foreach (['attention', 'reviewer_session', 'final_check', 'final_result', 'updated_at'] as $key) {
            unset($expected[$key], $current[$key]);
        }
        if ($expected !== $current) {
            throw new LogicException('The original reattempt workspace assignment or admitted configuration changed.');
        }
    }

    private function dispatches(TaskWorkspace $workspace, TaskReattemptCheckpoint $checkpoint, TaskAgentDispatch $origin,
        TaskAgentDispatch $successor, TaskRun $old, TaskRun $run): void
    {
        $binding = TaskLandingData::object($checkpoint->binding);
        if ($origin->task_workspace_id !== $workspace->id || $origin->task_run_id !== $old->id
            || $origin->step_key !== $old->id.':implement:0'
            || $origin->kind !== 'implement' || $origin->round !== 0 || $origin->state !== 'acknowledged'
            || ($origin->result['verdict'] ?? null) !== 'blocked' || $origin->toArray() !== ($binding['dispatch'] ?? null)
            || $origin->session !== ($binding['worker_session'] ?? null) || $origin->session === null
            || $successor->task_workspace_id !== $workspace->id || $successor->task_run_id !== $run->id
            || $successor->step_key !== $run->id.':implement:0' || $successor->kind !== 'implement' || $successor->round !== 0
            || $successor->state !== 'acknowledged' || $successor->result === null || ($successor->result['verdict'] ?? null) !== null
            || $successor->session === null || $successor->token_hash === $origin->token_hash
            || $successor->token_hash !== hash('sha256', $successor->handoff_token)
            || $origin->token_hash !== hash('sha256', $origin->handoff_token)) {
            throw new LogicException('Reattempt dispatch, blocked receipt, fresh token or retained session provenance changed.');
        }
        foreach ([$origin->result, $successor->result] as $receipt) {
            $receipt = TaskLandingData::object($receipt);
            TaskLandingData::text($receipt, 'summary');
            TaskLandingData::text($receipt, 'evidence');
        }
        HerdrTaskLandingReviewer::assertIdentity($origin->session, $successor->session);
        if (($origin->session['agentName'] ?? null) !== $old->worker_ref || ($origin->session['workingDirectory'] ?? null) !== $workspace->worktree) {
            throw new LogicException('The reattempt worker identity does not match its task and worktree.');
        }
    }

    /** @param array<string, string> $observation
     * @return array<string, string>
     */
    private function observation(array $observation): array
    {
        if (array_keys($observation) !== ['head', 'branch', 'git_directory', 'common_directory', 'tree', 'index_tree', 'index_sha256']) {
            throw new LogicException('Retain the complete exact reattempt Git observation.');
        }
        foreach (['head', 'tree', 'index_tree'] as $key) {
            GitObjectId::validate($observation[$key]);
        }
        $this->input->pin($observation['index_sha256']);

        return $observation;
    }

    /** @return list<array<string, mixed>> */
    private function manifestHistory(TaskWorkspace $workspace): array
    {
        $continuations = $workspace->getConnection()->getSchemaBuilder()->hasTable('task_final_continuations')
            ? TaskFinalContinuation::query()->where('task_workspace_id', $workspace->id)->orderByDesc('final_round')->get() : collect();
        $manifest = $this->plans->manifest($workspace->root()->firstOrFail());
        $history = [];
        $round = $continuations->count();
        if ($workspace->dispatches()->orderByDesc('id')->first()?->round !== $round) {
            throw new LogicException('The final reattempt round is missing its continuation history.');
        }
        foreach ($continuations as $continuation) {
            if ($continuation->final_round !== $round-- || ($continuation->request['mode'] ?? null) !== $continuation->mode
                || $this->input->hash($manifest) !== $continuation->manifest_hash
                || $continuation->request_hash !== $this->input->hash([$workspace->id, $continuation->task_agent_dispatch_id,
                    $continuation->head, $continuation->previous_manifest_hash, $continuation->request])) {
                throw new LogicException('The post-reattempt manifest continuation chain changed.');
            }
            if ($continuation->mode === 'append_correction') {
                $tail = array_pop($manifest['children']);
                $brief = TaskLandingData::object($continuation->request['task'] ?? null);
                if ($tail === null || $tail['id'] !== $continuation->task_id
                    || $tail['title'] !== ($brief['title'] ?? null) || $tail['description'] !== ($brief['description'] ?? null)
                    || $tail['acceptance_criteria'] !== ($brief['acceptance_criteria'] ?? null)) {
                    throw new LogicException('The appended correction no longer matches its immutable continuation.');
                }
            } elseif ($continuation->mode !== 'retry_final_checks' || $continuation->task_id !== null) {
                throw new LogicException('Unknown reattempt manifest continuation.');
            }
            if ($this->input->hash($manifest) !== $continuation->previous_manifest_hash) {
                throw new LogicException('The original reattempt manifest is not preserved by its continuation.');
            }
            $history[] = ['id' => $continuation->id, 'head' => $continuation->head, 'previous_manifest' => $continuation->previous_manifest_hash,
                'manifest' => $continuation->manifest_hash, 'audit_hash' => $this->input->hash($continuation->toArray())];
        }
        if ($this->input->hash($manifest) !== $workspace->manifest_hash) {
            throw new LogicException('The admitted reattempt task manifest changed without an audited continuation.');
        }

        return array_reverse($history);
    }
}
