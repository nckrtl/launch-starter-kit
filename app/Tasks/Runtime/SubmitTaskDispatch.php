<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Tasks\Actions\AcceptTaskRun;
use App\Tasks\Actions\CompleteTaskGroup;
use App\Tasks\Actions\MarkTaskRunReadyForReview;
use App\Tasks\Actions\RecordTaskRunReview;
use App\Tasks\Enums\TaskReviewVerdict;
use App\Tasks\Enums\TaskStatus;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\Proof\NativeOrbitTaskProof;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;
use App\Tasks\TaskPayload;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class SubmitTaskDispatch
{
    public function __construct(private TaskRuntimeLock $lock, private TaskPayload $payloads, private GitTaskWorktree $git,
        private MarkTaskRunReadyForReview $ready, private RecordTaskRunReview $review,
        private AcceptTaskRun $accept, private CompleteTaskGroup $complete, private TaskRuntimePlan $plans,
        private NativeOrbitTaskProof $proofs, private TaskProofReviewFiles $proofFiles, private TaskMainIntegration $integrations,
        private TaskArtifactReviews $artifacts) {}

    /** @param array<string, mixed> $receipt */
    public function handle(TaskAgentDispatch $dispatch, array $receipt): TaskAgentDispatch
    {
        $token = $receipt['token'] ?? null;
        if (! is_string($token) || ! hash_equals($dispatch->token_hash, hash('sha256', $token))) {
            throw new InvalidArgumentException('Invalid dispatch handoff token.');
        }
        unset($receipt['token']);
        foreach (['summary', 'evidence'] as $field) {
            if (! is_string($receipt[$field] ?? null) || trim($receipt[$field]) === '' || mb_strlen($receipt[$field]) > 100_000) {
                throw new InvalidArgumentException('A handoff requires bounded, nonempty summary and evidence.');
            }
        }
        if (array_diff(array_keys($receipt), ['summary', 'evidence', 'verdict']) !== []) {
            throw new InvalidArgumentException('Unknown handoff fields.');
        }
        $verdict = $receipt['verdict'] ?? null;
        if ($verdict !== 'blocked' && (in_array($dispatch->kind, ['review', 'final_review'], true)
            ? ! in_array($verdict, ['pass', 'revise'], true) : $verdict !== null)) {
            throw new InvalidArgumentException('Invalid verdict for this instruction.');
        }
        $claimed = $this->lock->handle($dispatch->task_workspace_id, function () use ($dispatch, $receipt): bool {
            $dispatch->refresh();
            if ($dispatch->state === 'acknowledged') {
                if (! $this->payloads->matches($dispatch->result ?? [], $receipt)) {
                    throw new LogicException('An acknowledged handoff cannot be replaced.');
                }

                return false;
            }
            $this->assertCurrent($dispatch);
            if (! in_array($dispatch->state, ['prompting', 'sent', 'ambiguous'], true) || $dispatch->session === null) {
                throw new LogicException('Only the current dispatched instruction can submit a handoff.');
            }
            $dispatch->update(['state' => 'submitting']);

            return true;
        });
        if (! $claimed) {
            return $dispatch;
        }

        try {
            $workspace = $dispatch->workspace()->firstOrFail();
            $run = $dispatch->run()->first();
            $integration = $run === null ? null : $this->integrations->binding($run);
            $snapshot = null;
            $commit = null;
            $proofReview = null;
            if ($verdict !== 'blocked') {
                if ($dispatch->kind === 'implement') {
                    $snapshot = $this->git->snapshot($workspace->worktree, $run->base_sha ?? throw new LogicException('Missing run base.'), $integration['main_sha'] ?? null);
                } elseif ($dispatch->kind === 'review' || $dispatch->kind === 'commit') {
                    $review = $run?->reviews()->where('round', $dispatch->round)->firstOrFail();
                    $base = $run->base_sha ?? throw new LogicException('Missing run base.');
                    $tree = $review->tree_sha ?? throw new LogicException('Missing reviewed tree.');
                    if ($dispatch->kind === 'review') {
                        $this->git->assertSnapshot($workspace->worktree, $base, $tree, $integration['main_sha'] ?? null);
                        if ($this->artifacts->active($run)) {
                            $this->artifacts->verifyLive($workspace, $run, $review);
                        }
                    } else {
                        if ($this->artifacts->active($run)) {
                            throw new LogicException('Artifact reviews cannot receive commit instructions.');
                        }
                        $commit = $this->git->acceptedCommit($workspace->worktree, $base, $tree, $integration['main_sha'] ?? null);
                    }
                } else {
                    $head = $this->git->validate($workspace->repository, $workspace->worktree);
                    $this->artifacts->verifyAcceptedArtifacts($workspace);
                    $check = $dispatch->final_check_version === 1 ? $dispatch->final_check : $workspace->final_check;
                    if (($check['sha'] ?? null) !== $head || ($check['exit_code'] ?? null) !== 0
                        || ($dispatch->final_check_version === 1 && (($check['candidate_unchanged'] ?? null) !== true
                            || ! is_string($check['manifest_hash'] ?? null)))
                        || (isset($check['manifest_hash']) && ($check['manifest_hash'] !== $this->plans->effectiveHash($workspace)
                            || $check['manifest_hash'] !== $this->plans->hash($workspace->root()->firstOrFail())))) {
                        throw new LogicException('The final review no longer matches the checked candidate.');
                    }
                    $profile = OrbitTaskProfile::forWorkspace($workspace);
                    if ($verdict === 'pass' && ($profile['flow'] ?? null) === 'proof') {
                        $prepared = $check['native_proof'] ?? null;
                        if (! is_array($prepared)) {
                            throw new LogicException('The final proof review has no immutable native proof binding.');
                        }
                        $this->proofFiles->verify($workspace->id, $check);
                        $proofReview = $this->proofs->finalize($workspace, TaskLandingData::object($prepared));
                    }
                }
            }

            return $this->lock->handle($workspace->id, function () use ($workspace, $dispatch, $receipt, $snapshot, $commit, $verdict, $proofReview): TaskAgentDispatch {
                $dispatch->refresh();
                $this->assertCurrent($dispatch);
                if ($dispatch->state !== 'submitting') {
                    throw new LogicException('Handoff ownership changed.');
                }
                $workspace->refresh();
                $run = $dispatch->run()->first();
                if ($verdict === 'blocked') {
                    $workspace->update(['attention' => $receipt['summary']]);
                } else {
                    match ($dispatch->kind) {
                        'implement' => $this->ready->handle($run ?? throw new LogicException('Missing run.'), $run->worker_ref, $dispatch->round + 1, $receipt, $snapshot),
                        'review' => $this->review->handle($run?->reviews()->where('round', $dispatch->round)->firstOrFail() ?? throw new LogicException('Missing review.'),
                            $run->reviewer_ref, TaskReviewVerdict::from((string) $verdict), (string) $receipt['summary'], (string) $receipt['evidence']),
                        'commit' => $this->accept->handle($run?->reviews()->where('round', $dispatch->round)->firstOrFail() ?? throw new LogicException('Missing review.'), $commit),
                        'final_review' => $verdict === 'pass' ? $this->complete->handle($workspace->root()->firstOrFail()) : null,
                        default => throw new LogicException('Unknown task instruction.'),
                    };
                    if ($run !== null && $this->artifacts->active($run)) {
                        if ($dispatch->kind === 'review' && $verdict === 'pass') {
                            $this->accept->handle($run->reviews()->where('round', $dispatch->round)->firstOrFail());
                        } elseif ($dispatch->kind === 'implement') {
                            $workspace->update(['attention' => $this->artifacts->hold($run, $dispatch->round + 1)]);
                        }
                    }
                    if ($dispatch->kind === 'final_review') {
                        $workspace->update(['final_result' => $proofReview === null ? $receipt : [...$receipt, 'native_proof_review' => $proofReview],
                            'attention' => $verdict === 'revise' ? (string) $receipt['summary'] : null]);
                    } elseif ($workspace->attention !== null && str_starts_with($workspace->attention, 'Dispatch '.$dispatch->id.' ')) {
                        $workspace->update(['attention' => null]);
                    }
                }
                $dispatch->update(['state' => 'acknowledged', 'result' => $receipt, 'error' => null]);

                return $dispatch;
            });
        } catch (Throwable $exception) {
            $this->lock->handle($dispatch->task_workspace_id, function () use ($dispatch): void {
                $dispatch->refresh();
                if ($dispatch->state === 'submitting') {
                    $dispatch->update(['state' => 'sent']);
                }
            });
            throw $exception;
        }
    }

    private function assertCurrent(TaskAgentDispatch $dispatch): void
    {
        $workspace = $dispatch->workspace()->firstOrFail();
        if ($workspace->dispatches()->orderByDesc('id')->first()?->id !== $dispatch->id) {
            throw new LogicException('This handoff belongs to an older instruction.');
        }
        if ($dispatch->kind === 'final_review') {
            if ($workspace->root()->firstOrFail()->children()->where('status', '!=', TaskStatus::Completed)->exists()) {
                throw new LogicException('Final review requires every implementation task to be accepted.');
            }

            return;
        }
        $run = $dispatch->run()->firstOrFail();
        $task = $run->task()->firstOrFail();
        $round = $run->reviews()->orderByDesc('round')->first()->round ?? 0;
        $expected = match ($dispatch->kind) {
            'implement' => $dispatch->round === 0 ? TaskStatus::Running : TaskStatus::ChangesRequested,
            'review' => TaskStatus::AwaitingReview,
            'commit' => TaskStatus::AwaitingCommit,
            default => throw new LogicException('Unknown task instruction.'),
        };
        if ($run->active_root_task_id !== $workspace->root_task_id || $run->active_task_id !== $task->id
            || $task->status !== $expected || $round !== $dispatch->round
            || $task->runs()->orderByDesc('attempt')->first()?->id !== $run->id) {
            throw new LogicException('The handoff does not match the active task run and round.');
        }
    }
}
