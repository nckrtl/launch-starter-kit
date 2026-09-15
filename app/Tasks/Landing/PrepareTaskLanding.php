<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Models\TaskLanding;
use App\Models\TaskWorkspace;
use App\Tasks\Runtime\TaskRuntimeLock;
use LogicException;
use Throwable;

final readonly class PrepareTaskLanding
{
    public function __construct(private TaskRuntimeLock $lock, private TaskLandingEvidence $evidence, private TaskLandingRepository $repository,
        private TaskLandingPackage $packages, private TaskLandingHistory $history) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function handle(int $workspaceId, array $request, bool $exclusive, ?string $expectedHash = null, bool $apply = false): array
    {
        if ($workspaceId < 1 || ! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Confirm exclusive source/worktree ownership and enable Tasks before applying a landing package.');
        }
        $request = TaskLandingData::request($request);
        $workspace = TaskWorkspace::query()->findOrFail($workspaceId);
        $inputs = $this->evidence->capture($workspace, $request);
        $inputHash = TaskLandingData::hash($inputs);
        $proposalHash = TaskLandingData::hash(['input_hash' => $inputHash, 'request' => $request]);
        $candidate = TaskLandingData::text($request, 'candidate');
        $artifact = $this->repository->artifact($workspace, $candidate, $inputs);
        $existing = $this->history->original($workspaceId);
        if ($existing !== null && ($existing->request !== $request || $existing->inputs !== $inputs || $existing->input_hash !== $inputHash)) {
            throw new LogicException('This workspace already has different immutable landing inputs.');
        }
        if (! $apply) {
            return ['applied' => false, 'input_hash' => $inputHash, 'proposal_hash' => $proposalHash,
                'proposed_pull_request_body' => $request['pull_request_body'], 'inputs' => $inputs,
                'published_artifact' => $artifact, 'landing' => $existing?->toArray()];
        }
        if ($expectedHash === null || ! hash_equals($proposalHash, $expectedHash)) {
            throw new LogicException('Preview and pin the exact proposal hash, including the proposed PR body, before applying the package.');
        }
        [$landing, $publish] = $this->lock->handle($workspaceId, function () use ($workspace, $request, $inputs, $inputHash, $candidate): array {
            $this->evidence->guard($workspace, $request, $inputs);
            $landing = $this->history->original($workspace->id);
            if ($landing === null) {
                $landing = TaskLanding::query()->create(['task_workspace_id' => $workspace->id,
                    'final_dispatch_id' => $request['final_dispatch'], 'issue_id' => $request['issue_id'], 'candidate_sha' => $candidate,
                    'input_hash' => $inputHash, 'request' => $request, 'inputs' => $inputs,
                    'artifact_ref' => 'refs/tags/loop/'.mb_strtolower($workspace->source_key).'/'.$candidate]);
            }
            if ($landing->request !== $request || $landing->inputs !== $inputs || $landing->input_hash !== $inputHash) {
                throw new LogicException('The landing intent changed during observation.');
            }
            $publish = $landing->state === 'prepared';
            if ($publish) {
                $landing->update(['state' => 'publishing']);
            }

            return [$landing, $publish];
        });
        if ($landing->package_hash !== null) {
            if ($artifact !== $landing->artifact_sha || TaskLandingData::hash($landing->package) !== $landing->package_hash) {
                throw new LogicException('The frozen package or published artifact no longer matches.');
            }

            return ['applied' => false, 'landing' => $landing->toArray()];
        }
        try {
            if ($publish && $artifact === null) {
                try {
                    $this->repository->publish($workspace, $candidate, $inputs);
                } catch (Throwable) {
                    // The response is not authority: only the exact artifact read-back can confirm publication.
                }
            }
            $artifact = $this->repository->artifact($workspace, $candidate, $inputs);
            if ($artifact === null) {
                throw new LogicException('Artifact publication remains unresolved. The recorded intent will not publish again; reconcile its exact ref.');
            }
            if ($this->evidence->capture($workspace, $request) !== $inputs) {
                throw new LogicException('The package inputs changed during publication.');
            }
            $package = $this->packages->render($workspace, $landing, $artifact);
            $this->evidence->assertNoSecrets($workspace, $package);
            $this->lock->handle($workspaceId, function () use ($workspace, $landing, $request, $inputs, $artifact, $package): void {
                $this->evidence->guard($workspace, $request, $inputs);
                $landing->refresh();
                if ($landing->package_hash !== null) {
                    if ($landing->package !== $package || $landing->artifact_sha !== $artifact) {
                        throw new LogicException('A conflicting package was recorded.');
                    }

                    return;
                }
                if (! in_array($landing->state, ['publishing', 'publication_unknown'], true)) {
                    throw new LogicException('Landing publication ownership changed.');
                }
                $landing->update(['artifact_sha' => $artifact, 'package' => $package,
                    'package_hash' => TaskLandingData::hash($package), 'state' => 'packaged', 'error' => null]);
            });
        } catch (Throwable $exception) {
            $this->lock->handle($workspaceId, function () use ($landing): void {
                $landing->refresh();
                if ($landing->package_hash === null && in_array($landing->state, ['publishing', 'publication_unknown'], true)) {
                    $landing->update(['state' => 'publication_unknown', 'error' => 'Publication or package evidence is unresolved; inspect the retained intent. No automatic retry.']);
                }
            });
            throw $exception;
        }

        return ['applied' => true, 'landing' => $landing->fresh()?->toArray()];
    }
}
