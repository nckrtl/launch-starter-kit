<?php

declare(strict_types=1);

namespace App\Tasks\Orbit\Proof;

use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\NativeTaskLandingRepository;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Runtime\TaskArtifactReviews;
use App\Tasks\Runtime\TaskMainIntegration;
use App\Tasks\Runtime\TaskManifestAmendmentHistory;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use LogicException;

final readonly class OrbitTaskProofEvidence
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph,
        private NativeTaskLandingRepository $repository, private TaskLandingEvidence $landingEvidence,
        private TaskMainIntegration $integrations, private TaskManifestAmendmentHistory $history,
        private TaskProofContract $contracts, private TaskArtifactReviews $artifacts) {}

    /** @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    public function inputs(TaskWorkspace $workspace, array $check): array
    {
        $profile = OrbitTaskProfile::forWorkspace($workspace);
        if ($profile !== ['schema' => 1, 'flow' => 'proof', 'snapshot_replacement' => true]) {
            throw new LogicException('This proof bridge requires the immutable Orbit replacement-proof profile.');
        }
        $candidate = $check['sha'] ?? null;
        if (! is_string($candidate) || preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1
            || ($check['candidate_unchanged'] ?? null) !== true || ($check['exit_code'] ?? null) !== 0
            || ($check['manifest_hash'] ?? null) !== $this->plans->effectiveHash($workspace)) {
            throw new LogicException('Proof publication requires the exact successful unchanged Builder check.');
        }
        $root = $workspace->root()->firstOrFail();
        if ($root->status !== TaskStatus::Pending || $workspace->final_check !== null || $workspace->final_result !== null) {
            throw new LogicException('Proof publication must precede final review and completion.');
        }
        $final = $workspace->dispatches()->orderByDesc('id')->first();
        if ($final === null || $final->kind !== 'final_review' || $final->state !== 'sending' || $final->final_check !== null) {
            throw new LogicException('Proof publication belongs to the current unsent final review.');
        }

        $accepted = [];
        $base = $workspace->base_sha;
        foreach ($this->graph->orderedChildren($root) as $child) {
            $run = $child->acceptedRun()->first();
            $review = $run?->reviews()->orderByDesc('round')->first();
            $artifact = $run !== null && $review !== null ? $this->artifacts->accepted($workspace, $run, $review) : null;
            if ($child->status !== TaskStatus::Completed || $run === null || $run->status !== TaskRunStatus::Completed
                || $run->task_id !== $child->id || $run->root_task_id !== $root->id
                || $run->reviewer_ref !== ($workspace->reviewer_session['agentName'] ?? null) || $run->worker_ref === $run->reviewer_ref
                || $run->base_sha !== $base || ($run->commit_sha === null && $artifact === null) || $review === null
                || $review->verdict !== TaskReviewVerdict::Pass || $review->tree_sha === null) {
                throw new LogicException('Every ordered proof task requires its exact accepted commit and review.');
            }
            $accepted[] = ['task_id' => $child->id, 'run_id' => $run->id, 'base_sha' => $run->base_sha,
                ...(($integration = $this->integrations->binding($run)) === null ? [] : ['main_integration' => $integration]),
                'commit_sha' => $run->commit_sha, 'tree_sha' => $review->tree_sha,
                ...($artifact === null ? [] : ['artifact_result' => $artifact]),
                'handoff' => $this->handoff($run->output),
                'review' => ['id' => $review->id, 'round' => $review->round, 'verdict' => 'pass',
                    'summary' => $review->summary, 'evidence' => $review->evidence_ref]];
            $base = $run->commit_sha ?? $base;
        }
        $last = TaskRun::query()->where('root_task_id', $root->id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
        if ($accepted === [] || $base !== $candidate || $last?->commit_sha !== $candidate) {
            throw new LogicException('The proof candidate must be the exact last accepted task commit.');
        }

        $manifest = $this->plans->manifest($root);
        $amendments = $this->history->ledger($workspace, $manifest);
        $continuations = $this->history->continuations($workspace);
        $contract = $this->contracts->read($workspace);
        $this->artifacts->assertPublication($workspace, $contract);
        $inputs = ['schema' => 2,
            'authority' => 'Commander Tasks; immutable pre-proof evidence export, not executable task state.',
            'database' => ['workspace_id' => $workspace->id, 'project_id' => 'orbit', 'source_key' => $workspace->source_key,
                'manifest' => $manifest, 'accepted_tasks' => $accepted,
                ...($amendments === [] || $continuations === [] ? [] : ['manifest_amendments' => $amendments]),
                ...($continuations === [] ? [] : ['final_continuations' => $continuations]),
                'builder_gate' => $this->check($check), 'final_dispatch_id' => $final->id],
            'repository' => $this->repository->inspectProofCandidate($workspace, $candidate),
            'proof_contract' => $contract,
            'retention' => 'Final verdict, native proof results, capture, and interactive review are intentionally outside this immutable artifact.'];
        if (strlen(TaskLandingData::json($inputs)) > 8_388_608) {
            throw new LogicException('The pre-proof evidence package is too large.');
        }
        $this->landingEvidence->assertNoSecrets($workspace, $inputs);

        return $inputs;
    }

    /** @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    private function check(array $check): array
    {
        foreach (['output', 'error_output'] as $key) {
            $value = $check[$key] ?? null;
            if (! is_string($value) || strlen($value) > 1_048_576 || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new LogicException('Builder check output must be bounded UTF-8 without NUL bytes.');
            }
        }

        return $check;
    }

    /** @param array<string, mixed>|null $value
     * @return array{summary:string,evidence:string}
     */
    private function handoff(?array $value): array
    {
        return ['summary' => TaskLandingData::text($value ?? [], 'summary'),
            'evidence' => TaskLandingData::text($value ?? [], 'evidence')];
    }
}
