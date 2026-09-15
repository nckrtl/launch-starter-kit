<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Landing\TaskLandingEvidence;
use LogicException;

final readonly class ReacquireTaskSnapshot
{
    public function __construct(private PrepareTaskSnapshotReacquisition $preparation,
        private NativeTaskSnapshotReacquisition $native, private NativeTaskProofCloseout $closeout,
        private TaskSnapshotReacquisitionEvidence $evidence, private TaskCloseoutLedger $ledger,
        private ReviewTaskSnapshotReacquisition $reviewer, private TaskLandingEvidence $secrets) {}

    /** Caller owns exclusive issue execution and the closeout lock. Review transport must run outside that lock.
     * @return array<string,mixed>
     */
    public function handle(TaskLanding $landing, string $stage, bool $apply): array
    {
        $index = array_search($stage, TaskSnapshotReacquisitionEvidence::STAGES, true);
        if ($index === false || $stage === 'snapshot-review') {
            throw new LogicException('Choose one explicit postinstall stage; dispatch review outside the closeout lock.');
        }
        $installation = $this->evidence->installation($landing);
        if ((OrbitTaskProfile::forWorkspace($landing->workspace()->firstOrFail())['snapshot_replacement'] ?? false) !== true) {
            throw new LogicException('Ordinary nonreplacement proof requires native closeout, not ORB-91 reacquisition.');
        }
        $prior = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $stage)->first();
        if ($prior?->result !== null) {
            $chain = $this->evidence->through($landing, $installation, $stage);

            return ['applied' => false, 'stage' => $stage, 'result' => Data::object($chain['records'])[$stage]];
        }
        if ($stage === 'snapshot-prepare') {
            $workspace = $landing->workspace()->firstOrFail();
            $request = $prior === null ? $this->preparation->freeze(['schema' => 1, 'issue' => $workspace->source_key,
                'repository' => $workspace->repository, 'worktree_root' => $workspace->configuration['worktree_root'] ?? null,
                'accepted_worktree' => $workspace->worktree, 'accepted_candidate' => $landing->candidate_sha,
                'accepted_artifact' => $landing->artifact_sha, 'merged_main' => $installation['main_sha']]) : Data::object($prior->input['request'] ?? null);
            $generation = $prior === null ? $this->generation($landing, $installation) : Data::object($prior->input['generation'] ?? null);
            $chain = ['request' => $request, 'generation' => $generation, 'records' => [], 'previous' => null];
        } else {
            $chain = $this->evidence->through($landing, $installation, TaskSnapshotReacquisitionEvidence::STAGES[$index - 1]
                ?? throw new LogicException('Missing explicit preceding snapshot stage.'));
            $request = Data::object($chain['request']);
            $generation = Data::object($chain['generation']);
        }
        $input = $this->evidence->input($landing, $installation, $request, $generation,
            $chain['previous'] === null ? null : Data::object($chain['previous']));
        $records = Data::object($chain['records']);
        if (! $apply) {
            return ['applied' => false, 'stage' => $stage, 'input' => $input,
                'state' => $prior->state ?? 'eligible'];
        }
        // Concrete native stdout/release receipts cannot be reconstructed from missing leases.
        // Local preparation has exact durable read-back, but can only be adopted after this coordinator's recorded intent.
        $response = null;
        $result = $this->ledger->step($landing, $stage, $input,
            function () use (&$response, $landing, $installation, $stage, $request, $generation): ?array {
                return in_array($stage, ['snapshot-prepare', 'snapshot-bootstrap', 'snapshot-remove-worktree', 'snapshot-remove-branch'], true)
                    ? $this->reconcile($landing, $installation, $stage, $request, $generation) : $response;
            },
            function () use (&$response, $landing, $stage, $request, $generation, $records): void {
                $observed = match ($stage) {
                    'snapshot-prepare' => $this->preparation->executeOnce($request),
                    'snapshot-bootstrap' => $this->preparation->bootstrapOnce($request),
                    'snapshot-remove-worktree' => $this->preparation->removeWorktreeOnce($request),
                    'snapshot-remove-branch' => $this->preparation->removeBranchOnce($request),
                    default => $this->native->executeOnce(substr($stage, strlen('snapshot-')),
                        $this->evidence->nativeRequest($request, $generation), $this->evidence->nativeEvidence($records)),
                };
                $this->evidence->receipt($stage, $observed, $request, $generation, $records, null);
                $this->secrets->assertNoSecrets($landing->workspace()->firstOrFail(), $observed);
                $response = $observed;
            },
            function () use ($landing, $installation, $generation, $request, $stage, $records): array {
                $this->live($landing, $installation, $generation);
                $this->preflight($stage, $request, $generation, $records);

                return ['installation_hash' => Data::hash($installation), 'generation_hash' => Data::hash($generation),
                    'request_hash' => Data::hash($request)];
            });
        $this->evidence->through($landing, $installation, $stage);

        return ['applied' => true, 'stage' => $stage, 'result' => $result];
    }

    /** @param array<string,mixed> $installation
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $generation
     * @return array<string,mixed>|null
     */
    private function reconcile(TaskLanding $landing, array $installation, string $stage, array $request, array $generation): ?array
    {
        if (! in_array($stage, ['snapshot-prepare', 'snapshot-bootstrap', 'snapshot-remove-worktree', 'snapshot-remove-branch'], true)) {
            return null;
        }
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $stage)->firstOrFail();
        if (! in_array($operation->state, ['intended', 'unknown'], true)) {
            return null;
        }
        if ($operation->preflight !== ['installation_hash' => Data::hash($installation), 'generation_hash' => Data::hash($generation),
            'request_hash' => Data::hash($request)]) {
            throw new LogicException('Reconcile only the exact preparation or cleanup with recorded preflight authority.');
        }
        $this->live($landing, $installation, $generation);
        $result = match ($stage) {
            'snapshot-prepare' => $this->preparation->inspect($request),
            'snapshot-bootstrap' => $this->preparation->inspectReadiness($request),
            default => $this->preparation->inspectRemoval($request),
        };
        $expected = match ($stage) {
            'snapshot-prepare' => 'prepared', 'snapshot-bootstrap' => 'ready',
            'snapshot-remove-worktree' => 'worktree_removed', default => 'removed',
        };
        if (($result['state'] ?? null) !== $expected) {
            return null;
        }
        $this->evidence->receipt($stage, $result, $request, $generation, [], null);
        $this->secrets->assertNoSecrets($landing->workspace()->firstOrFail(), $result);

        return $result;
    }

    /** Read while holding the closeout lock; release it before dispatchReview().
     * @return array<string,mixed>
     */
    public function reviewBundle(TaskLanding $landing): array
    {
        $installation = $this->evidence->installation($landing);
        $chain = $this->evidence->through($landing, $installation, 'snapshot-observe-proof');
        $this->live($landing, $installation, Data::object($chain['generation']));
        $this->preflight('snapshot-review', Data::object($chain['request']), Data::object($chain['generation']), Data::object($chain['records']));

        return $this->evidence->bundle($installation, Data::object($chain['request']), Data::object($chain['generation']),
            Data::object($chain['records']), Data::object($chain['pointers']));
    }

    /** @param array<string,mixed> $bundle
     * @return array<string,mixed>
     */
    public function dispatchReview(TaskLanding $landing, array $bundle, bool $apply): array
    {
        return ['stage' => 'snapshot-review', ...$this->reviewer->dispatch($landing, $bundle, $apply)];
    }

    /** Read-only and usable after auxiliary/original worktrees are gone and main/G advance.
     * @return array<string,mixed>
     */
    public function completionEvidence(TaskLanding $landing): array
    {
        $installation = $this->evidence->installation($landing);
        $replacement = OrbitTaskProfile::forWorkspace($landing->workspace()->firstOrFail())['snapshot_replacement'] ?? false;

        return [...$installation, 'reacquisition' => $replacement
            ? $this->evidence->through($landing, $installation, 'snapshot-remove-branch') : null];
    }

    /** @param array<string,mixed> $installation
     * @return array<string,mixed>
     */
    private function generation(TaskLanding $landing, array $installation): array
    {
        $generation = Data::object(json_decode(Data::file($landing->workspace()->firstOrFail()->repository.'/.e2e/topology-snapshot/promoted.json'), true, flags: JSON_THROW_ON_ERROR));
        if (($generation['id'] ?? null) !== (Data::object($installation['native_proof_closeout'])['generation_id'] ?? null)
            || ($generation['main_sha'] ?? null) !== $installation['main_sha']) {
            throw new LogicException('The current installed generation is not the exact native closeout generation.');
        }

        return $generation;
    }

    /** @param array<string,mixed> $installation
     * @param  array<string,mixed>  $generation
     */
    private function live(TaskLanding $landing, array $installation, array $generation): void
    {
        $merge = Data::object($this->evidence->operation($landing, 'merge')->result);
        $native = $this->closeout->inspect($landing, $merge);
        if ($this->evidence->installation($landing) !== $installation || $this->generation($landing, $installation) !== $generation
            || ($native['closeout'] ?? null) !== $installation['native_proof_closeout'] || ($native['proof_released'] ?? null) !== true) {
            throw new LogicException('Postinstall execution requires the exact installed G and completed original native closeout.');
        }
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $generation
     * @param  array<string,mixed>  $records
     */
    private function preflight(string $stage, array $request, array $generation, array $records): void
    {
        if ($stage === 'snapshot-prepare') {
            if ($this->preparation->inspect($request)['state'] !== 'absent') {
                throw new LogicException('Postinstall preparation is create-only; do not adopt existing resources.');
            }

            return;
        }
        if ($stage === 'snapshot-bootstrap') {
            if (($this->preparation->inspectReadiness($request)['state'] ?? null) !== 'not_started') {
                throw new LogicException('Bootstrap was already attempted; do not replay it.');
            }

            return;
        }
        if ($stage === 'snapshot-remove-branch') {
            if ($this->preparation->inspectRemoval($request)['state'] !== 'worktree_removed') {
                throw new LogicException('Remove only the exact auxiliary branch after recorded worktree removal.');
            }

            return;
        }
        if ($this->preparation->inspectReadiness($request) !== ($records['snapshot-bootstrap'] ?? null)) {
            throw new LogicException('The isolated M bootstrap changed after readiness.');
        }
        $observed = $this->native->inspect($this->evidence->nativeRequest($request, $generation));
        $previous = null;
        foreach ($records as $record) {
            if (is_array($record) && isset($record['after'])) {
                $previous = Data::object($record['after']);
            }
        }
        if ($previous !== null && $previous !== $observed) {
            throw new LogicException('Native auxiliary state changed since the last exact receipt; inspect without replay.');
        }
        if ($stage === 'snapshot-discover' && (($observed['artifact'] ?? null) !== null
            || array_any(Data::object($observed['status'] ?? null), fn (mixed $value): bool => $value !== null))) {
            throw new LogicException('Start from absent auxiliary attempts and unpublished M inputs; never adopt foreign work.');
        }
    }
}
