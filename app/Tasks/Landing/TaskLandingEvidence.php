<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\Delivery;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskRunStatus;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\ReadTaskOrbitIssue;
use App\Tasks\Runtime\TaskArtifactReviews;
use App\Tasks\Runtime\TaskMainIntegration;
use App\Tasks\Runtime\TaskManifestAmendmentHistory;
use App\Tasks\Runtime\TaskRuntimePlan;
use App\Tasks\TaskGraph;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final readonly class TaskLandingEvidence
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph,
        private ReadTaskOrbitIssue $issues, private TaskLandingRepository $repository, private TaskLandingReattempt $reattempts,
        private TaskManifestAmendmentHistory $amendments, private TaskMainIntegration $integrations,
        private TaskArtifactReviews $artifacts, private TaskEvidenceSafety $safety) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function capture(TaskWorkspace $workspace, array $request): array
    {
        $database = $this->database($workspace, $request);
        $issueId = TaskLandingData::text($request, 'issue_id');
        $issue = $this->issues->read($issueId, $workspace->source_key);
        $team = TaskLandingData::object($issue->payload['team'] ?? null);
        $expectedTeam = config('commander.hermes.orbit_linear_team_id');
        if ($issue->issueId !== $issueId || $issue->issueKey !== $workspace->source_key
            || ($issue->payload['id'] ?? null) !== $issueId || ($issue->payload['identifier'] ?? null) !== $workspace->source_key
            || ! is_string($expectedTeam) || $expectedTeam === '' || ($team['id'] ?? null) !== $expectedTeam
            || preg_match('/\A[a-f0-9]{64}\z/', $issue->contractHash) !== 1) {
            throw new LogicException('The Linear issue no longer matches this Orbit workspace.');
        }
        $profile = OrbitTaskProfile::forWorkspace($workspace);
        $proof = null;
        if (($profile['flow'] ?? null) === 'proof') {
            $finalCheck = $workspace->final_check;
            $proof = is_array($finalCheck['native_proof'] ?? null) ? $finalCheck['native_proof'] : null;
            $artifactInputs = is_array($proof['artifact_inputs'] ?? null) ? $proof['artifact_inputs'] : null;
            $artifact = is_array($proof['artifact'] ?? null) ? $proof['artifact'] : null;
            if ($artifactInputs === null || $artifact === null || ($artifactInputs['schema'] ?? null) !== 2
                || TaskLandingData::hash($artifactInputs) !== ($artifact['input_sha256'] ?? null)
                || $this->repository->artifact($workspace, TaskLandingData::text($request, 'candidate'), $artifactInputs) !== ($artifact['sha'] ?? null)) {
                throw new LogicException('The immutable pre-proof artifact is missing or changed.');
            }
            $repository = TaskLandingData::object($artifactInputs['repository'] ?? null);
        } else {
            $repository = $this->repository->inspect($workspace, TaskLandingData::text($request, 'candidate'), TaskLandingData::text($request, 'gate_receipt'));
        }
        $reattemptProof = $this->reattempts->proof($workspace, $database);
        $attachments = [];
        $files = $request['evidence_files'] ?? [];
        if (! is_array($files)) {
            throw new LogicException('Missing retained evidence list.');
        }
        foreach ($files as $file) {
            $file = TaskLandingData::object($file);
            $contents = TaskLandingData::file(TaskLandingData::text($file, 'path'));
            if (! hash_equals(TaskLandingData::text($file, 'sha256'), hash('sha256', $contents))) {
                throw new LogicException('A retained evidence file changed.');
            }
            $attachments[] = [...$file, 'contents' => $contents];
        }
        $inputs = ['schema' => 1, 'authority' => 'Commander Tasks; this is an immutable evidence export, not executable task state.',
            'database' => $database, 'issue' => ['id' => $issueId, 'identifier' => $workspace->source_key,
                'team_id' => $expectedTeam, 'contract_hash' => $issue->contractHash,
                'title' => TaskLandingData::text($issue->payload, 'title', 255),
                'description' => TaskLandingData::text($issue->payload, 'description'),
                ...$this->classification($issue->payload)],
            'repository' => $repository, 'evidence_files' => $attachments,
            'retention' => 'Only the explicit evidence_files are embedded. Other paths in handoff prose are references, not archived proof. No cleanup is authorized.',
            ...($reattemptProof === null ? [] : ['reattempt_proof' => $reattemptProof])];
        if ($proof !== null) {
            $review = $workspace->final_result['native_proof_review'] ?? null;
            if (! is_array($review)) {
                throw new LogicException('The final proof verdict has no frozen native review evidence.');
            }
            $inputs = ['schema' => 2,
                'authority' => 'Commander Tasks; schema-2 landing evidence adopts the immutable pre-proof artifact without rewriting it.',
                'database' => $database, 'issue' => $inputs['issue'], 'repository' => $repository,
                'pre_proof_artifact' => TaskLandingData::object($proof['artifact']),
                'artifact_inputs' => TaskLandingData::object($proof['artifact_inputs']),
                'native_proof' => ['attempt_id' => $proof['attempt_id'] ?? null,
                    'capture_fingerprint' => $proof['capture_fingerprint'] ?? null,
                    'retained_topology' => $proof['retained_topology'] ?? null,
                    'prove' => $proof['prove'] ?? null, 'capture' => $proof['capture'] ?? null,
                    'final_review' => $review],
                'evidence_files' => $attachments,
                'retention' => 'The artifact_inputs are the unchanged artifact bytes. Final verdict and native proof-review archives remain Commander landing evidence outside that artifact.',
                ...($reattemptProof === null ? [] : ['reattempt_proof' => $reattemptProof])];
        }
        $this->assertNoSecrets($workspace, $inputs);
        $this->assertNoSecrets($workspace, TaskLandingData::text($request, 'pull_request_body', 50_000));
        $this->guard($workspace, $request, $inputs);

        return $inputs;
    }

    /** @param array<string, mixed> $request
     * @param  array<string, mixed>  $inputs
     */
    public function guard(TaskWorkspace $workspace, array $request, array $inputs): void
    {
        $current = $workspace->fresh() ?? throw new LogicException('Missing workspace.');
        $database = $this->database($current, $request);
        if ($database !== ($inputs['database'] ?? null)) {
            throw new LogicException('Task acceptance, ownership, configuration or final evidence changed during observation.');
        }
        if ($this->reattempts->proof($current, $database) !== ($inputs['reattempt_proof'] ?? null)) {
            throw new LogicException('The retained reattempt Git provenance changed during observation.');
        }
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function database(TaskWorkspace $workspace, array $request): array
    {
        $root = $workspace->root()->firstOrFail();
        $candidate = TaskLandingData::text($request, 'candidate');
        $configuration = $workspace->configuration;
        $allowed = $configuration['worktree_root'] ?? null;
        if ($workspace->project_id !== 'orbit' || $root->project_id !== 'orbit'
            || preg_match('/\AORB-[1-9][0-9]*\z/', $workspace->source_key) !== 1
            || $root->status !== TaskStatus::Completed || $root->completed_at === null
            || $workspace->attention !== null || ($configuration['flow_version'] ?? null) !== 1
            || ($configuration['repository'] ?? null) !== $workspace->repository
            || ! is_string($allowed) || $allowed === '/' || ! str_starts_with($workspace->worktree, $allowed.'/')
            || $workspace->reviewer_session === null || $workspace->herdr_workspace === null
            || $this->plans->hash($root) !== ($request['manifest'] ?? null)
            || $this->plans->effectiveHash($workspace) !== $request['manifest']
            || TaskRun::query()->where('active_root_task_id', $root->id)->exists()) {
            throw new LogicException('Landing requires an exclusively owned, completed Orbit workspace and its exact accepted manifest.');
        }
        if (Delivery::query()->active()->where(function (Builder $query) use ($workspace): void {
            $query->where('worktree_path', $workspace->worktree)->orWhere('external_issue_key', $workspace->source_key);
        })->exists() || TaskWorkspace::query()->whereKeyNot($workspace->id)->where(function (Builder $query) use ($workspace): void {
            $query->where('worktree', $workspace->worktree)->orWhere('source_key', $workspace->source_key);
        })->exists()) {
            throw new LogicException('Another controller owns this source or worktree.');
        }
        $final = $workspace->dispatches()->orderByDesc('id')->first();
        $check = $final?->final_check_version === 1 ? $final->final_check : $workspace->final_check;
        $finalResult = $workspace->final_result;
        $proofProfile = (OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) === 'proof';
        $finalReceipt = $proofProfile && is_array($finalResult) ? array_diff_key($finalResult, ['native_proof_review' => true]) : $finalResult;
        if ($final === null || $final->id !== ($request['final_dispatch'] ?? null) || $final->kind !== 'final_review'
            || $final->state !== 'acknowledged' || ($final->result['verdict'] ?? null) !== 'pass'
            || $finalReceipt !== $final->result || ($check['sha'] ?? null) !== $candidate
            || ($proofProfile && $workspace->final_check !== $check)
            || ($check['exit_code'] ?? null) !== 0 || ($final->final_check_version === 1 && ($check['candidate_unchanged'] ?? null) !== true)) {
            throw new LogicException('Retain the exact successful final review; it is eligibility, not package approval.');
        }
        HerdrTaskLandingReviewer::assertIdentity($final->session ?? [], $workspace->reviewer_session ?? []);
        $accepted = [];
        $children = $this->graph->orderedChildren($root);
        $reattempt = $this->reattempts->ledger($workspace, $children[0] ?? null);
        $base = $reattempt === null ? $workspace->base_sha : TaskLandingData::text($reattempt, 'base_sha');
        foreach ($children as $child) {
            $run = $child->acceptedRun()->first();
            $review = $run?->reviews()->orderByDesc('round')->first();
            $artifact = $run !== null && $review !== null ? $this->artifacts->accepted($workspace, $run, $review) : null;
            if ($child->status !== TaskStatus::Completed || $run === null || $run->task_id !== $child->id
                || $run->root_task_id !== $root->id || $run->status !== TaskRunStatus::Completed
                || $run->reviewer_ref !== ($workspace->reviewer_session['agentName'] ?? null) || $run->worker_ref === $run->reviewer_ref
                || $run->base_sha !== $base || ($run->commit_sha === null && $artifact === null) || $review === null
                || $review->verdict !== TaskReviewVerdict::Pass || $review->tree_sha === null) {
                throw new LogicException('Every ordered task requires its exact accepted commit and passing review.');
            }
            if ($reattempt !== null) {
                $this->reattempts->accepted($run, $review);
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
            throw new LogicException('The candidate must be the clean last accepted task commit, without extra commits.');
        }

        $manifest = $this->plans->manifest($root);
        $amendments = $this->amendments->ledger($workspace, $manifest);
        $continuations = $this->amendments->continuations($workspace);

        return ['workspace_id' => $workspace->id, 'project_id' => $workspace->project_id, 'source_key' => $workspace->source_key,
            'repository' => $workspace->repository, 'worktree' => $workspace->worktree,
            'manifest' => $manifest, ...($amendments === [] ? [] : ['manifest_amendments' => $amendments]),
            ...($continuations === [] ? [] : ['final_continuations' => $continuations]), 'accepted_tasks' => $accepted,
            'final' => ['dispatch_id' => $final->id, 'candidate' => $candidate, 'verdict' => 'pass', 'handoff' => $this->handoff($final->result)],
            'ownership_hash' => TaskLandingData::hash([$configuration, $workspace->herdr_workspace, $workspace->reviewer_session,
                $final->toArray(), $workspace->final_check, $root->completed_at->toISOString(),
                ...($proofProfile ? [$workspace->final_result] : [])]),
            ...($reattempt === null ? [] : ['reattempt' => $reattempt])];
    }

    /** @param array<string, mixed>|null $output
     * @return array{summary:string,evidence:string}
     */
    private function handoff(?array $output): array
    {
        return ['summary' => TaskLandingData::text($output ?? [], 'summary'), 'evidence' => TaskLandingData::text($output ?? [], 'evidence')];
    }

    /** @param array<string, mixed> $payload
     * @return array{labels:list<string>,attachments:list<array{title:string,repository_url:?string}>}
     */
    private function classification(array $payload): array
    {
        $collections = [];
        foreach (['labels', 'attachments'] as $key) {
            $nodes = TaskLandingData::object($payload[$key] ?? null)['nodes'] ?? null;
            if (! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > 100) {
                throw new LogicException('The current Linear classification or attachment list is incomplete.');
            }
            $collections[$key] = $nodes;
        }
        $labels = [];
        foreach ($collections['labels'] as $label) {
            $labels[] = TaskLandingData::text(TaskLandingData::object($label), 'name', 100);
        }
        sort($labels, SORT_STRING);
        $attachments = [];
        foreach ($collections['attachments'] as $attachment) {
            $attachment = TaskLandingData::object($attachment);
            $url = TaskLandingData::text($attachment, 'url', 5000);
            $attachments[] = ['title' => TaskLandingData::text($attachment, 'title', 500),
                'repository_url' => preg_match('#\Ahttps://github\.com/nckrtl/orbit/(?:(?:pull|issues)/[1-9][0-9]*|commit/[a-f0-9]{40})\z#', $url) === 1 ? $url : null];
        }

        return ['labels' => $labels, 'attachments' => $attachments];
    }

    public function assertNoSecrets(TaskWorkspace $workspace, mixed $value): void
    {
        $this->safety->assertNoSecrets($workspace, $value);
    }
}
