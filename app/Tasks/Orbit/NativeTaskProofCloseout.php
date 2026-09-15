<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Delivery\Data\OrbitProofCloseout;
use App\Models\TaskLanding;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeTaskProofCloseout
{
    /** Read-only; absence is not permission to repeat an uncertain closeout.
     * @param  array<string,mixed>  $merge
     * @return array<string,mixed>
     */
    public function inspect(TaskLanding $landing, array $merge): array
    {
        $target = $this->target($landing, $merge);
        $result = $this->run($target['repository'], [$target['script'], 'status', $target['issue'],
            '--worktree='.$target['worktree'], '--json'], 60);
        if ($result->failed()) {
            throw new LogicException('Native proof closeout status is unavailable; no mutation is authorized.');
        }
        $status = TaskLandingData::object(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));
        if (($status['issue'] ?? null) !== $target['issue'] || ($status['worktree'] ?? null) !== $target['worktree']
            || ! in_array($status['state'] ?? null, ['absent', 'captured', 'discovery', 'proof', 'discovery+proof',
                'candidate-convergence', 'discovery+candidate-convergence', 'proof+candidate-convergence',
                'discovery+proof+candidate-convergence'], true)) {
            throw new LogicException('Native proof status differs from the accepted issue, worktree, candidate or attempt.');
        }
        $capture = $status['capture'] ?? null;
        $discoveryOnly = $status['state'] === 'discovery'
            && ! array_key_exists('capture', $status) && ! array_key_exists('closeout', $status);
        if (! $discoveryOnly) {
            $capture = TaskLandingData::object($capture);
            if (($capture['issue'] ?? null) !== $target['issue'] || ($capture['attempt_id'] ?? null) !== $target['attempt']
                || ($capture['candidate_sha'] ?? null) !== $target['candidate']
                || ($capture['fingerprint'] ?? null) !== $target['capture']) {
                throw new LogicException('Native capture differs from the accepted proof.');
            }
        }
        $local = ($status['closeout'] ?? null) === null ? null : TaskLandingData::object($status['closeout']);
        $path = $target['repository'].'/.e2e/proof-closeout/'.$target['issue'].'/'.$target['attempt'].'.json';
        $archive = file_exists($path) || is_link($path)
            ? TaskLandingData::object(json_decode(TaskLandingData::file($path), true, flags: JSON_THROW_ON_ERROR)) : null;
        foreach ([$local, $archive] as $record) {
            if ($record !== null) {
                $this->binding($record, $target);
            }
        }
        if ($local !== null && ($archive === null || ! $this->forward($local, $archive))) {
            throw new LogicException('Native closeout and its retained primary archive differ without valid forward progress.');
        }
        $retained = $status['retained_topology'] ?? null;
        $hasProof = str_contains((string) $status['state'], 'proof')
            || ($status['proof_topology'] ?? null) !== null || ($status['proof_attempt_id'] ?? null) !== null;
        if (($archive['state'] ?? null) === 'complete' && ($retained !== null || $hasProof)) {
            throw new LogicException('A complete closeout cannot retain a live proof attempt.');
        }
        if ($discoveryOnly && ($archive['state'] ?? null) !== 'complete') {
            throw new LogicException('Discovery-only status requires an exact completed primary closeout archive.');
        }

        return ['closeout' => $archive, 'proof_released' => $archive !== null && $archive['state'] === 'complete'];
    }

    /** Primary archive writes precede local state writes in native closeout.
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $archive
     */
    private function forward(array $local, array $archive): bool
    {
        if ($local === $archive) {
            return true;
        }
        if ($archive['recorded_at'] < $local['recorded_at']) {
            return false;
        }
        if (in_array($local['state'], ['refresh-succeeded', 'replacement-succeeded'], true)) {
            return in_array($archive['state'], [$local['state'], 'complete'], true)
                && $local['main_sha'] === $archive['main_sha'] && $local['generation_id'] === $archive['generation_id'];
        }
        if (in_array($local['state'], ['refresh-failed', 'replacement-failed'], true)) {
            if ($local['state'] === 'replacement-failed' && $local['main_sha'] !== $archive['main_sha']) {
                return false;
            }

            return in_array($archive['state'], [$local['state'], str_replace('-failed', '-succeeded', $local['state']), 'complete'], true);
        }

        return false;
    }

    /** Caller must persist intent and reconcile status; this method never retries.
     * @param  array<string,mixed>  $merge
     * @return array<string,mixed>
     */
    public function execute(TaskLanding $landing, array $merge, string $main): array
    {
        $target = $this->target($landing, $merge);
        GitObjectId::validate($main);
        $result = $this->run($target['repository'], [$target['script'], 'closeout', $target['issue'],
            '--worktree='.$target['worktree'], '--candidate='.$target['candidate'], '--artifact='.$target['artifact'],
            '--merge='.$target['merge'], '--main-sha='.$main, '--json'], 3600);
        $record = TaskLandingData::object(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));
        $this->binding($record, $target);
        $closeout = OrbitProofCloseout::fromArray($record);
        if ($closeout->mainSha !== $main || ($result->exitCode() === 0) !== $closeout->complete()) {
            throw new LogicException('Native proof closeout returned inconsistent main or exit evidence.');
        }

        return $record;
    }

    /** @param array<string,mixed> $merge
     * @return array{repository:string,worktree:string,script:string,issue:string,attempt:string,candidate:string,artifact:string,merge:string,capture:string,replacement:bool}
     */
    private function target(TaskLanding $landing, array $merge): array
    {
        $workspace = $landing->workspace()->firstOrFail();
        $profile = TaskLandingData::object($workspace->configuration['orbit_profile'] ?? null);
        if (($profile['schema'] ?? null) !== 1 || ($profile['flow'] ?? null) !== 'proof'
            || ! is_bool($profile['snapshot_replacement'] ?? null) || count($profile) !== 3
            || $workspace->project_id !== 'orbit' || ($landing->package['schema'] ?? null) !== 2) {
            throw new LogicException('Native proof closeout requires an explicitly admitted proof package.');
        }
        $repository = $workspace->repository;
        $worktree = $workspace->worktree;
        $root = TaskLandingData::text($workspace->configuration, 'worktree_root');
        $issue = $workspace->source_key;
        $script = $repository.'/bin/e2e-topology';
        $proof = TaskLandingData::object($workspace->final_check['native_proof'] ?? null);
        $attempt = TaskLandingData::text($proof, 'attempt_id');
        $capture = TaskLandingData::text($proof, 'capture_fingerprint');
        if ($repository === '/' || realpath($repository) !== $repository || ! is_dir($repository)
            || $root === '/' || realpath($root) !== $root || realpath($worktree) !== $worktree
            || ! str_starts_with($worktree, $root.'/') || ! is_dir($worktree)
            || ! is_file($worktree.'/.git') || is_link($worktree.'/.git')
            || realpath($script) !== $script || ! is_executable($script) || is_link($script)
            || preg_match('/\AORB-[1-9][0-9]*\z/', $issue) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/', $attempt) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $capture) !== 1) {
            throw new LogicException('The canonical native proof target or retained attempt is invalid.');
        }
        $candidate = $landing->candidate_sha;
        $artifact = $landing->artifact_sha ?? throw new LogicException('Missing accepted proof artifact.');
        $mergeSha = TaskLandingData::text($merge, 'merge_sha');
        foreach ([$candidate, $artifact, $mergeSha] as $sha) {
            GitObjectId::validate($sha);
        }
        if (($merge['candidate_sha'] ?? null) !== $candidate) {
            throw new LogicException('The recorded merge names another proof candidate.');
        }

        return ['repository' => $repository, 'worktree' => $worktree, 'script' => $script, 'issue' => $issue,
            'attempt' => $attempt, 'candidate' => $candidate, 'artifact' => $artifact, 'merge' => $mergeSha,
            'capture' => $capture, 'replacement' => $profile['snapshot_replacement']];
    }

    /** @param array<string,mixed> $record
     * @param  array{issue:string,attempt:string,candidate:string,artifact:string,merge:string,replacement:bool,...}  $target
     */
    private function binding(array $record, array $target): void
    {
        foreach (['issue' => 'issue', 'attempt_id' => 'attempt', 'candidate_sha' => 'candidate',
            'artifact_sha' => 'artifact', 'merge_sha' => 'merge'] as $field => $key) {
            if (($record[$field] ?? null) !== $target[$key]) {
                throw new LogicException('The native closeout record does not match the exact accepted proof.');
            }
        }
        if (($record['schema'] ?? null) !== 1 || ! in_array($record['state'] ?? null,
            ['refresh-failed', 'refresh-succeeded', 'replacement-failed', 'replacement-succeeded', 'complete'], true)) {
            throw new LogicException('The native closeout state is invalid.');
        }
        if (in_array($record['state'], ['refresh-failed', 'replacement-failed', 'complete'], true)) {
            OrbitProofCloseout::fromArray($record);
        } else {
            OrbitProofCloseout::fromArray([...$record, 'state' => 'complete']);
        }
        if ($record['state'] !== 'complete' && str_starts_with($record['state'], 'replacement-') !== $target['replacement']) {
            throw new LogicException('The native closeout strategy differs from the admitted proof profile.');
        }
    }

    /** @param list<string> $command */
    private function run(string $repository, array $command, int $timeout): ProcessResult
    {
        return Process::path($repository)->env(TaskProcessEnvironment::isolated())->timeout($timeout)->run($command);
    }
}
