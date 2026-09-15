<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskAgentDispatch;
use App\Models\TaskRun;
use App\Models\TaskWorkspace;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\Proof\NativeOrbitTaskProof;
use App\Tasks\Orbit\Proof\TaskProofReviewFiles;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class DispatchTaskAgent
{
    public function __construct(private TaskRuntimeLock $lock, private TaskAgents $agents, private GitTaskWorktree $git, private TaskAgentPrompt $prompts,
        private TaskRuntimePlan $plans, private ReconcileTaskReattempt $reattempts, private NativeOrbitTaskProof $proofs,
        private TaskProofReviewFiles $proofFiles, private TaskAgentSessions $sessions, private TaskProofPreflight $preflight,
        private TaskArtifactReviews $artifacts) {}

    public function handle(TaskAgentDispatch $dispatch, ?string $executionKey = null): void
    {
        $claimed = $this->lock->handle($dispatch->task_workspace_id, function () use ($dispatch, $executionKey): bool {
            $dispatch = TaskAgentDispatch::query()->findOrFail($dispatch->id);
            if ($dispatch->state !== 'prepared') {
                return false;
            }
            if ($dispatch->kind === 'final_review' && $dispatch->final_check_version !== 1) {
                throw new LogicException('Legacy prepared final instructions require the original runner; do not reinterpret or resend them.');
            }
            $dispatch->update(['state' => 'sending', 'execution_key' => $executionKey]);

            return true;
        });
        if (! $claimed) {
            return;
        }

        try {
            $dispatch->refresh();
            $workspace = $dispatch->workspace()->firstOrFail();
            $run = $dispatch->run()->first();
            if ($run !== null && $this->artifacts->active($run)) {
                if ($dispatch->kind === 'commit') {
                    throw new LogicException('Artifact results cannot dispatch commit instructions.');
                }
                if ($dispatch->kind === 'review') {
                    $this->artifacts->verifyLive($workspace, $run, $run->reviews()->where('round', $dispatch->round)->firstOrFail());
                }
            }
            if ($dispatch->kind === 'final_review') {
                if (! $this->checkFeature($workspace, $dispatch)) {
                    return;
                }
                $workspace->refresh();
                $this->artifacts->verifyAcceptedArtifacts($workspace);
                if ((OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) === 'proof' && $workspace->final_check !== null) {
                    $this->proofFiles->retain($workspace, $workspace->final_check);
                }
                $dispatch->update(['prompt' => $this->prompts->render($workspace->refresh(), $dispatch, $dispatch->handoff_token, null)]);
            }
            $retained = $this->reattempts->beforeSend($workspace, $dispatch);
            $session = $this->sessions->resolve($workspace, $retained ?? $this->session($workspace, $dispatch));
            $this->lock->handle($workspace->id, function () use ($workspace, $dispatch, $session): void {
                $dispatch->refresh();
                $this->reattempts->assertCurrent($workspace->refresh(), $dispatch);
                if ($dispatch->state !== 'sending') {
                    throw new LogicException('Dispatch ownership changed before prompting.');
                }
                $dispatch->update(['session' => $session, 'state' => 'prompting']);
            });
            $session = $this->agents->prompt($workspace, $session, $dispatch->prompt);
            $this->lock->handle($workspace->id, function () use ($dispatch, $workspace, $session): void {
                $dispatch->refresh();
                if ($dispatch->state === 'prompting') {
                    $dispatch->update(['state' => 'sent', 'session' => $session]);
                    if ($dispatch->kind !== 'implement') {
                        $workspace->refresh()->update(['reviewer_session' => $session]);
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->lock->handle($dispatch->task_workspace_id, function () use ($dispatch, $exception): void {
                $dispatch->refresh();
                if (in_array($dispatch->state, ['acknowledged', 'integration_required'], true)) {
                    return;
                }
                $message = Str::limit($exception->getMessage(), 1000);
                $dispatch->update(['state' => 'ambiguous', 'error' => $message]);
                $dispatch->workspace()->firstOrFail()->update(['attention' => 'Dispatch '.$dispatch->id.' needs inspection; it will not be resent. '.$message]);
            });
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function session(TaskWorkspace $workspace, TaskAgentDispatch $dispatch): array
    {
        if ($dispatch->kind !== 'implement' && $workspace->reviewer_session !== null) {
            return $workspace->reviewer_session;
        }
        $run = $dispatch->run()->first();
        if ($dispatch->kind === 'implement') {
            $previous = $workspace->dispatches()->where('task_run_id', $dispatch->task_run_id)
                ->where('kind', 'implement')->whereNotNull('session')->orderByDesc('id')->first();
            if ($previous?->session !== null) {
                return $previous->session;
            }
        }
        $name = $dispatch->kind === 'implement' ? $run?->worker_ref : 'task-w'.$workspace->id.'-reviewer';
        if ($name === null) {
            throw new LogicException('Missing task agent assignment.');
        }
        $session = $this->agents->start($workspace, $name);
        if ($dispatch->kind !== 'implement') {
            $workspace->update(['reviewer_session' => $session]);
        }

        return $session;
    }

    private function checkFeature(TaskWorkspace $workspace, TaskAgentDispatch $dispatch): bool
    {
        if ($this->preflight->holdIfNeeded($workspace, $dispatch)) {
            return false;
        }
        $manifest = $this->plans->effectiveHash($workspace);
        $head = $this->git->validate($workspace->repository, $workspace->worktree);
        $last = TaskRun::query()->where('root_task_id', $workspace->root_task_id)->whereNotNull('commit_sha')->orderByDesc('id')->first();
        if ($last === null || $last->commit_sha !== $head) {
            throw new LogicException('Final verification requires the last accepted task commit, without extra commits.');
        }
        $command = $workspace->configuration['final_command'] ?? null;
        $timeout = $workspace->configuration['final_timeout'] ?? null;
        if (! is_array($command) || ! array_is_list($command) || ! is_int($timeout)) {
            throw new LogicException('Invalid final-check configuration.');
        }
        foreach ($command as $argument) {
            if (! is_string($argument)) {
                throw new LogicException('Invalid final-check argument.');
            }
        }
        $result = Process::path($workspace->worktree)->timeout($timeout)
            ->env(TaskProcessEnvironment::isolated())->run($command);
        try {
            $unchanged = $this->git->validate($workspace->repository, $workspace->worktree) === $head;
        } catch (\RuntimeException) {
            $unchanged = false;
        }
        $check = ['sha' => $head, 'manifest_hash' => $manifest,
            'candidate_unchanged' => $unchanged, 'command' => $command, 'exit_code' => $result->exitCode(),
            'output' => $result->output(), 'error_output' => $result->errorOutput()];
        $profile = OrbitTaskProfile::forWorkspace($workspace);
        if ($result->successful() && $unchanged && ($profile['flow'] ?? null) === 'proof') {
            if ($this->preflight->holdIfNeeded($workspace, $dispatch, $check)) {
                return false;
            }
            $mismatch = $this->artifacts->publicationInputMismatch($workspace);
            if ($mismatch === null) {
                $check['native_proof'] = $this->proofs->prepare($workspace, $check);
            } else {
                $check['artifact_input_mismatch'] = $mismatch;
            }
        }

        return $this->lock->handle($workspace->id, function () use ($workspace, $dispatch, $check, $result, $unchanged): bool {
            $dispatch->refresh();
            if ($dispatch->state !== 'sending' || $workspace->dispatches()->orderByDesc('id')->first()?->id !== $dispatch->id) {
                throw new LogicException('Final check ownership changed before its result was recorded.');
            }
            if ($check['manifest_hash'] !== $this->plans->effectiveHash($workspace)
                || $check['manifest_hash'] !== $this->plans->hash($workspace->root()->firstOrFail())) {
                throw new LogicException('The final check manifest changed during execution.');
            }
            if (isset($check['artifact_input_mismatch'])
                && ($dispatch->session !== null || $dispatch->result !== null || $dispatch->final_check !== null
                    || $workspace->refresh()->final_check !== null || $workspace->final_result !== null || $workspace->attention !== null
                    || $this->git->validate($workspace->repository, $workspace->worktree) !== $check['sha']
                    || $this->artifacts->publicationInputMismatch($workspace) !== $check['artifact_input_mismatch'])) {
                throw new LogicException('The unprompted artifact input preflight changed before its hold was recorded.');
            }
            $dispatch->update(['final_check' => $check]);
            $workspace->update(['final_check' => $check]);
            if ($result->failed() || ! $unchanged || isset($check['artifact_input_mismatch'])) {
                $message = isset($check['artifact_input_mismatch'])
                    ? 'Final dispatch '.$dispatch->id.' held before proof publication: Builder passed, but accepted artifact inputs changed; append an independently reviewed correction.'
                    : 'Final check dispatch '.$dispatch->id.' failed; inspect its evidence before continuing.';
                $dispatch->update(['state' => 'check_failed', 'error' => $message]);
                $workspace->update(['attention' => $message]);

                return false;
            }

            return true;
        });
    }
}
