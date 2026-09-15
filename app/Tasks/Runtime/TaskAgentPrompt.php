<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Task;
use App\Models\TaskAgentDispatch;
use App\Models\TaskFinalContinuation;
use App\Models\TaskManifestAmendment;
use App\Models\TaskReattempt;
use App\Models\TaskReattemptCheckpoint;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;

final readonly class TaskAgentPrompt
{
    public function __construct(private TaskProofReviewFiles $proofFiles, private TaskMainIntegration $integrations, private TaskArtifactReviews $artifacts) {}

    public function render(TaskWorkspace $workspace, TaskAgentDispatch $dispatch, string $token, ?TaskRun $run): string
    {
        $orbitInstructions = OrbitTaskProfile::instructions($workspace);
        $profile = OrbitTaskProfile::forWorkspace($workspace);
        $root = $workspace->root()->firstOrFail();
        $task = $run?->task()->firstOrFail();
        $integration = $run === null ? null : $this->integrations->binding($run);
        $instruction = match ($dispatch->kind) {
            'implement' => 'Implement only the assigned task. Do not commit. Include focused tests, documentation, and required machine verification. Submit a handoff when ready for review.',
            'review' => 'Independently review this exact uncommitted submission and its evidence. Do not modify the worktree. Return verdict pass or revise. A pass does not authorize committing until the separate commit instruction arrives.',
            'commit' => 'Your review passed. Create exactly one commit containing the reviewed tree, directly on the assigned base. Do not change implementation content. Submit after committing; Commander inspects the real Git objects and clean worktree. In the handoff, report only the commit SHA, reviewed tree SHA, assigned base as sole parent, and clean-worktree result. Cite the passed review ID and round from the assignment context once. Do not restate acceptance criteria, checks, or unchanged review evidence.',
            'final_review' => 'Review the complete feature against every root acceptance criterion and the evidence from all task handoffs. Account for every accepted task in a compact coverage list. You may reuse only your own recorded independent assessment when the assignment context identifies the same reviewer, task/run, and source tree. Cite the prior review ID and tree once; do not manually hash, byte-count, copy, or restate unchanged handoff and review evidence. Native identity and tree binding establishes which recorded assessment is yours and unchanged, not that its judgment was correct or its evidence adequate. Another agent\'s summary or approval is not your assessment. Inspect all new, changed, unresolved or inadequately recorded content completely. If your own prior assessment is unavailable or its native binding does not match, perform the full relevant independent review. Always judge integration, cross-task interactions, every root acceptance criterion and all changed or unresolved claims. Inspect the actual new coordinator final-check result completely. Do not change code or create commits. Submit a fresh verdict for this exact assignment and integrated tree; prior approval never transfers. Return pass only when the integrated feature is complete; otherwise return revise.',
            default => throw new \LogicException('Unknown task instruction.'),
        };
        if ($integration !== null) {
            $instruction = match ($dispatch->kind) {
                'implement' => 'Implement only the audited main integration. Merge exactly the pinned main SHA using git merge --no-commit --no-ff MAIN_SHA from the assigned base, or continue that exact unfinished merge after changes requested. Resolve integration conflicts and regressions; preserve accepted history and feature scope. Run affected focused checks. Leave exactly the pinned main in MERGE_HEAD, no unresolved index entries, and no commit. Submit the resulting tree for independent review.',
                'review' => 'Independently review the exact integration tree against BOTH ordered parents in main_integration.parents and all affected root criteria. Inspect conflict resolutions and cross-task regressions. Do not modify the worktree or commit. Return pass or revise for this exact tree and pinned parent pair; a separate commit instruction follows a pass.',
                'commit' => 'Your independent integration review passed. Create exactly one merge commit containing only the reviewed tree and ordered parents in main_integration.parents. Do not change content or merge another ref. Submit the commit SHA, reviewed tree SHA, both ordered parent SHAs, passed review ID/round and clean-worktree result. Commander verifies the real Git objects.',
                default => throw new \LogicException('An integration audit only authorizes its own task cycle.'),
            };
        }
        if ($dispatch->kind === 'final_review' && $dispatch->state !== 'prepared' && ($profile['flow'] ?? null) === 'proof') {
            $proof = $workspace->final_check['native_proof'] ?? null;
            if (! is_array($proof)) {
                throw new \LogicException('The proof final review requires frozen native proof evidence.');
            }
            $attempt = $proof['attempt_id'] ?? null;
            $capture = $proof['capture_fingerprint'] ?? null;
            $artifact = is_array($proof['artifact'] ?? null) ? $proof['artifact'] : [];
            if (! is_string($attempt) || ! is_string($capture) || ! is_string($artifact['sha'] ?? null)) {
                throw new \LogicException('The proof final review binding is incomplete.');
            }
            $instruction .= "\n\n".'Independently review the already-proved feature on the exact retained proof topology. The candidate, immutable pre-proof artifact, proof attempt, capture fingerprint, and topology are frozen in the assignment context. Do not edit code, commit, publish, rerun prove or capture, release topology, or perform closeout. For interactive proof review, use `bin/e2e-topology shell ISSUE NODE --proof --review-action=ID`, `bin/e2e-topology exec ISSUE NODE --argv=JSON --proof --review-action=ID`, and `bin/e2e-topology review ISSUE --complete=ID --result=passed|failed`; `bin/e2e-topology review ISSUE --json` persists the current review evaluation. Use the assigned worktree where required. Record at least one relevant action and leave the native review evaluation ready before returning pass. Return revise or blocked when proof is insufficient or any required action fails. This verdict covers premerge acceptance only; required postmerge clean installation and discovery/proof reacquisition, including C12 when named, remain pending. Your handoff verdict remains independent and does not authorize merge, release, or closeout.';
        }
        $latestReview = $run?->reviews()->orderByDesc('round')->first();
        $artifact = $run !== null && $latestReview !== null ? $this->artifacts->find($run, $latestReview->round) : null;
        if ($run !== null && $this->artifacts->active($run)) {
            $instruction = match ($dispatch->kind) {
                'implement' => 'Correct only the assigned artifact package in ignored .loop/proof files. Preserve the original tracked code tree and base. Do not commit or invent a tracked delta. Submit a genuine handoff, then stop; Commander holds for an explicit fresh artifact binding before the next independent review.',
                'review' => 'Independently review the exact artifact_review_binding, all its declared file bytes and the task acceptance criteria. Verify the real ignored files match that package and tracked code is unchanged. Do not edit files or commit. Return pass or revise. A passing handoff accepts this artifact result without a product commit; Commander will not send a commit instruction.',
                default => throw new \LogicException('Artifact results never authorize a commit instruction.'),
            };
        }
        $finalCheck = $workspace->final_check;
        if ($dispatch->kind !== 'commit' && ($profile['flow'] ?? null) === 'proof' && is_array($finalCheck['native_proof'] ?? null)) {
            $native = $finalCheck['native_proof'];
            $nativeArtifact = is_array($native['artifact'] ?? null) ? $native['artifact'] : [];
            $finalCheck = ['sha' => $finalCheck['sha'] ?? null,
                'native_proof' => ['attempt_id' => $native['attempt_id'] ?? null,
                    'capture_fingerprint' => $native['capture_fingerprint'] ?? null, 'artifact_sha' => $nativeArtifact['sha'] ?? null],
                'retained_evidence' => $this->proofFiles->reference($workspace->id, $finalCheck)];
            $instruction .= "\nRead the complete unchanged coordinator final-check record and every native proof record from final_check.retained_evidence before reviewing. This is a Commander-retained private file separate from the immutable pre-proof artifact. Verify its raw SHA256 and byte count; missing, changed, or unreadable evidence requires blocked. Do not modify either evidence source.";
        }
        $context = $dispatch->kind === 'commit' ? [
            'root_id' => $root->id,
            'task' => $task === null ? null : ['id' => $task->id, 'title' => $task->title],
            'run_id' => $run?->id,
            'base_sha' => $run?->base_sha,
            'round' => $dispatch->round,
            'passed_review' => $latestReview === null ? null : [
                'id' => $latestReview->id,
                'round' => $latestReview->round,
                'tree_sha' => $latestReview->tree_sha,
                'verdict' => $latestReview->verdict?->value,
                'reviewer_ref' => $run->reviewer_ref,
            ],
        ] : [
            'root' => $this->brief($root),
            'task' => $task === null ? null : $this->brief($task),
            'run_id' => $run?->id,
            'base_sha' => $run?->base_sha,
            'round' => $dispatch->round,
            'latest_review' => $latestReview?->toArray(),
            'accepted_tasks' => $root->children()->with('acceptedRun.reviews')->whereNotNull('accepted_task_run_id')->get()->toArray(),
            'final_check' => $finalCheck,
            'reattempt' => $workspace->getConnection()->getSchemaBuilder()->hasTable('task_reattempts')
                ? TaskReattempt::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->first()?->toArray() : null,
            'reattempt_checkpoint' => $workspace->getConnection()->getSchemaBuilder()->hasTable('task_reattempt_checkpoints')
                ? TaskReattemptCheckpoint::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->first()?->toArray() : null,
            'final_continuation' => $workspace->getConnection()->getSchemaBuilder()->hasTable('task_final_continuations')
                ? TaskFinalContinuation::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->orderByDesc('final_round')->first()?->toArray() : null,
            'manifest_amendments' => $workspace->getConnection()->getSchemaBuilder()->hasTable('task_manifest_amendments')
                ? TaskManifestAmendment::on($workspace->getConnectionName())->where('task_workspace_id', $workspace->id)->orderBy('sequence')->get()->toArray() : [],
        ];
        if ($integration !== null) {
            $context['main_integration'] = $integration;
            $instruction .= "\nThis exact audited local integration is the sole exception to any general 'Do not merge' instruction below. It never authorizes landing, publishing, deployment, changing a pinned parent, or rewriting accepted history.";
        }
        if ($artifact !== null) {
            $context['artifact_review_binding'] = ['id' => $artifact->id, 'binding_hash' => $artifact->binding_hash, 'binding' => $artifact->binding];
        }
        if ($dispatch->kind === 'final_review') {
            $context['accepted_artifact_results'] = $this->artifacts->acceptedBindings($workspace);
        }
        $projectInstructions = $workspace->configuration['instructions'] ?? '';
        $projectInstructions = is_string($projectInstructions) ? $projectInstructions : '';
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tasks:submit '.$dispatch->id.' --file=/absolute/path/outside-worktree/handoff.json';
        $receipt = ['token' => $token, 'summary' => 'What was implemented or reviewed', 'evidence' => 'Exact checks and results, limitations, and retained evidence references'];
        if (in_array($dispatch->kind, ['review', 'final_review'], true)) {
            $receipt['verdict'] = 'pass or revise';
        }

        return $instruction."\n\n".$projectInstructions
            .($orbitInstructions === '' ? '' : "\n\n".$orbitInstructions)
            ."\n\nWork only in ".$workspace->worktree.'. The structured task update is the handoff: after submitting it, stop all work and wait for a new Commander prompt. '
            .($integration === null ? 'Do not create native implementer subagents, commit early, merge, or launch the next task.' : 'Do not create native implementer subagents, commit early, merge any unassigned ref, or launch the next task.')
            .' Do not rely on Herdr idle/done to submit the result.'
            ."\n\nCreate the handoff privately from the start: use a fresh mktemp -d directory outside the checkout (mode 0700), and create the empty JSON file with mode 0600 under umask 077. Verify both modes before writing the token; do not write it first and chmod afterward. Quote the generated absolute file path in --file."
            ."\nFrom the exact worktree, save this JSON to that private file and invoke:\n".$command
            ."\n".json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            ."\nUse verdict blocked (for any role) if you cannot complete the instruction. Never invent passing evidence. Do not print or commit the handoff token. The command only records the handoff; Commander performs the next dispatch separately."
            ."\n\nAssignment context:\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function brief(Task $task): array
    {
        return ['id' => $task->id, 'title' => $task->title, 'description' => $task->description, 'acceptance_criteria' => $task->acceptance_criteria];
    }
}
