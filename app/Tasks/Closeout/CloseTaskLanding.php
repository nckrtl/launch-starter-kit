<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Orbit\CloseOrbitTaskProof;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\ReacquireTaskSnapshot;
use App\Tasks\Orbit\TaskSnapshotReacquisitionEvidence;
use App\Tasks\TaskPayload;
use LogicException;

final readonly class CloseTaskLanding
{
    public function __construct(private TaskCloseoutLock $lock, private TaskCloseoutContext $context,
        private TaskCloseoutLedger $ledger, private TaskCloseoutGitHub $github,
        private TaskCloseoutRepository $repository, private TaskMainHoldService $holds, private TaskPayload $payloads,
        private CloseOrbitTaskProof $proof, private ReacquireTaskSnapshot $snapshots) {}

    /** Each stage is explicit. Preview never writes a ledger, pushes, fetches objects or publishes.
     * @return array<string,mixed>
     */
    public function handle(int $id, string $package, string $stage, bool $exclusive, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)
            || ! in_array($stage, ['publish', 'merge', 'verify', 'reconcile-primary', 'proof-closeout', 'release', ...TaskSnapshotReacquisitionEvidence::STAGES], true)) {
            throw new LogicException('Choose one explicit exclusive Tasks closeout stage; enable Tasks before applying.');
        }
        $operation = function () use ($id, $package, $stage, $apply): array {
            $landing = $this->context->approved($id, $package);
            $pr = $this->github->publication($landing);
            if ($stage === 'publish') {
                $this->context->observe($landing, $pr);
                if (! $apply) {
                    return ['applied' => false, 'stage' => $stage, 'package_hash' => $package,
                        'branch' => $this->repository->branch($landing), 'pull_request' => $pr,
                        'approval' => $pr === null ? null : $this->github->approval($landing, $pr, $this->context->approvalMarker($landing))];
                }

                return $this->publish($landing);
            }
            $pr = $this->publication($landing, $pr);
            $merged = $this->github->merged($landing, $pr);
            if ($stage === 'merge') {
                if ($merged === null) {
                    $this->beforeMerge($landing, $pr);
                }
                if (! $apply) {
                    return ['applied' => false, 'stage' => $stage, 'pull_request' => $pr, 'merged' => $merged];
                }
                if ($merged === null) {
                    $this->ledger->step($landing, 'reservation', $this->input($landing, $pr),
                        fn (): ?array => $this->github->reservation($landing, $pr),
                        fn () => $this->github->reserve($landing, $pr));
                }
                $result = $this->ledger->step($landing, 'merge', $this->input($landing, $pr),
                    fn (): ?array => $this->github->merged($landing, $pr),
                    fn () => $this->github->merge($landing, $pr),
                    function () use ($landing, $pr): array {
                        $gate = $this->beforeMerge($landing, $pr);
                        if ($this->github->reservation($landing, $pr) === null) {
                            throw new LogicException('The exact merge reservation was lost.');
                        }

                        return $gate;
                    });
                $this->same($result, $this->github->merged($landing, $pr), 'merged pull request');

                return ['applied' => true, 'stage' => $stage, 'merged' => $result];
            }
            $recorded = $this->ledger->result($landing, 'merge');
            if ($recorded === null) {
                throw new LogicException('Reconcile the exact merge into its ledger before post-merge work.');
            }
            $this->same($recorded, $merged, 'merged pull request');
            if ($stage === 'verify') {
                if (! $apply) {
                    return ['applied' => false, 'stage' => $stage, 'merged' => $merged,
                        'action' => 'Fetch authoritative main and verify native merge lineage for the admitted flow; no cleanup or hold clearance.'];
                }
                $verification = $this->repository->verify($landing, $recorded);
                $this->ledger->record($landing, 'verify:'.TaskLandingData::hash($verification), $this->input($landing, $pr), $verification);

                return ['applied' => true, 'stage' => $stage, 'verification' => $verification];
            }
            if (! TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'like', 'verify:%')
                ->where('state', 'completed')->exists()) {
                throw new LogicException('Verify the exact merged lineage before postmerge closeout or reservation release.');
            }
            if (in_array($stage, ['reconcile-primary', 'proof-closeout'], true)) {
                return $this->proof->handle($landing, $recorded, $stage, $apply);
            }
            if ($stage === 'snapshot-review') {
                return ['landing' => $landing, 'bundle' => $this->snapshots->reviewBundle($landing)];
            }
            if (in_array($stage, TaskSnapshotReacquisitionEvidence::STAGES, true)) {
                return $this->snapshots->handle($landing, $stage, $apply);
            }
            $releaseInput = $this->input($landing, $pr);
            if ((OrbitTaskProfile::forWorkspace($landing->workspace()->firstOrFail())['flow'] ?? null) === 'proof') {
                $releaseInput['proof_completion_hash'] = TaskLandingData::hash($this->snapshots->completionEvidence($landing));
            }
            $reservation = $this->github->reservation($landing, $pr);
            if (! $apply) {
                return ['applied' => false, 'stage' => $stage, 'reservation' => $reservation];
            }
            $released = $this->ledger->step($landing, 'release', $releaseInput,
                fn (): ?array => $this->github->reservation($landing, $pr) === null ? ['released' => true, 'url' => $pr['url']] : null,
                fn () => $this->github->release($landing, $pr));
            if ($this->github->reservation($landing, $pr) !== null) {
                throw new LogicException('The previously released reservation was acquired again; reconcile without replaying release.');
            }

            return ['applied' => true, 'stage' => $stage, 'release' => $released];
        };

        $result = $apply ? $this->lock->handle($operation) : $operation();
        // Review delivery can synchronously acknowledge its assignment, which takes the same lock.
        // All native bundle checks above finish before handing transport to the reviewer service.
        if ($stage === 'snapshot-review') {
            $landing = $result['landing'];
            if (! $landing instanceof TaskLanding) {
                throw new LogicException('Missing exact postinstall review landing.');
            }

            return $this->snapshots->dispatchReview($landing, TaskLandingData::object($result['bundle']), $apply);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function publish(TaskLanding $landing): array
    {
        $branch = $this->ledger->step($landing, 'branch', $this->input($landing),
            fn (): ?array => $this->repository->branch($landing), fn () => $this->repository->push($landing));
        $this->same($branch, $this->repository->branch($landing), 'feature branch');
        $this->context->observe($landing, $this->github->publication($landing));
        $pr = $this->ledger->step($landing, 'publication', $this->input($landing),
            fn (): ?array => $this->github->publication($landing), fn () => $this->github->publish($landing));
        $this->publication($landing, $this->github->publication($landing));
        $this->context->observe($landing, $pr);
        $marker = $this->context->approvalMarker($landing);
        $approval = $this->ledger->step($landing, 'approval', [...$this->input($landing, $pr), 'marker' => $marker],
            fn (): ?array => $this->github->approval($landing, $pr, $marker),
            fn () => $this->github->approve($landing, $pr, $marker));
        $this->same($approval, $this->github->approval($landing, $pr, $marker), 'package-specific approval');

        return ['applied' => true, 'stage' => 'publish', 'pull_request' => $pr, 'approval' => $approval];
    }

    /** @param array<string,mixed>|null $observed
     * @return array<string,mixed>
     */
    private function publication(TaskLanding $landing, ?array $observed): array
    {
        $pr = $this->ledger->result($landing, 'publication');
        if ($pr === null) {
            throw new LogicException('Publish and retain the exact package PR before continuing closeout.');
        }
        $this->same($pr, $observed, 'published pull request');

        return $pr;
    }

    /** @param array<string,mixed> $pr
     * @return array<string,mixed>
     */
    private function beforeMerge(TaskLanding $landing, array $pr): array
    {
        $this->context->observe($landing, $pr);
        $approval = $this->ledger->result($landing, 'approval');
        if ($approval === null) {
            throw new LogicException('Publish the retained independent package review before merging.');
        }
        $this->same($approval, $this->github->approval($landing, $pr, $this->context->approvalMarker($landing), true), 'package-specific approval');

        return ['approval' => $approval, 'main' => $this->holds->guardMerge($landing)];
    }

    /** @param array<string,mixed>|null $pr
     * @return array<string,mixed>
     */
    private function input(TaskLanding $landing, ?array $pr = null): array
    {
        return ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'identity_hash' => $this->github->identityHash(), 'pull_request' => $pr];
    }

    /** @param array<string,mixed> $expected
     * @param  array<string,mixed>|null  $actual
     */
    private function same(array $expected, ?array $actual, string $name): void
    {
        if ($actual === null || ! $this->payloads->matches($expected, $actual)) {
            throw new LogicException('The exact '.$name.' changed; reconcile without replaying mutations.');
        }
    }
}
