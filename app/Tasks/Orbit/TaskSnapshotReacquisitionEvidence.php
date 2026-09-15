<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Delivery\Data\OrbitProofCloseout;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\HerdrTaskLandingReviewer;
use App\Tasks\Landing\TaskLandingData as Data;
use LogicException;

/** Reads retained evidence only. Deleted worktrees and later main/generation changes are not new acceptance inputs. */
final class TaskSnapshotReacquisitionEvidence
{
    public const array STAGES = ['snapshot-prepare', 'snapshot-bootstrap', 'snapshot-discover',
        'snapshot-observe-discovery', 'snapshot-publish', 'snapshot-prove', 'snapshot-capture',
        'snapshot-observe-proof', 'snapshot-review', 'snapshot-release-proof', 'snapshot-release-discovery',
        'snapshot-remove-worktree', 'snapshot-remove-branch'];

    /** @return array<string,mixed> */
    public function installation(TaskLanding $landing): array
    {
        $workspace = $landing->workspace()->firstOrFail();
        if ((OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || ($landing->package['schema'] ?? null) !== 2 || $landing->state !== 'approved'
            || Data::hash($landing->package) !== $landing->package_hash
            || ($landing->package['candidate_sha'] ?? null) !== $landing->candidate_sha
            || ($landing->package['artifact_sha'] ?? null) !== $landing->artifact_sha) {
            throw new LogicException('Retained proof completion requires its explicitly admitted approved package.');
        }
        $merge = $this->operation($landing, 'merge');
        $closed = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'proof-closeout:%')->orderByDesc('id')->first();
        if ($closed === null) {
            throw new LogicException('Complete native proof closeout before postinstall verification or release.');
        }
        $this->assertOperation($landing, $closed);
        $record = Data::object($closed->result);
        $native = OrbitProofCloseout::fromArray($record);
        $proof = Data::object($workspace->final_check['native_proof'] ?? null);
        $expected = ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'artifact_sha' => $landing->artifact_sha, 'merge_sha' => $merge->result['merge_sha'] ?? null,
            'main_sha' => $native->mainSha];
        if (! $native->complete() || $native->issueKey !== $workspace->source_key
            || $native->attemptId !== ($proof['attempt_id'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', Data::text($proof, 'capture_fingerprint')) !== 1
            || $native->candidateSha !== $landing->candidate_sha || $native->artifactSha !== $landing->artifact_sha
            || $native->mergeCommitSha !== ($merge->result['merge_sha'] ?? null)
            || ($merge->result['candidate_sha'] ?? null) !== $landing->candidate_sha
            || array_intersect_key($closed->input, $expected) !== $expected
            || array_diff(array_keys($closed->input), [...array_keys($expected), 'before']) !== []) {
            throw new LogicException('The completed native proof closeout does not match its accepted C/A/merge/M/attempt.');
        }
        $verified = false;
        foreach (TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'verify:%')->where('state', 'completed')->get() as $verification) {
            $this->assertOperation($landing, $verification);
            $value = Data::object($verification->result);
            $lineage = Data::object($value['lineage'] ?? null);
            if ($verification->operation === 'verify:'.Data::hash($value) && ($value['main_sha'] ?? null) === $native->mainSha
                && ($lineage['flow'] ?? null) === 'proof' && ($lineage['candidate'] ?? null) === $landing->candidate_sha
                && ($lineage['merge'] ?? null) === $native->mergeCommitSha && $verification->id < $closed->id) {
                $verified = true;
            }
        }
        $repository = $workspace->repository;
        if (! $verified || $repository === '/' || realpath($repository) !== $repository
            || ($workspace->configuration['repository'] ?? null) !== $repository
            || preg_match('/\AORB-[1-9][0-9]*\z/D', $workspace->source_key) !== 1) {
            throw new LogicException('Retain the exact proof merge verification and canonical primary archive.');
        }
        $archive = Data::object(json_decode(Data::file($repository.'/.e2e/proof-closeout/'.$native->issueKey.'/'.$native->attemptId.'.json'), true, flags: JSON_THROW_ON_ERROR));
        if (OrbitProofCloseout::fromArray($archive)->toArray() !== $record) {
            throw new LogicException('The retained native proof archive differs from the completed operation.');
        }

        return ['schema' => 1, ...$expected, 'native_proof_closeout' => $record];
    }

    /** @param array<string,mixed> $installation
     * @return array<string,mixed>
     */
    public function preparation(TaskLanding $landing, array $installation): array
    {
        $operation = $this->operation($landing, 'snapshot-prepare');
        $input = $operation->input;
        $request = Data::object($input['request'] ?? null);
        $generation = Data::object($input['generation'] ?? null);
        $workspace = $landing->workspace()->firstOrFail();
        $identity = ['schema' => 1, 'issue' => $workspace->source_key, 'repository' => $workspace->repository,
            'worktree_root' => $workspace->configuration['worktree_root'] ?? null,
            'accepted_worktree' => $workspace->worktree, 'accepted_candidate' => $landing->candidate_sha,
            'accepted_artifact' => $landing->artifact_sha, 'merged_main' => $installation['main_sha']];
        $branch = 'orb-91-postinstall-'.substr(Data::text($installation, 'main_sha'), 0, 12);
        if ((OrbitTaskProfile::forWorkspace($workspace)['snapshot_replacement'] ?? false) !== true
            || $workspace->source_key !== 'ORB-91' || array_intersect_key($request, $identity) !== $identity
            || ($request['validation_branch'] ?? null) !== $branch
            || ($request['validation_worktree'] ?? null) !== Data::text($identity, 'worktree_root').'/'.$branch
            || ($generation['id'] ?? null) !== (Data::object($installation['native_proof_closeout'])['generation_id'] ?? null)
            || ($generation['main_sha'] ?? null) !== $installation['main_sha']
            || $input !== $this->input($landing, $installation, $request, $generation, null)) {
            throw new LogicException('Postinstall preparation differs from its installed generation or approved C/A/M inputs.');
        }
        $this->receipt('snapshot-prepare', Data::object($operation->result), $request, $generation, [], null);

        return ['request' => $request, 'generation' => $generation, 'operation' => $operation];
    }

    /** @param array<string,mixed> $installation
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $generation
     * @param  array<string,mixed>|null  $previous
     * @return array<string,mixed>
     */
    public function input(TaskLanding $landing, array $installation, array $request, array $generation, ?array $previous): array
    {
        return ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'installation_hash' => Data::hash($installation), 'request' => $request, 'generation' => $generation, 'previous' => $previous];
    }

    /** @return array<string,mixed> */
    public function pointer(TaskCloseoutOperation $operation): array
    {
        return ['id' => $operation->id, 'operation' => $operation->operation, 'input_hash' => $operation->input_hash,
            'result_hash' => Data::hash($operation->result)];
    }

    /** Retain each exact native receipt, including concrete stdout, for independent review.
     * @param  array<string,mixed>  $installation
     * @return array<string,mixed>
     */
    public function through(TaskLanding $landing, array $installation, string $last): array
    {
        $prepared = $this->preparation($landing, $installation);
        $request = Data::object($prepared['request']);
        $generation = Data::object($prepared['generation']);
        $previous = $this->operation($landing, 'snapshot-prepare');
        $records = ['snapshot-prepare' => Data::object($previous->result)];
        $pointers = ['snapshot-prepare' => $this->pointer($previous)];
        foreach (array_slice(self::STAGES, 1) as $stage) {
            if ($previous->operation === $last) {
                break;
            }
            if ($stage === 'snapshot-review') {
                $bundle = $this->bundle($installation, $request, $generation, $records, $pointers);
                $operation = $this->review($landing, $bundle);
            } else {
                $operation = $this->operation($landing, $stage);
                $expected = $this->input($landing, $installation, $request, $generation, $this->pointer($previous));
                if ($operation->input !== $expected) {
                    throw new LogicException('The postinstall stage differs from its exact predecessor and frozen request.');
                }
                $this->receipt($stage, Data::object($operation->result), $request, $generation, $records, $previous);
            }
            if ($operation->id <= $previous->id) {
                throw new LogicException('Postinstall stage order changed.');
            }
            $records[$stage] = Data::object($operation->result);
            $pointers[$stage] = $this->pointer($operation);
            $previous = $operation;
            if ($stage === $last) {
                break;
            }
        }

        return ['request' => $request, 'generation' => $generation, 'records' => $records,
            'pointers' => $pointers, 'previous' => $this->pointer($previous)];
    }

    /** @param array<string,mixed> $installation
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $generation
     * @param  array<string,mixed>  $records
     * @param  array<string,mixed>  $pointers
     * @return array<string,mixed>
     */
    public function bundle(array $installation, array $request, array $generation, array $records, array $pointers): array
    {
        return ['schema' => 1, 'installation' => $installation, 'request' => $request, 'generation' => $generation,
            'records' => $records, 'operations' => $pointers];
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $generation
     * @return array<string,mixed>
     */
    public function nativeRequest(array $request, array $generation): array
    {
        return [...array_intersect_key($request, array_flip(['issue', 'repository', 'accepted_worktree',
            'accepted_candidate', 'validation_worktree', 'merged_main', 'files', 'discovery_action', 'proof_action'])), 'generation' => $generation];
    }

    /** @param array<string,mixed> $records
     * @return array<string,mixed>
     */
    public function nativeEvidence(array $records): array
    {
        $evidence = [];
        foreach (['discovery' => 'snapshot-discover', 'proof' => 'snapshot-prove'] as $purpose => $stage) {
            if (isset($records[$stage])) {
                $evidence[$purpose.'_attempt'] = Data::text(Data::object(Data::object($records[$stage])['result'] ?? null), 'attempt_id');
            }
        }
        if (isset($records['snapshot-capture'])) {
            $evidence['capture_fingerprint'] = Data::text(Data::object(Data::object($records['snapshot-capture'])['result'] ?? null), 'fingerprint');
        }
        if (isset($records['snapshot-review'])) {
            $evidence['acceptance_hash'] = Data::hash($records['snapshot-review']);
        }

        return $evidence;
    }

    /** @param array<string,mixed> $result
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $generation
     * @param  array<string,mixed>  $records
     */
    public function receipt(string $stage, array $result, array $request, array $generation, array $records, ?TaskCloseoutOperation $previous): void
    {
        $states = ['snapshot-prepare' => 'prepared', 'snapshot-bootstrap' => 'ready',
            'snapshot-remove-worktree' => 'worktree_removed', 'snapshot-remove-branch' => 'removed'];
        if (isset($states[$stage])) {
            $exit = $stage === 'snapshot-bootstrap' ? Data::object($result['exit'] ?? null) : [];
            if (($result['state'] ?? null) !== $states[$stage] || ($result['request_hash'] ?? null) !== Data::hash($request)
                || ($stage === 'snapshot-bootstrap' && (($exit['exit_code'] ?? null) !== 0
                    || ($exit['request_hash'] ?? null) !== Data::hash($request) || ! is_array($result['snapshot'] ?? null)))) {
                throw new LogicException('The exact postinstall preparation/bootstrap/cleanup is incomplete.');
            }

            return;
        }
        $operation = substr($stage, strlen('snapshot-'));
        $native = $this->nativeRequest($request, $generation);
        $after = Data::object($result['after'] ?? null);
        $status = Data::object($after['status'] ?? null);
        $value = Data::object($result['result'] ?? null);
        $before = Data::object($result['before'] ?? null);
        if (($result['operation'] ?? null) !== $operation || ($result['request_hash'] ?? null) !== Data::hash($native)
            || ($after['request_hash'] ?? null) !== Data::hash($native) || ($status['candidate_attempt'] ?? null) !== null) {
            throw new LogicException('The retained native receipt belongs to another operation or request.');
        }
        $priorNative = null;
        foreach ($records as $record) {
            if (is_array($record) && isset($record['after'])) {
                $priorNative = Data::object($record['after']);
            }
        }
        if ($priorNative !== null && ($before !== ($priorNative['status'] ?? null)
            || ($after['plan_sha256'] ?? null) !== ($priorNative['plan_sha256'] ?? null)
            || ($operation !== 'publish' && ($after['artifact'] ?? null) !== ($priorNative['artifact'] ?? null)))) {
            throw new LogicException('The exact native state changed between postinstall stages.');
        }
        $evidence = $this->nativeEvidence($records);
        foreach (['discovery', 'proof'] as $purpose) {
            $attempt = $status[$purpose.'_attempt'] ?? null;
            $topology = $status[$purpose] ?? null;
            if ($attempt === null && $topology === null) {
                continue;
            }
            $attempt = Data::object($attempt);
            $topology = Data::object($topology);
            $source = Data::object($topology['source'] ?? null);
            $construction = Data::object($topology['construction'] ?? null);
            $verification = Data::object($topology['verification'] ?? null);
            if (($attempt['issue'] ?? null) !== $request['issue'] || ($attempt['purpose'] ?? null) !== $purpose
                || preg_match('/\A[0-9a-f]{32}\z/D', Data::text($attempt, 'attempt_id')) !== 1
                || ($topology['attempt_id'] ?? null) !== $attempt['attempt_id'] || ($topology['generation'] ?? null) !== $generation
                || ($source['host_sha'] ?? null) !== $request['merged_main']
                || ($source['guest_sha'] ?? null) !== $request['merged_main'] || ($source['dirty'] ?? null) !== false
                || ($construction['source_generation'] ?? null) !== $generation['id']
                || ($construction['snapshot_replacement'] ?? null) !== false
                || ($construction['extension'] ?? null) !== null || ($verification['passed'] ?? null) !== true
                || (isset($evidence[$purpose.'_attempt']) && $attempt['attempt_id'] !== $evidence[$purpose.'_attempt'])) {
                throw new LogicException('The retained ordinary topology differs from the exact installed generation and attempt.');
            }
        }
        if (in_array($operation, ['discover', 'prove'], true)) {
            $purpose = $operation === 'discover' ? 'discovery' : 'proof';
            if (($value['issue'] ?? null) !== $request['issue'] || ($value['attempt_id'] ?? null) !== (Data::object($status[$purpose.'_attempt'] ?? null)['attempt_id'] ?? null)
                || ! isset($status[$purpose]) || ($before[$purpose.'_attempt'] ?? null) !== null
                || ($operation === 'prove' && (($value['status'] ?? null) !== 'proved' || $value !== ($status['proof_result'] ?? null)
                    || ($value['candidate_sha'] ?? null) !== $request['merged_main'] || ($value['plan_sha256'] ?? null) !== ($after['plan_sha256'] ?? null)))) {
                throw new LogicException('The retained acquisition is incomplete.');
            }
        } elseif ($operation === 'publish') {
            if ($value !== ($after['artifact'] ?? null) || ($value['candidate'] ?? null) !== $request['merged_main']
                || ($value['ref'] ?? null) !== 'refs/tags/loop/orb-91/'.Data::text($request, 'merged_main')
                || preg_match('/\A[0-9a-f]{40}\z/D', Data::text($value, 'artifacts')) !== 1) {
                throw new LogicException('The retained immutable M artifact is incomplete.');
            }
        } elseif ($operation === 'capture') {
            if ($value !== ($status['capture'] ?? null) || ($value['candidate_sha'] ?? null) !== $request['merged_main']
                || ($value['attempt_id'] ?? null) !== ($evidence['proof_attempt'] ?? null)
                || ($value['plan_sha256'] ?? null) !== ($after['plan_sha256'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/D', Data::text($value, 'fingerprint')) !== 1) {
                throw new LogicException('The retained auxiliary capture is incomplete.');
            }
        } elseif (str_starts_with($operation, 'observe-')) {
            if (($value['state'] ?? null) !== 'executed' || ($value['exit_code'] ?? null) !== 0 || ! is_string($value['stderr'] ?? null)) {
                throw new LogicException('The retained concrete observation failed.');
            }
            $stdout = Data::text($value, 'stdout', 16_777_216);
            if ($operation === 'observe-proof') {
                $review = Data::object($status['review_record'] ?? null);
                $capture = Data::object($status['capture'] ?? null);
                $requestedAction = Data::object($request['proof_action'] ?? null);
                $actions = $review['actions'] ?? null;
                $action = is_array($actions) && count($actions) === 1 ? Data::object($actions[0]) : [];
                if (($review['candidate_sha'] ?? null) !== $request['merged_main'] || ($review['issue'] ?? null) !== $request['issue']
                    || ($review['attempt_id'] ?? null) !== ($evidence['proof_attempt'] ?? null)
                    || ($capture['fingerprint'] ?? null) !== ($evidence['capture_fingerprint'] ?? null)
                    || ($action['id'] ?? null) !== 'snapshot-candidate-reacquire' || ($action['required'] ?? null) !== true
                    || ($action['status'] ?? null) !== 'passed' || ($action['exit_code'] ?? null) !== 0 || ($action['type'] ?? null) !== 'exec'
                    || ($action['argv'] ?? null) !== ($requestedAction['argv'] ?? null)
                    || ($action['node'] ?? null) !== ($requestedAction['node'] ?? null)
                    || ($action['stdout'] ?? null) !== (strlen($stdout) <= 4096 ? $stdout : mb_strcut($stdout, -4096))) {
                    throw new LogicException('Retain the exact required native proof observation, not only an exit count.');
                }
            }
        } elseif (str_starts_with($operation, 'release-')) {
            $purpose = $operation === 'release-proof' ? 'proof' : 'discovery';
            $attempts = $value['attempts'] ?? null;
            $receipt = $purpose === 'proof' ? $value : Data::object(is_array($attempts) && count($attempts) === 1 ? $attempts[0] : null);
            if (! isset($evidence['acceptance_hash']) || ($value['state'] ?? null) !== 'released'
                || ($value['issue'] ?? null) !== $request['issue'] || ($receipt['purpose'] ?? null) !== $purpose
                || ($receipt['attempt_id'] ?? null) !== ($evidence[$purpose.'_attempt'] ?? null)
                || ($status[$purpose.'_attempt'] ?? null) !== null || ($status[$purpose] ?? null) !== null) {
                throw new LogicException('Exact independently accepted auxiliary cleanup is incomplete.');
            }
        } else {
            throw new LogicException('Unknown postinstall evidence stage.');
        }
    }

    /** @param array<string,mixed> $bundle */
    private function review(TaskLanding $landing, array $bundle): TaskCloseoutOperation
    {
        $hash = Data::hash($bundle);
        $operation = $this->operation($landing, 'snapshot-review:'.$hash);
        $latest = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'snapshot-review:%')->orderByDesc('id')->first();
        $result = Data::object($operation->result);
        $path = storage_path('app/private/task-snapshot-reviews/'.$landing->id.'/'.$hash.'.json');
        if ($latest?->id !== $operation->id || $operation->input !== ['package_hash' => $landing->package_hash,
            'candidate_sha' => $landing->candidate_sha, 'bundle_hash' => $hash, 'bundle' => $bundle]
            || ($result['verdict'] ?? null) !== 'pass' || ($result['bundle_hash'] ?? null) !== $hash
            || ($result['assignment'] ?? null) !== $operation->assignment || ($operation->preflight['bundle_path'] ?? null) !== $path
            || Data::file($path, 16_777_216) !== Data::json($bundle)
            || (fileperms($path) & 0077) !== 0 || (fileperms(dirname($path)) & 0077) !== 0) {
            throw new LogicException('Independent postinstall review has not passed the exact retained observation bundle.');
        }
        Data::text($result, 'summary');
        Data::text($result, 'evidence');
        $session = Data::object($operation->preflight['session'] ?? null);
        HerdrTaskLandingReviewer::assertIdentity($landing->review_session ?? [], $session);
        HerdrTaskLandingReviewer::assertIdentity($landing->workspace()->firstOrFail()->reviewer_session ?? [], $session);

        return $operation;
    }

    public function operation(TaskLanding $landing, string $name): TaskCloseoutOperation
    {
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $name)->first();
        if ($operation === null) {
            throw new LogicException('Missing required closeout evidence: '.$name.'.');
        }
        $this->assertOperation($landing, $operation);

        return $operation;
    }

    private function assertOperation(TaskLanding $landing, TaskCloseoutOperation $operation): void
    {
        if ($operation->state !== 'completed' || $operation->result === null
            || $operation->package_hash !== $landing->package_hash || ($operation->input['package_hash'] ?? null) !== $landing->package_hash
            || $operation->input_hash !== Data::hash($operation->input)) {
            throw new LogicException('Required closeout evidence is missing, stale or uncertain; do not replay it.');
        }
    }
}
