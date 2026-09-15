<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;
use App\Tasks\Runtime\TaskAgents;
use App\Tasks\Runtime\TaskRuntimeLock;
use App\Tasks\TaskPayload;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class ReviewTaskLanding
{
    public function __construct(private TaskRuntimeLock $lock, private TaskLandingEvidence $evidence,
        private TaskLandingRepository $repository, private TaskLandingReviewer $reviewer, private TaskAgents $agents, private TaskPayload $payloads,
        private TaskLandingHistory $history, private TaskProofReviewFiles $proofFiles) {}

    /** @return array<string, mixed> */
    public function dispatch(int $id, string $packageHash, bool $exclusive, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Confirm exclusive ownership and enable Tasks before dispatching a supplemental review.');
        }
        $landing = TaskLanding::query()->findOrFail($id);
        $this->assertPackage($landing, $packageHash);
        if ($landing->review_assignment !== null) {
            return ['applied' => false, 'landing' => $landing->toArray()];
        }
        if ($landing->state !== 'packaged') {
            throw new LogicException('Freeze and verify the complete package before asking for its review.');
        }
        $workspace = $this->observe($landing);
        $session = $this->reviewer->observe($workspace, $workspace->reviewer_session ?? throw new LogicException('Missing retained reviewer.'), true);
        HerdrTaskLandingReviewer::assertIdentity($workspace->reviewer_session ?? [], $session);
        $assignment = (string) Str::uuid();
        $token = Str::random(64);
        $prompt = $this->referencePrompt($landing, $session, $assignment, $token);
        $transport = TaskLandingReviewTransport::inspect($session, $prompt);
        if (! $apply) {
            return ['applied' => false, 'landing' => $landing->toArray(), 'reviewer' => $session, 'transport' => $transport];
        }
        $claimed = $this->lock->handle($workspace->id, function () use ($workspace, $landing, $packageHash, $session, $assignment, $token, $prompt): bool {
            $this->evidence->guard($workspace, $landing->request, $landing->inputs);
            $landing->refresh();
            $this->assertPackage($landing, $packageHash);
            if ($landing->review_assignment !== null) {
                return false;
            }
            if ($landing->state !== 'packaged') {
                throw new LogicException('Package review ownership changed.');
            }
            TaskLandingReviewTransport::inspect($session, $prompt);
            if (($landing->inputs['schema'] ?? null) === 2) {
                foreach ($this->proofReviewEvidence($landing) as $value) {
                    $this->proofFiles->retain($workspace, $value);
                }
            }
            $landing->update(['review_assignment' => $assignment, 'review_session' => $session,
                'review_token' => $token, 'review_token_hash' => hash('sha256', $token),
                'review_prompt' => $prompt, 'state' => 'prompting']);

            return true;
        });
        if (! $claimed) {
            return ['applied' => false, 'landing' => $landing->fresh()?->toArray()];
        }
        try {
            $observed = $this->agents->prompt($workspace, $session, $landing->review_prompt ?? throw new LogicException('Missing review prompt.'));
            HerdrTaskLandingReviewer::assertIdentity($session, $observed);
            $this->lock->handle($workspace->id, function () use ($workspace, $landing): void {
                $this->evidence->guard($workspace, $landing->request, $landing->inputs);
                $landing->refresh();
                if ($landing->state === 'prompting') {
                    $landing->update(['state' => 'sent']);
                }
            });
        } catch (Throwable $exception) {
            $this->lock->handle($workspace->id, function () use ($landing): void {
                $landing->refresh();
                if ($landing->review_result === null && $landing->state === 'prompting') {
                    $landing->update(['state' => 'review_unknown', 'error' => 'Supplemental prompt delivery is uncertain. Never resend or replace the retained reviewer automatically.']);
                }
            });
            throw $exception;
        }

        return ['applied' => true, 'landing' => $landing->fresh()?->toArray()];
    }

    /** @param array<string, mixed> $receipt */
    public function submit(int $id, array $receipt): TaskLanding
    {
        $landing = TaskLanding::query()->findOrFail($id);
        $this->assertPackage($landing, TaskLandingData::text($receipt, 'package_hash'));
        $token = TaskLandingData::text($receipt, 'token');
        if ($landing->review_token_hash === null || ! hash_equals($landing->review_token_hash, hash('sha256', $token))
            || $landing->review_assignment === null || ($receipt['assignment'] ?? null) !== $landing->review_assignment
            || ($receipt['candidate_sha'] ?? null) !== $landing->candidate_sha || ($receipt['artifact_sha'] ?? null) !== $landing->artifact_sha
            || ! $this->payloads->matches(TaskLandingData::object($receipt['session'] ?? null), $landing->review_session ?? [])
            || ! in_array($receipt['verdict'] ?? null, ['pass', 'revise', 'blocked'], true)
            || array_diff(array_keys($receipt), ['token', 'assignment', 'package_hash', 'candidate_sha', 'artifact_sha', 'session', 'verdict', 'summary', 'evidence']) !== []) {
            throw new LogicException('The supplemental review does not match its secret current assignment and exact package.');
        }
        TaskLandingData::text($receipt, 'summary');
        TaskLandingData::text($receipt, 'evidence');
        unset($receipt['token']);
        if (str_contains(TaskLandingData::json($receipt), $token)) {
            throw new LogicException('Do not include the private review token in evidence.');
        }
        if ($landing->review_result !== null) {
            if (! $this->payloads->matches($landing->review_result, $receipt)) {
                throw new LogicException('The recorded supplemental verdict cannot be replaced.');
            }

            return $landing;
        }
        if (! in_array($landing->state, ['prompting', 'sent', 'review_unknown'], true)) {
            throw new LogicException('Only the current dispatched supplemental review can submit.');
        }
        $workspace = $this->observe($landing);
        $session = $landing->review_session ?? throw new LogicException('Missing supplemental reviewer identity.');
        $observed = $this->reviewer->observe($workspace, $session, false);
        HerdrTaskLandingReviewer::assertIdentity($session, $observed);
        $this->evidence->assertNoSecrets($workspace, $receipt);

        return $this->lock->handle($workspace->id, function () use ($workspace, $landing, $receipt): TaskLanding {
            $this->evidence->guard($workspace, $landing->request, $landing->inputs);
            $landing->refresh();
            $this->assertPackage($landing, TaskLandingData::text($receipt, 'package_hash'));
            if ($landing->review_result !== null) {
                if (! $this->payloads->matches($landing->review_result, $receipt)) {
                    throw new LogicException('A conflicting supplemental verdict was recorded.');
                }

                return $landing;
            }
            if (! in_array($landing->state, ['prompting', 'sent', 'review_unknown'], true)
                || $landing->review_assignment !== $receipt['assignment']
                || ! $this->payloads->matches($landing->review_session ?? [], TaskLandingData::object($receipt['session']))) {
                throw new LogicException('Supplemental review ownership changed during verification.');
            }
            $landing->update(['review_result' => $receipt, 'state' => $receipt['verdict'] === 'pass' ? 'approved' : 'rejected', 'error' => null]);

            return $landing;
        });
    }

    public function observe(TaskLanding $landing): TaskWorkspace
    {
        $workspace = $landing->workspace()->firstOrFail();
        if ($this->evidence->capture($workspace, $landing->request) !== $landing->inputs
            || $this->repository->artifact($workspace, $landing->candidate_sha, $landing->inputs) !== $landing->artifact_sha) {
            throw new LogicException('The candidate, artifact, ownership or evidence changed after the package was frozen.');
        }

        return $workspace;
    }

    public function assertPackage(TaskLanding $landing, string $hash): void
    {
        $this->history->assert($landing);
        if ($landing->package_hash === null || $landing->artifact_sha === null || $landing->package === null
            || ! hash_equals($landing->package_hash, $hash) || TaskLandingData::hash($landing->package) !== $hash
            || TaskLandingData::hash($landing->inputs) !== $landing->input_hash
            || ($landing->package['candidate_sha'] ?? null) !== $landing->candidate_sha
            || ($landing->package['artifact_sha'] ?? null) !== $landing->artifact_sha
            || ($landing->package['artifact_ref'] ?? null) !== $landing->artifact_ref
            || ($landing->package['input_hash'] ?? null) !== $landing->input_hash) {
            throw new LogicException('Pin the complete immutable landing package before reviewing it.');
        }
        if (($landing->inputs['schema'] ?? null) === 2
            && (($landing->package['schema'] ?? null) !== 2
                || ($landing->package['native_proof'] ?? null) !== ($landing->inputs['native_proof'] ?? null)
                || ($landing->package['proof_evidence_hash'] ?? null) !== TaskLandingData::hash($landing->inputs['native_proof'] ?? null))) {
            throw new LogicException('Pin the complete schema-2 proof evidence before reviewing it.');
        }
        if (($landing->inputs['schema'] ?? null) === 2 && $landing->review_assignment !== null) {
            foreach ($this->proofReviewEvidence($landing) as $value) {
                $this->proofFiles->verify($landing->task_workspace_id, $value);
            }
        }
    }

    /** @return array{package:array<string, mixed>,landing_evidence:array<string, mixed>} */
    private function proofReviewEvidence(TaskLanding $landing): array
    {
        return ['package' => TaskLandingData::object($landing->package),
            'landing_evidence' => array_diff_key($landing->inputs, ['artifact_inputs' => true, 'native_proof' => true])];
    }

    /** @param array<string, mixed> $session */
    public function referencePrompt(TaskLanding $landing, array $session, string $assignment, string $token): string
    {
        $amendment = $this->history->reviewContext($landing);
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan')).' tasks:landing-submit '.$landing->id.' --file=/absolute/path/outside-worktree/review.json';
        $artifactCommand = 'git --no-replace-objects show '.$landing->artifact_sha.':.loop/commander-tasks.json';
        $isProof = ($landing->inputs['schema'] ?? null) === 2;
        $artifactInputs = $isProof ? TaskLandingData::object($landing->inputs['artifact_inputs'] ?? null) : $landing->inputs;
        $receipt = ['assignment' => $assignment, 'token' => $token, 'package_hash' => $landing->package_hash,
            'candidate_sha' => $landing->candidate_sha, 'artifact_sha' => $landing->artifact_sha, 'session' => $session,
            'verdict' => 'pass or revise or blocked', 'summary' => 'Exact package review outcome',
            'evidence' => 'Checks of candidate, native artifact inputs, retained proof, acceptance criteria, and complete PR body; list missing evidence.'];
        $proofInstructions = $isProof
            ? "\nThis is a schema-2 proof package. Review every normalized prove, capture, retained-topology, interactive action/evaluation, and exact native primary archive byte/hash binding carried by the package. Confirm snapshot_replacement is true and the review is ready with nonempty actions. The artifact intentionally predates these records and must remain unchanged. Do not run native prove, capture, shell, exec, review, release, closeout, or snapshot operations during this supplemental package review.\n"
            : '';

        return "Perform an independent supplemental review of this exact Orbit landing package. Your previous final pass is not package approval. Do not change code, tasks, commits, artifacts, PR body, or any other state. Do not merge, publish a PR, or clean up.\n\n"
            .$proofInstructions
            ."Check the candidate and published artifact against the frozen input hash and all accepted task and root criteria. Always inspect the complete current PR title/body and judge root acceptance, integration and cross-task interactions. Confirm the actual successful Builder receipt and final-check record belong to the exact unchanged candidate. You may cite your own final-review assessment of those exact records when the native package binding is unchanged; do not reread or restate their unchanged logs solely for this package review. Compare the complete explicit current Linear title, description, label classifications and attachment titles/safe repository links against the accepted root and tasks, even when reusing an assessment: legacy admission did not retain an original Linear contract hash. A fresh hash alone does not prove coverage. A null attachment repository_url means its URL was deliberately withheld, not that its requirements are satisfied. New, removed, changed or unresolved requirements/classifications must return blocked for coordinator reconciliation, never acquire acceptance from the original final pass. Confirm required proof is embedded or available at a stable verified reference; arbitrary temporary paths are not retained proof. Return revise or blocked for missing, conflicting, or insufficient evidence. Commander Tasks remains the sole task authority; .loop/commander-tasks.json is an immutable evidence export.\n\n"
            .($isProof ? "Read the complete unchanged package, including its current PR title/body and all native proof records, at this Commander-retained private file. Verify its raw SHA256 and byte count before reviewing; missing or mismatched evidence requires blocked. Package file:\n"
                .TaskLandingData::json($this->proofFiles->reference($landing->task_workspace_id, $this->proofReviewEvidence($landing)['package']))
                : "Package:\n".TaskLandingData::json($landing->package))."\nPackage SHA256: ".$landing->package_hash
            .($isProof ? "\n\nFrozen landing evidence outside the pre-proof artifact (native proof is in the separate package file above). Read this complete Commander-retained private file and verify its raw SHA256 and byte count; it includes the later accepted database and issue evidence, not a replacement artifact:\n"
                .TaskLandingData::json($this->proofFiles->reference($landing->task_workspace_id, $this->proofReviewEvidence($landing)['landing_evidence'])) : '')
            .($amendment === null ? '' : "\n\nBody-only amendment audit:\n".TaskLandingData::json($amendment)
                ."Read the original rejected package and complete verdict at predecessor_id with read-only access. Verify the pinned hashes and amendment audit. Always inspect and reconcile the complete retained correction evidence against the complete current PR body. This audit supplements, and does not replace or alter, the artifact inputs. The previous verdict remains rejected. Apply the same reuse limits to unchanged artifact content; independently judge this entire new package.\n")
            ."\n\nFrozen inputs are retained in the immutable published artifact, not repeated in this prompt:\n"
            .TaskLandingData::json(['artifact_ref' => $landing->artifact_ref, 'artifact_sha' => $landing->artifact_sha,
                'sole_parent_candidate_sha' => $landing->candidate_sha, 'path' => '.loop/commander-tasks.json',
                'raw_bytes' => strlen(TaskLandingData::json($artifactInputs)), 'raw_sha256' => $isProof ? TaskLandingData::hash($artifactInputs) : $landing->input_hash])
            .($isProof
                ? "Commander has already verified that the exact ref resolves to this artifact SHA, its sole parent is the candidate, it changes no product bytes, and its raw export exactly equals the frozen pre-proof artifact inputs and raw_sha256 above. The later landing input hash separately binds the artifact inputs, current accepted database and issue evidence, and native proof-review evidence; it is not the raw artifact hash. Commander also verified the accepted commit/review chain, successful final review and check, and retained reviewer identity before this assignment. Use those as binding guarantees, not as correctness or proof-adequacy judgments. Read the exact immutable export directly, without changing the checkout:\n`{$artifactCommand}`\n"
                : "Commander has already verified that the exact ref resolves to this artifact SHA, its sole parent is the candidate, it changes no product bytes, and its raw export exactly equals the frozen inputs and input hash. Commander also verified the accepted commit/review chain, successful final review and check, and retained reviewer identity before this assignment. Use those as binding guarantees, not as correctness or proof-adequacy judgments. Read the exact immutable export directly, without changing the checkout:\n`{$artifactCommand}`\n")
            ."Account for every input section and every embedded evidence file, including failed and successful check history. You may reuse only your own recorded independent assessment when the export names the same reviewer, task/run, and source tree. Cite the prior review ID/tree or final dispatch once; do not inspect Commander source or its database solely to reconstruct that binding, and do not manually hash, byte-count, copy, or restate unchanged handoffs, reviews, or final evidence. Another agent's summary or approval is not your assessment. Inspect all new, changed, unresolved or inadequately recorded content completely in bounded reads. If your own prior assessment is unavailable or its native binding does not match, perform the full relevant independent review. Submit a fresh verdict bound to this exact package and assignment; prior approval never transfers. Do not truncate, omit, re-encode, rewrite, republish, or replace evidence, and do not create a source commit. A temporary path alone is not retained proof. Missing or mismatched evidence requires revise or blocked.\n"
            ."\n\nCreate the handoff privately from the start: use a fresh mktemp -d directory outside the checkout (mode 0700), and create the empty JSON file with mode 0600 under umask 077. Verify both modes before writing the token; do not write it first and chmod afterward. Quote the generated absolute file path in --file."
            ."\nFrom the exact assigned worktree, write this handoff JSON to that private file and invoke:\n".$command."\n".TaskLandingData::json($receipt)
            ."\nThe token is private: do not print, commit, or include it in evidence. Report your review verdict separately from submission acceptance. Claim it was recorded only when the native command confirms that result. On failure or an uncertain result, report 'submission not confirmed', the exit code if known, and the safe error; retain the unchanged private handoff and give only its path and SHA-256. Do not retry or resend automatically, replace the assignment, or repair the provider. Herdr idle/done is not acceptance. Stop and await Commander. This assignment authorizes only the supplemental review.";
    }
}
