<?php

declare(strict_types=1);

namespace App\Tasks\Completion;

use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Models\TaskMainHold;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Closeout\TaskCloseoutGitHub;
use App\Tasks\Closeout\TaskCloseoutRepository;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingEvidence;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Orbit\ReacquireTaskSnapshot;
use App\Tasks\Runtime\TaskProcessEnvironment;
use App\Tasks\TaskPayload;
use Illuminate\Support\Facades\Process;
use LogicException;
use ReflectionClass;

final readonly class TaskCompletionEvidence
{
    public function __construct(private TaskCloseoutContext $context, private TaskLandingEvidence $acceptance,
        private TaskCloseoutGitHub $github, private TaskCloseoutRepository $repository, private TaskCompletionIssue $issues,
        private OrbitIssueSnapshotFactory $snapshots, private TaskPayload $payloads,
        private ReacquireTaskSnapshot $proof) {}

    /** Stable across normal main movement and a later removal of the accepted worktree.
     * @return array<string,mixed>
     */
    public function input(TaskLanding $landing): array
    {
        return ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'artifact_sha' => $landing->artifact_sha, 'issue_id' => $landing->issue_id,
            'github_identity' => $this->github->identityHash(), 'completion_identity' => $this->issues->identityHash()];
    }

    /** Full provenance is required before a first write; retained admission owns subsequent reconciliation.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function admission(TaskLanding $landing, array $input): array
    {
        $existing = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'completion-admission')->first();
        $admission = $existing === null || ($existing->state === 'prepared' && $existing->result === null)
            ? null : $this->operation($landing, 'completion-admission');
        $linear = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'linear-completion')->first();
        $resume = $linear !== null && in_array($linear->state, ['intended', 'unknown', 'completed'], true);
        if (! $resume) {
            $this->context->approved($landing->id, TaskLandingData::text($input, 'package_hash'));
        } elseif ($admission === null) {
            throw new LogicException('Completion reconciliation requires the retained full-provenance admission.');
        }
        $workspace = $landing->workspace()->firstOrFail();
        $database = $this->acceptance->database($workspace, $landing->request);
        $binding = ['landing_hash' => TaskLandingData::hash($landing->fresh()?->toArray() ?? []),
            'database_hash' => TaskLandingData::hash($database)];
        $proof = $this->proofEvidence($landing);
        if ($proof !== null) {
            $binding['proof_closeout_hash'] = TaskLandingData::hash($proof);
        }
        if ($admission !== null) {
            if (! $this->payloads->matches($admission->input, $input)
                || ! $this->payloads->matches(TaskLandingData::object($admission->result['binding'] ?? null), $binding)
                || ($admission->result['full_provenance_guard'] ?? null) !== 'passed'
                || preg_match('/\\A[a-f0-9]{64}\\z/', TaskLandingData::text($admission->result ?? [], 'acceptance_guard_sha256')) !== 1) {
                throw new LogicException('The admitted package, independent review, or accepted database evidence changed.');
            }

            return $admission->result ?? throw new LogicException('Missing completion admission result.');
        }
        $source = (new ReflectionClass($this->acceptance))->getFileName();
        if (! is_string($source) || ! is_string($hash = hash_file('sha256', $source))) {
            throw new LogicException('The full acceptance guard implementation cannot be identified.');
        }

        return ['binding' => $binding, 'acceptance_guard_sha256' => $hash, 'full_provenance_guard' => 'passed'];
    }

    /** Fresh observations do not require active Linear state, a live agent, or worktree files.
     * @return array<string,mixed>
     */
    public function observe(TaskLanding $landing): array
    {
        $publication = $this->operation($landing, 'publication');
        $approval = $this->operation($landing, 'approval');
        $merge = $this->operation($landing, 'merge');
        $release = $this->operation($landing, 'release');
        $pr = $publication->result ?? throw new LogicException('Missing publication.');
        $merged = $merge->result ?? throw new LogicException('Missing merged result.');
        foreach ([$publication, $approval, $merge, $release] as $operation) {
            if (($operation->input['identity_hash'] ?? null) !== $this->github->identityHash()
                || ($operation->input['candidate_sha'] ?? null) !== $landing->candidate_sha) {
                throw new LogicException('The retained closeout identity or candidate changed.');
            }
        }
        if (($pr['candidate_sha'] ?? null) !== $landing->candidate_sha
            || ($merged['candidate_sha'] ?? null) !== $landing->candidate_sha || ($merged['url'] ?? null) !== ($pr['url'] ?? null)
            || ($release->result['released'] ?? null) !== true || ($release->result['url'] ?? null) !== ($pr['url'] ?? null)
            || ($approval->result['review_body_hash'] ?? null) !== hash('sha256', $this->context->approvalMarker($landing))) {
            throw new LogicException('The retained publication, approval, merge or release does not match this exact package.');
        }
        $this->same($pr, $this->github->publication($landing), 'published pull request');
        $this->same($merged, $this->github->merged($landing, $pr), 'merged pull request');
        $mergeSha = TaskLandingData::text($merged, 'merge_sha');
        GitObjectId::validate($mergeSha);
        $workspace = $landing->workspace()->firstOrFail();
        $flow = OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? 'discovery';
        $verified = null;
        foreach (TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'like', 'verify:%')->orderBy('id')->get() as $candidate) {
            $operation = $this->operation($landing, $candidate->operation);
            $lineage = TaskLandingData::object($operation->result['lineage'] ?? null);
            if (($lineage['flow'] ?? null) === $flow && ($lineage['candidate'] ?? null) === $landing->candidate_sha
                && ($lineage['merge'] ?? null) === $mergeSha) {
                GitObjectId::validate(TaskLandingData::text($lineage, 'tree'));
                $verified = $operation;
                break;
            }
        }
        if ($verified === null) {
            throw new LogicException('Retain exact native '.$flow.' merge verification before delivery completion.');
        }
        $proof = $this->proofEvidence($landing);
        if ($proof !== null && ($release->input['proof_completion_hash'] ?? null) !== TaskLandingData::hash($proof)) {
            throw new LogicException('The proof reservation release must bind the exact completed native closeout evidence.');
        }
        $configuration = $this->context->configuration($workspace);
        $this->artifact($configuration->repository, $landing);
        $main = $this->repository->main($configuration);
        if (! $this->repository->contains($configuration, $mergeSha, $main->mainSha)) {
            throw new LogicException('Current authoritative main does not contain the recorded reviewed merge.');
        }
        $reservation = $this->issues->reservation($landing, $pr);
        if (! in_array($reservation['status'] ?? null, ['idle', 'foreign'], true)) {
            throw new LogicException('This feature still owns or has reacquired its merge reservation.');
        }
        $issue = $this->issue($landing, $pr);
        $holds = [];
        $proofs = [];
        foreach (TaskMainHold::query()->where('project_id', 'orbit')->orderBy('id')->get() as $hold) {
            if ($hold->evidence_hash !== TaskLandingData::hash($hold->evidence)
                || ($hold->evidence['repository'] ?? null) !== $configuration->repository) {
                throw new LogicException('The current main incident evidence is inconsistent.');
            }
            if ($hold->clearance === null) {
                $holds[] = ['id' => $hold->id, 'incident' => $hold->incident, 'evidence_hash' => $hold->evidence_hash];
            }
            foreach ($hold->repairs as $repair) {
                if (($repair['landing_id'] ?? null) === $landing->id) {
                    $proofs[] = $this->repairProof($landing, $hold, $mergeSha, $main->mainSha);
                    break;
                }
            }
        }

        return [...$issue, 'publication' => $pr, 'merge' => $merged,
            'verification_operation_id' => $verified->id, 'verification_hash' => TaskLandingData::hash($verified->result),
            'main_sha' => $main->mainSha, 'native_failures' => $main->failures,
            'reservation' => $reservation, 'repair_proofs' => $proofs, 'open_main_incidents' => $holds,
            ...($proof === null ? [] : ['native_proof_closeout' => $proof]),
            'operational_closeout' => ['primary' => 'not_performed', 'maintenance' => 'coordinator_owned',
                'sessions' => 'not_performed', 'resources' => 'not_performed', 'worktree' => 'not_performed']];
    }

    /** @return array<string,mixed>|null */
    private function proofEvidence(TaskLanding $landing): ?array
    {
        $profile = OrbitTaskProfile::forWorkspace($landing->workspace()->firstOrFail());
        $proof = ($profile['flow'] ?? null) === 'proof';
        if (($landing->package['schema'] ?? null) !== ($proof ? 2 : 1)) {
            throw new LogicException('Completion must retain the admitted flow and its original package schema.');
        }

        return $proof ? $this->proof->completionEvidence($landing) : null;
    }

    /** @param array<string,mixed> $pr
     * @return array<string,mixed>
     */
    public function issue(TaskLanding $landing, array $pr): array
    {
        $issue = $this->issues->read($landing);
        $payload = $issue->payload;
        $expected = TaskLandingData::object($landing->inputs['issue'] ?? null);
        $state = TaskLandingData::object($payload['state'] ?? null);
        $team = TaskLandingData::object($payload['team'] ?? null);
        $nodes = TaskLandingData::object($team['states'] ?? null)['nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new LogicException('The completion issue team states are incomplete.');
        }
        $done = array_values(array_filter($nodes, fn (mixed $item): bool => is_array($item) && ($item['name'] ?? null) === 'Done'));
        $stateId = count($done) === 1 ? ($done[0]['id'] ?? null) : null;
        if (! is_string($stateId) || preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\z/', $stateId) !== 1) {
            throw new LogicException('The exact Orbit team must provide one valid Done state.');
        }
        $nick = config('commander.hermes.nick_linear_user_id');
        $permittedOwner = ($payload['assignee'] ?? null) === null || (is_string($nick) && $nick !== ''
            && (TaskLandingData::object($payload['assignee'])['id'] ?? null) === $nick);
        $active = in_array($state['name'] ?? null, ['In Progress', 'In Review'], true) && ($state['type'] ?? null) === 'started'
            && $permittedOwner;
        $completed = ($state['id'] ?? null) === $stateId && ($state['name'] ?? null) === 'Done'
            && ($state['type'] ?? null) === 'completed' && $permittedOwner;
        if ($issue->issueId !== $landing->issue_id || $issue->issueKey !== ($expected['identifier'] ?? null)
            || ($payload['id'] ?? null) !== $landing->issue_id || ($payload['identifier'] ?? null) !== $issue->issueKey
            || ($team['id'] ?? null) !== config('commander.hermes.orbit_linear_team_id')
            || ($team['id'] ?? null) !== ($expected['team_id'] ?? null)
            || ! array_key_exists('delegate', $payload) || $payload['delegate'] !== null
            || ! array_key_exists('assignee', $payload) || (! $active && ! $completed)
            || ! $this->snapshots->matchesExpectedContract($issue, TaskLandingData::text($expected, 'contract_hash'),
                TaskLandingData::text($pr, 'url'), TaskLandingData::text($landing->package ?? [], 'title'))) {
            throw new LogicException('The Tasks completion issue identity, undelegated ownership, state or approved contract changed.');
        }
        $collections = [];
        foreach (['labels', 'attachments', 'children', 'inverseRelations'] as $key) {
            $value = TaskLandingData::object($payload[$key] ?? null);
            if (! is_array($value['nodes'] ?? null) || ! array_is_list($value['nodes']) || count($value['nodes']) > 100
                || (TaskLandingData::object($value['pageInfo'] ?? null)['hasNextPage'] ?? null) !== false) {
                throw new LogicException('The Tasks completion issue has an incomplete collection.');
            }
            $collections[$key] = array_map(TaskLandingData::object(...), $value['nodes']);
        }
        if ($collections['children'] !== [] || str_contains(TaskLandingData::text($payload, 'description'), '## Readiness')) {
            throw new LogicException('The Tasks completion issue acquired a readiness or child hold.');
        }
        foreach ($collections['labels'] as $label) {
            $name = strtolower(TaskLandingData::text($label, 'name'));
            if (($name !== 'controller:tasks' && str_starts_with($name, 'controller:')) || $name === 'maintenance:monorepo') {
                throw new LogicException('The Tasks completion issue has a foreign controller.');
            }
        }
        foreach ($collections['inverseRelations'] as $relation) {
            $related = TaskLandingData::object($relation['issue'] ?? null);
            if (($relation['type'] ?? null) === 'blocks'
                && ! in_array(TaskLandingData::object($related['state'] ?? null)['type'] ?? null, ['completed', 'canceled'], true)) {
                throw new LogicException('The Tasks completion issue has an unresolved blocker.');
            }
        }
        $identity = ['issue_id' => $landing->issue_id, 'issue_key' => $issue->issueKey, 'team_id' => $team['id'],
            'contract_hash' => $expected['contract_hash'], 'state' => ['id' => $stateId, 'name' => 'Done', 'type' => 'completed'],
            'assignee' => $payload['assignee'], 'delegate' => null];

        return ['done_state_id' => $stateId, 'completed_issue' => $completed ? $identity : null,
            'issue_observation' => ['state' => $state, 'assignee' => $payload['assignee'], 'delegate' => null,
                'contract_hash' => $issue->contractHash, 'updated_at' => TaskLandingData::text($payload, 'updatedAt')]];
    }

    /** @return array<string,mixed> */
    private function repairProof(TaskLanding $landing, TaskMainHold $hold, string $merge, string $main): array
    {
        $candidates = [];
        if ($hold->clearance !== null) {
            $candidates[] = ['source' => 'clearance', 'record' => $hold->clearance];
        }
        foreach (TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'main-proof:'.$hold->id.':%')->orderByDesc('id')->get() as $operation) {
            $record = $this->operation($landing, $operation->operation);
            if (($record->input['hold_id'] ?? null) !== $hold->id || ($record->input['hold_hash'] ?? null) !== $hold->evidence_hash) {
                throw new LogicException('A repair case observation differs from its incident.');
            }
            $candidates[] = ['source' => $operation->operation, 'record' => $record->result ?? []];
        }
        foreach ($candidates as $candidate) {
            $record = $candidate['record'];
            if (($record['landing_id'] ?? null) !== $landing->id || ($record['issue_id'] ?? null) !== $landing->issue_id
                || ($record['package_hash'] ?? null) !== $landing->package_hash || ($record['candidate_sha'] ?? null) !== $landing->candidate_sha
                || ($record['review_hash'] ?? null) !== TaskLandingData::hash($landing->review_result)
                || ($record['hold_hash'] ?? null) !== $hold->evidence_hash || ($record['merge_sha'] ?? null) !== $merge
                || ($record['verification_exit_code'] ?? null) !== 0) {
                continue;
            }
            $provedMain = TaskLandingData::text($record, 'observed_main');
            $configuration = $this->context->configuration($landing->workspace()->firstOrFail());
            if (! $this->repository->contains($configuration, $merge, $provedMain)
                || ! $this->repository->contains($configuration, $provedMain, $main)) {
                continue;
            }
            $required = $hold->evidence['cases'] ?? null;
            $checks = $record['checks'] ?? null;
            if (! is_array($required) || ! array_is_list($required) || $required === []
                || ! is_array($checks) || ! array_is_list($checks) || count($checks) !== count($required)) {
                continue;
            }
            $seen = [];
            foreach ($checks as $value) {
                $check = TaskLandingData::object($value);
                $case = TaskLandingData::text($check, 'case');
                TaskLandingData::text($check, 'command');
                TaskLandingData::text($check, 'cwd');
                if (! in_array($case, $required, true) || in_array($case, $seen, true)
                    || ($check['main_sha'] ?? null) !== $provedMain || ($check['executed'] ?? null) !== true
                    || ($check['selected'] ?? null) !== true || ($check['cached'] ?? null) !== false || ($check['exit_code'] ?? null) !== 0) {
                    continue 2;
                }
                $seen[] = $case;
            }
            $attestation = TaskLandingData::object($record['attestation'] ?? null);
            $contents = TaskLandingData::text($attestation, 'contents');
            if (($attestation['sha256'] ?? null) !== hash('sha256', $contents)) {
                throw new LogicException('The retained repair acceptance evidence changed.');
            }

            return ['hold_id' => $hold->id, 'hold_hash' => $hold->evidence_hash, 'source' => $candidate['source'],
                'record_hash' => TaskLandingData::hash($record), 'main_sha' => $provedMain, 'verification_exit_code' => 0];
        }

        throw new LogicException('A repaired main incident requires retained actual case proof from an overall zero-exit verification; red observations do not complete acceptance.');
    }

    public function operation(TaskLanding $landing, string $name): TaskCloseoutOperation
    {
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $name)->first();
        if ($operation === null || $operation->state !== 'completed' || $operation->result === null
            || $operation->package_hash !== $landing->package_hash || ($operation->input['package_hash'] ?? null) !== $landing->package_hash
            || $operation->input_hash !== TaskLandingData::hash($operation->input)) {
            throw new LogicException('Retain the exact completed '.$name.' operation before delivery completion.');
        }

        return $operation;
    }

    private function artifact(string $repository, TaskLanding $landing): void
    {
        if (realpath($repository) !== $repository) {
            throw new LogicException('The canonical completion repository is unavailable.');
        }
        $ref = 'refs/tags/loop/'.strtolower(TaskLandingData::text(TaskLandingData::object($landing->inputs['issue'] ?? null), 'identifier')).'/'.$landing->candidate_sha;
        if ($ref !== $landing->artifact_ref || ! is_string($landing->artifact_sha)) {
            throw new LogicException('The retained completion artifact identity changed.');
        }
        GitObjectId::validate($landing->artifact_sha);
        $git = function (array $arguments) use ($repository): string {
            $result = Process::path($repository)->env([...TaskProcessEnvironment::isolated(), 'GIT_NO_LAZY_FETCH' => '1',
                'GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0'])->timeout(30)
                ->run(['git', '--no-replace-objects', ...$arguments]);
            if ($result->failed()) {
                throw new LogicException('The retained artifact cannot be observed without mutation or missing-object fetch.');
            }

            return trim($result->output());
        };
        if (! in_array($git(['remote', 'get-url', 'origin']), ['git@github.com:nckrtl/orbit.git', 'https://github.com/nckrtl/orbit.git'], true)
            || $git(['show-ref', '--verify', $ref]) !== $landing->artifact_sha.' '.$ref
            || $git(['ls-remote', '--refs', 'origin', $ref]) !== $landing->artifact_sha."\t".$ref) {
            throw new LogicException('The local or remote retained artifact differs from the approved package.');
        }
        $git(['cat-file', '-e', $landing->artifact_sha.'^{commit}']);
    }

    /** @param array<string,mixed> $expected
     * @param  array<string,mixed>|null  $actual
     */
    private function same(array $expected, ?array $actual, string $name): void
    {
        if ($actual === null || ! $this->payloads->matches($expected, $actual)) {
            throw new LogicException('The current '.$name.' differs from the recorded reviewed delivery.');
        }
    }
}
