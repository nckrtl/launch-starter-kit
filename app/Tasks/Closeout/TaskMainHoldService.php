<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskMainHold;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\TaskPayload;
use LogicException;

final readonly class TaskMainHoldService
{
    public function __construct(private TaskCloseoutLock $lock, private TaskCloseoutContext $context,
        private TaskCloseoutRepository $repository, private TaskPayload $payloads, private TaskCloseoutLedger $ledger) {}

    /** Import is project-owned and does not require a candidate or review.
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    public function handle(string $action, array $request, bool $exclusive, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)
            || ! in_array($action, ['import', 'repair', 'observe', 'clear', 'withdraw'], true)) {
            throw new LogicException('Main hold changes require an explicit exclusive coordinator action.');
        }
        $operation = function () use ($action, $request, $apply): array {
            $landing = in_array($action, ['import', 'withdraw'], true) ? null : $this->landing($request);
            $configuration = $this->context->configuration($landing?->workspace()->firstOrFail());
            $main = $this->repository->main($configuration);
            if (($request['main_sha'] ?? null) !== $main->mainSha
                || ($request['repository'] ?? null) !== $configuration->repository) {
                throw new LogicException('Pin the exact configured repository and currently observed authoritative main.');
            }
            $evidence = $this->evidence($request);
            if ($action === 'import') {
                $incident = TaskLandingData::text($request, 'incident', 100);
                if (preg_match('/\\A[a-z0-9][a-z0-9._-]*\\z/', $incident) !== 1) {
                    throw new LogicException('Use one stable incident identifier; a recurrence needs a new incident.');
                }
                $record = ['repository' => $configuration->repository, 'main_sha' => $main->mainSha,
                    'native_failures_hash' => TaskLandingData::hash($main->failures), 'native_failures' => $main->failures,
                    'cases' => $this->cases($request), 'attestation' => $evidence];
                $existing = TaskMainHold::query()->where('project_id', 'orbit')->where('incident', $incident)->first();
                if ($existing !== null && ! $this->payloads->matches($existing->evidence, $record)) {
                    throw new LogicException('A recorded incident cannot be overwritten or silently reopened.');
                }
                if ($apply && $existing === null) {
                    $existing = TaskMainHold::query()->create(['project_id' => 'orbit', 'incident' => $incident,
                        'evidence' => $record, 'evidence_hash' => TaskLandingData::hash($record)]);
                }

                return ['applied' => $apply, 'incident' => $incident, 'hold' => $existing?->toArray(), 'evidence' => $record];
            }
            if ($action === 'withdraw') {
                if (! $main->passed()) {
                    throw new LogicException('Withdrawal cannot bypass a current native correctness failure.');
                }

                return $this->withdraw($request, $configuration->repository, $main->mainSha, $evidence, $apply);
            }
            $holdId = $request['hold_id'] ?? null;
            if (! is_int($holdId) || $holdId < 1 || $landing === null) {
                throw new LogicException('Pin the exact imported hold and independently approved repair package.');
            }
            $hold = TaskMainHold::query()->findOrFail($holdId);
            $this->assertHold($hold, $configuration->repository);
            $record = ['landing_id' => $landing->id, 'issue_id' => $landing->issue_id,
                'issue_key' => $landing->workspace()->firstOrFail()->source_key,
                'candidate_sha' => $landing->candidate_sha, 'package_hash' => $landing->package_hash,
                'review_hash' => TaskLandingData::hash($landing->review_result), 'hold_hash' => $hold->evidence_hash,
                'observed_main' => $main->mainSha, 'native_failures_hash' => TaskLandingData::hash($main->failures),
                'attestation' => $evidence];
            if ($action === 'repair') {
                if ($hold->clearance !== null) {
                    throw new LogicException('A cleared incident cannot receive another repair authorization.');
                }
                $scope = $this->openScope($configuration->repository);
                $retained = array_values(array_filter($scope, fn (array $incident): bool => $incident['id'] !== $hold->id));
                if (! $this->payloads->matches(['incidents' => $retained], ['incidents' => $request['retained_holds'] ?? []])) {
                    throw new LogicException('Explicitly acknowledge every other current incident by exact ID and evidence hash; do not claim to repair it.');
                }
                $record['retained_holds'] = $retained;
                $record['incident_scope_hash'] = TaskLandingData::hash($scope);
                $same = array_values(array_filter($hold->repairs, fn (array $repair): bool => ($repair['landing_id'] ?? null) === $landing->id
                    && ($repair['observed_main'] ?? null) === $record['observed_main']
                    && ($repair['native_failures_hash'] ?? null) === $record['native_failures_hash']
                    && ($repair['incident_scope_hash'] ?? null) === $record['incident_scope_hash']));
                if ($same !== [] && ! $this->payloads->matches($same[0], $record)) {
                    throw new LogicException('This exact package and observed incident scope already have an immutable repair authorization.');
                }
                if ($apply && $same === []) {
                    $hold->update(['repairs' => [...$hold->repairs, $record]]);
                }
            } else {
                if ($this->repair($hold, $landing) === null || ($action === 'clear' && ! $main->passed())) {
                    throw new LogicException('Case proof requires an exact authorized repair; clearance also requires no current native correctness failure.');
                }
                $merged = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'merge')->first()?->result;
                if ($merged === null || ($request['merge_sha'] ?? null) !== ($merged['merge_sha'] ?? null)) {
                    throw new LogicException('Clear requires the exact recorded repair merge.');
                }
                $mergeSha = TaskLandingData::text($merged, 'merge_sha');
                $verified = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
                    ->where('operation', 'like', 'verify:%')->where('state', 'completed')->get()
                    ->contains(function (TaskCloseoutOperation $operation) use ($landing, $mergeSha): bool {
                        $lineage = TaskLandingData::object($operation->result['lineage'] ?? null);

                        return ($lineage['flow'] ?? null) === 'discovery' && ($lineage['candidate'] ?? null) === $landing->candidate_sha
                            && ($lineage['merge'] ?? null) === $mergeSha && $operation->package_hash === $landing->package_hash;
                    });
                if (! $verified) {
                    throw new LogicException('Verify and retain the native discovery merge lineage before recording main case proof.');
                }
                if (! $this->repository->contains($configuration, $mergeSha, $main->mainSha)) {
                    throw new LogicException('Current main must contain the exact reviewed repair merge.');
                }
                $record['merge_sha'] = $mergeSha;
                $record['checks'] = $this->checkedCases($request, $hold->evidence['cases'] ?? [], $main->mainSha);
                $exit = $request['verification_exit_code'] ?? null;
                if (! is_int($exit) || $exit < 0 || $exit > 255 || ($action === 'clear' && $exit !== 0)) {
                    throw new LogicException('Record the actual overall verification exit code; a red command cannot clear a hold.');
                }
                $record['verification_exit_code'] = $exit;
                $record['native_failures'] = $main->failures;
                if ($action === 'observe') {
                    if ($apply) {
                        $this->ledger->record($landing, 'main-proof:'.$hold->id.':'.TaskLandingData::hash($record),
                            ['hold_id' => $hold->id, 'hold_hash' => $hold->evidence_hash, 'package_hash' => $landing->package_hash], $record);
                    }
                } else {
                    if ($hold->clearance !== null && ! $this->payloads->matches($hold->clearance, $record)) {
                        throw new LogicException('An existing main clearance is immutable.');
                    }
                    if ($apply && $hold->clearance === null) {
                        $hold->update(['clearance' => $record]);
                    }
                }
            }

            return ['applied' => $apply, 'action' => $action, 'hold' => $hold->toArray(), 'attestation' => $record];
        };

        return $apply ? $this->lock->handle($operation) : $operation();
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function withdraw(array $request, string $repository, string $main, array $evidence, bool $apply): array
    {
        if (array_diff(array_keys($request), ['repository', 'main_sha', 'hold_id', 'hold_hash',
            'attestation', 'evidence_file', 'evidence_sha256']) !== []) {
            throw new LogicException('Withdrawal accepts only exact hold identity and withdrawal evidence, not repair or test claims.');
        }
        $holdId = $request['hold_id'] ?? null;
        if (! is_int($holdId) || $holdId < 1) {
            throw new LogicException('Pin the exact imported hold to withdraw.');
        }
        $hold = TaskMainHold::query()->findOrFail($holdId);
        $this->assertHold($hold, $repository);
        if (($request['hold_hash'] ?? null) !== $hold->evidence_hash) {
            throw new LogicException('Pin the exact imported hold evidence hash to withdraw.');
        }
        $record = ['resolution' => 'withdrawn', 'hold_id' => $hold->id, 'hold_hash' => $hold->evidence_hash,
            'repository' => $repository, 'observed_main' => $main, 'attestation' => $evidence];
        if ($hold->clearance !== null && ! $this->payloads->matches($hold->clearance, $record)) {
            throw new LogicException('An existing main hold resolution is immutable.');
        }
        if ($apply && $hold->clearance === null) {
            $hold->update(['clearance' => $record]);
        }

        return ['applied' => $apply, 'action' => 'withdraw', 'hold' => $hold->toArray(), 'attestation' => $record];
    }

    /** Caller holds the same project lock as hold mutations.
     * @return array<string,mixed>
     */
    public function guardMerge(TaskLanding $landing): array
    {
        $workspace = $this->context->guard($landing);
        $main = $this->repository->main($this->context->configuration($workspace));
        $holds = TaskMainHold::query()->where('project_id', 'orbit')->whereNull('clearance')->orderBy('id')->get();
        $scope = $this->openScope($workspace->repository);
        $scopeHash = TaskLandingData::hash($scope);
        $repairs = [];
        $covered = $main->passed();
        foreach ($holds as $hold) {
            $this->assertHold($hold, $workspace->repository);
            $repair = $this->repair($hold, $landing, $scopeHash, $main->mainSha);
            if ($repair === null || ($repair['native_failures_hash'] ?? null) !== TaskLandingData::hash($main->failures)) {
                continue;
            }
            $repairs[] = ['id' => $hold->id, 'incident' => $hold->incident, 'evidence_hash' => $hold->evidence_hash,
                'repair_hash' => TaskLandingData::hash($repair)];
            $covered = true;
        }
        if ($holds->isNotEmpty() && $repairs === []) {
            throw new LogicException('An unresolved or newly changed main incident scope blocks this unrelated merge.');
        }
        if (! $covered) {
            throw new LogicException('Fresh native main failures have no exact coordinator-owned repair authorization.');
        }

        return ['main_sha' => $main->mainSha, 'native_failures_hash' => TaskLandingData::hash($main->failures),
            'native_failures' => $main->failures, 'repair_incidents' => $repairs,
            'retained_incidents' => array_values(array_filter($scope, fn (array $incident): bool => ! in_array($incident['id'], array_column($repairs, 'id'), true)))];
    }

    /** @param array<string,mixed> $request */
    private function landing(array $request): TaskLanding
    {
        $id = $request['landing_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            throw new LogicException('Pin a positive independently approved landing identifier.');
        }

        return $this->context->approved($id, TaskLandingData::text($request, 'package_hash', 64));
    }

    private function assertHold(TaskMainHold $hold, string $repository): void
    {
        if ($hold->project_id !== 'orbit' || ($hold->evidence['repository'] ?? null) !== $repository
            || TaskLandingData::hash($hold->evidence) !== $hold->evidence_hash || ! array_is_list($hold->repairs)) {
            throw new LogicException('The imported main incident identity is inconsistent.');
        }
    }

    /** @return array<string,mixed>|null */
    private function repair(TaskMainHold $hold, TaskLanding $landing, ?string $scope = null, ?string $main = null): ?array
    {
        foreach (array_reverse($hold->repairs) as $repair) {
            if (($repair['landing_id'] ?? null) === $landing->id && ($repair['package_hash'] ?? null) === $landing->package_hash
                && ($repair['candidate_sha'] ?? null) === $landing->candidate_sha && ($repair['issue_id'] ?? null) === $landing->issue_id
                && ($repair['hold_hash'] ?? null) === $hold->evidence_hash
                && ($repair['review_hash'] ?? null) === TaskLandingData::hash($landing->review_result)
                && ($main === null || ($repair['observed_main'] ?? null) === $main)
                && ($scope === null || ($repair['incident_scope_hash'] ?? null) === $scope)) {
                return $repair;
            }
        }

        return null;
    }

    /** @return list<array{id:int,evidence_hash:string}> */
    private function openScope(string $repository): array
    {
        $scope = [];
        foreach (TaskMainHold::query()->where('project_id', 'orbit')->whereNull('clearance')->orderBy('id')->get() as $hold) {
            $this->assertHold($hold, $repository);
            $scope[] = ['id' => $hold->id, 'evidence_hash' => $hold->evidence_hash];
        }

        return $scope;
    }

    /** @param array<string,mixed> $request
     * @return list<string>
     */
    private function cases(array $request): array
    {
        $cases = $request['relevant_cases'] ?? null;
        if (! is_array($cases) || ! array_is_list($cases) || $cases === [] || count($cases) > 100) {
            throw new LogicException('List the actual relevant case identities for this incident.');
        }
        foreach ($cases as $case) {
            if (! is_string($case) || trim($case) === '' || strlen($case) > 1000) {
                throw new LogicException('Relevant case identities must be nonempty bounded strings.');
            }
        }
        if (count(array_unique($cases)) !== count($cases)) {
            throw new LogicException('Relevant case identities must be unique.');
        }

        return $cases;
    }

    /** Coordinator attestation, not an inference from cached test totals.
     * @param  array<string,mixed>  $request
     * @return list<array<string,mixed>>
     */
    private function checkedCases(array $request, mixed $required, string $main): array
    {
        GitObjectId::validate($main);
        $checks = $request['checks'] ?? null;
        if (! is_array($required) || $required === [] || ! is_array($checks) || ! array_is_list($checks) || count($checks) !== count($required)) {
            throw new LogicException('Clearance requires an actually executed result for every recorded relevant case.');
        }
        $seen = [];
        foreach ($checks as $value) {
            $check = TaskLandingData::object($value);
            $case = TaskLandingData::text($check, 'case', 1000);
            TaskLandingData::text($check, 'command');
            TaskLandingData::text($check, 'cwd');
            if (! in_array($case, $required, true) || in_array($case, $seen, true)
                || ($check['main_sha'] ?? null) !== $main || ($check['executed'] ?? null) !== true
                || ($check['exit_code'] ?? null) !== 0 || ($check['cached'] ?? null) !== false
                || ($check['selected'] ?? null) !== true) {
                throw new LogicException('Cached, skipped, zero-selected, failed or wrong-main results cannot clear a hold.');
            }
            $seen[] = $case;
        }

        return array_map(TaskLandingData::object(...), $checks);
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function evidence(array $request): array
    {
        $summary = TaskLandingData::text($request, 'attestation', 10_000);
        $file = TaskLandingData::text($request, 'evidence_file');
        $sha = TaskLandingData::text($request, 'evidence_sha256', 64);
        $contents = TaskLandingData::file($file);
        if (! hash_equals($sha, hash('sha256', $contents))) {
            throw new LogicException('The coordinator evidence file changed.');
        }

        return ['summary' => $summary, 'file' => $file, 'sha256' => $sha, 'contents' => $contents];
    }
}
