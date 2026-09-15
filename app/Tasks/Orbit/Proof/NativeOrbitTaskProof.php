<?php

declare(strict_types=1);

namespace App\Tasks\Orbit\Proof;

use App\Models\TaskWorkspace;
use App\Tasks\Landing\NativeTaskLandingRepository;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeOrbitTaskProof
{
    public function __construct(private OrbitTaskProofEvidence $evidence,
        private NativeTaskLandingRepository $artifacts) {}

    /** @param array<string, mixed> $check
     * @return array<string, mixed>
     */
    public function prepare(TaskWorkspace $workspace, array $check): array
    {
        $inputs = $this->evidence->inputs($workspace, $check);

        return $this->execute($workspace, $check, $inputs);
    }

    /** @param array<string, mixed> $check
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function execute(TaskWorkspace $workspace, array $check, array $inputs): array
    {
        $candidate = TaskLandingData::text($check, 'sha', 40);
        $this->script($workspace);
        $artifact = $this->artifacts->artifact($workspace, $candidate, $inputs);
        if ($artifact === null) {
            try {
                $this->artifacts->publish($workspace, $candidate, $inputs);
            } catch (\Throwable) {
                // A process response is not authority. Reconcile only the exact deterministic ref below.
            }
            $artifact = $this->artifacts->artifact($workspace, $candidate, $inputs);
        }
        if ($artifact === null) {
            throw new LogicException('Proof artifact publication is unresolved; inspect its exact ref without republishing.');
        }

        $contract = $this->contract($inputs, $workspace->source_key);
        $planPath = TaskLandingData::text($contract, 'path');
        $planSha = TaskLandingData::text($contract, 'sha256', 64);
        $prove = $this->mutate($workspace, 'prove', $planPath);
        if ($prove === null) {
            $prove = TaskLandingData::object($this->status($workspace)['proof'] ?? null);
        }
        $this->assertProve($prove, $workspace, $candidate);

        $capture = $this->mutate($workspace, 'capture', $planPath);
        $status = $this->status($workspace);
        if ($capture === null) {
            $capture = $this->captureArchive($workspace, TaskLandingData::text($prove, 'attempt_id', 32));
        }
        $this->assertCapture($capture, $workspace, $candidate, $prove);
        $this->assertStatusCapture($status, $capture);

        return ['schema' => 1, 'attempt_id' => $prove['attempt_id'],
            'capture_fingerprint' => $capture['fingerprint'], 'retained_topology' => $capture['topology'],
            'artifact' => ['ref' => 'refs/tags/loop/'.mb_strtolower($workspace->source_key).'/'.$candidate,
                'sha' => $artifact, 'input_sha256' => TaskLandingData::hash($inputs),
                'commander_tasks_sha256' => hash('sha256', TaskLandingData::json($inputs)),
                'plan_path' => $planPath, 'plan_sha256' => $planSha, 'native_plan_sha256' => $prove['plan_sha256']],
            'artifact_inputs' => $inputs, 'prove' => $prove, 'capture' => $capture];
    }

    /** @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    public function finalize(TaskWorkspace $workspace, array $prepared): array
    {
        $attempt = TaskLandingData::text($prepared, 'attempt_id', 32);
        $candidate = TaskLandingData::text(TaskLandingData::object($prepared['prove'] ?? null), 'candidate_sha', 40);
        $capture = TaskLandingData::object($prepared['capture'] ?? null);
        $prove = TaskLandingData::object($prepared['prove'] ?? null);
        $this->assertProve($prove, $workspace, $candidate);
        $this->assertCapture($capture, $workspace, $candidate, $prove);
        $inputs = TaskLandingData::object($prepared['artifact_inputs'] ?? null);
        $artifact = TaskLandingData::object($prepared['artifact'] ?? null);
        if (TaskLandingData::hash($inputs) !== ($artifact['input_sha256'] ?? null)
            || $this->artifacts->artifact($workspace, $candidate, $inputs) !== ($artifact['sha'] ?? null)) {
            throw new LogicException('The pre-proof artifact binding changed before final review.');
        }
        $status = $this->status($workspace);
        $this->assertStatusCapture($status, $capture);
        $review = TaskLandingData::object($status['review_record'] ?? null);
        $evaluation = TaskLandingData::object($status['review_evaluation'] ?? null);
        $topology = TaskLandingData::object($status['retained_topology'] ?? null);
        $statusCapture = TaskLandingData::object($status['capture'] ?? null);
        $construction = TaskLandingData::object($topology['construction'] ?? null);
        if (($status['closeout'] ?? null) !== null || ($status['snapshot_replacement'] ?? null) !== null
            || ($construction['snapshot_replacement'] ?? null) !== true
            || ($statusCapture['fingerprint'] ?? null) !== ($prepared['capture_fingerprint'] ?? null)
            || ($statusCapture['attempt_id'] ?? null) !== $attempt || ($statusCapture['candidate_sha'] ?? null) !== $candidate
            || ($review['issue'] ?? null) !== $workspace->source_key || ($review['candidate_sha'] ?? null) !== $candidate
            || ($review['attempt_id'] ?? null) !== $attempt || ! is_array($review['actions'] ?? null) || $review['actions'] === []
            || ($evaluation['issue'] ?? null) !== $workspace->source_key || ($evaluation['candidate_sha'] ?? null) !== $candidate
            || ($evaluation['attempt_id'] ?? null) !== $attempt || ($evaluation['status'] ?? null) !== 'ready'
            || ($evaluation['required_incomplete'] ?? null) !== [] || ($evaluation['required_failed'] ?? null) !== []) {
            throw new LogicException('The native proof review is absent, stale, failed, changed, or already closed out.');
        }
        $exploratoryFailures = [];
        foreach ($review['actions'] as $action) {
            $action = TaskLandingData::object($action);
            if (! is_bool($action['required'] ?? null) || ! in_array($action['status'] ?? null, ['passed', 'failed', 'incomplete'], true)
                || ($action['required'] && $action['status'] !== 'passed')) {
                throw new LogicException('The native review evaluation does not match its required action outcomes.');
            }
            if (! $action['required'] && $action['status'] === 'failed') {
                $exploratoryFailures[] = TaskLandingData::text($action, 'id', 64);
            }
        }
        if (($evaluation['exploratory_failed'] ?? null) !== $exploratoryFailures) {
            throw new LogicException('The native review evaluation does not match its exploratory action outcomes.');
        }

        $archives = [];
        foreach (['capture' => 'proof-evidence', 'review' => 'proof-review', 'evaluation' => 'proof-review-evaluation'] as $name => $directory) {
            $path = $workspace->repository.'/.e2e/'.$directory.'/'.$workspace->source_key.'/'.$attempt.'.json';
            $contents = $this->archive($workspace, $path);
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $expected = match ($name) {
                'capture' => $capture, 'review' => $review, 'evaluation' => $evaluation,
            };
            if ($decoded !== $expected) {
                throw new LogicException('A native primary proof archive differs from the validated status.');
            }
            $archives[$name] = ['path' => $path, 'sha256' => hash('sha256', $contents), 'contents' => $contents];
        }

        return ['schema' => 1, 'status' => $status, 'capture' => $capture, 'review_record' => $review,
            'review_evaluation' => $evaluation, 'retained_topology' => $topology, 'archives' => $archives];
    }

    /** @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private function contract(array $inputs, string $issue): array
    {
        $contracts = $inputs['proof_contract'] ?? null;
        if (! is_array($contracts)) {
            throw new LogicException('The proof contract is missing from the artifact inputs.');
        }
        foreach ($contracts as $contract) {
            if (is_array($contract) && ($contract['path'] ?? null) === '.loop/proof/'.$issue.'.json') {
                return $contract;
            }
        }
        throw new LogicException('The exact issue proof plan is missing from the artifact inputs.');
    }

    private function command(TaskWorkspace $workspace, string $action, string $plan): ProcessResult
    {
        $script = $this->script($workspace);

        return Process::path($workspace->worktree)->env(TaskProcessEnvironment::isolated())->timeout(3600)->run([
            $script, $action, $workspace->source_key, '--worktree='.$workspace->worktree, '--plan='.$plan, '--json',
        ]);
    }

    /** @return array<string, mixed>|null */
    private function mutate(TaskWorkspace $workspace, string $action, string $plan): ?array
    {
        try {
            $result = $this->command($workspace, $action, $plan);

            return $result->successful() ? $this->json($result) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function status(TaskWorkspace $workspace): array
    {
        $script = $this->script($workspace);
        $result = Process::path($workspace->worktree)->env(TaskProcessEnvironment::isolated())->timeout(30)->run([
            $script, 'status', $workspace->source_key, '--worktree='.$workspace->worktree, '--json',
        ]);
        if ($result->failed()) {
            throw new LogicException('The native proof status could not be inspected.');
        }

        $status = $this->json($result);
        if (($status['worktree'] ?? null) !== $workspace->worktree) {
            throw new LogicException('The native proof status belongs to another worktree.');
        }

        return $status;
    }

    private function script(TaskWorkspace $workspace): string
    {
        foreach (['bin/e2e-topology', 'apps/e2e/artisan', 'apps/e2e/bootstrap/app.php', 'apps/e2e/vendor/autoload.php'] as $relative) {
            $path = $workspace->worktree.'/'.$relative;
            if (realpath($path) !== $path || is_link($path) || ! is_file($path) || ! is_readable($path)) {
                throw new LogicException('The exact candidate Orbit helper and local PHP bootstrap are required.');
            }
        }
        $script = $workspace->worktree.'/bin/e2e-topology';
        if (! is_executable($script)) {
            throw new LogicException('The candidate Orbit proof helper is not executable.');
        }

        return $script;
    }

    /** @return array<string, mixed> */
    private function json(ProcessResult $result): array
    {
        $output = $result->output();
        if (strlen($output) > 8_388_608 || ! mb_check_encoding($output, 'UTF-8') || str_contains($output, "\0")) {
            throw new LogicException('The native proof response is not bounded UTF-8 JSON.');
        }
        try {
            return TaskLandingData::object(json_decode($output, true, 512, JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            throw new LogicException('The native proof response is malformed.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $prove */
    private function assertProve(array $prove, TaskWorkspace $workspace, string $candidate): void
    {
        $attempt = $prove['attempt_id'] ?? null;
        $manifest = $prove['manifest_sha256'] ?? null;
        $plan = $prove['plan_sha256'] ?? null;
        if (($prove['status'] ?? null) !== 'proved' || ($prove['issue'] ?? null) !== $workspace->source_key
            || ($prove['candidate_sha'] ?? null) !== $candidate
            || ! is_string($attempt) || preg_match('/\A[a-f0-9]{32}\z/D', $attempt) !== 1
            || ! is_string($plan) || preg_match('/\A[a-f0-9]{64}\z/D', $plan) !== 1
            || ! is_string($manifest) || preg_match('/\A[a-f0-9]{64}\z/D', $manifest) !== 1
            || ! is_array($prove['actions'] ?? null)) {
            throw new LogicException('The native proof result does not match the exact artifact candidate and plan.');
        }
    }

    /** @param array<string, mixed> $capture
     * @param  array<string, mixed>  $prove
     */
    private function assertCapture(array $capture, TaskWorkspace $workspace, string $candidate, array $prove): void
    {
        $topology = TaskLandingData::object($capture['topology'] ?? null);
        $construction = TaskLandingData::object($topology['construction'] ?? null);
        $source = TaskLandingData::object($topology['source'] ?? null);
        $verification = TaskLandingData::object($topology['verification'] ?? null);
        $fingerprint = $capture['fingerprint'] ?? null;
        if (($capture['schema'] ?? null) !== 1 || ($capture['issue'] ?? null) !== $workspace->source_key
            || ($capture['candidate_sha'] ?? null) !== $candidate || ($capture['plan_sha256'] ?? null) !== ($prove['plan_sha256'] ?? null)
            || ($capture['attempt_id'] ?? null) !== ($prove['attempt_id'] ?? null)
            || ($capture['manifest_sha256'] ?? null) !== ($prove['manifest_sha256'] ?? null)
            || ($capture['proof'] ?? null) !== $prove || ($topology['purpose'] ?? null) !== 'proof'
            || ($topology['issue'] ?? null) !== $workspace->source_key || ($topology['attempt_id'] ?? null) !== $capture['attempt_id']
            || ($construction['snapshot_replacement'] ?? null) !== true
            || ($source['host_sha'] ?? null) !== $candidate || ($source['guest_sha'] ?? null) !== $candidate
            || ($verification['passed'] ?? null) !== true
            || ! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new LogicException('The captured native proof does not match its proved candidate, plan, or replacement topology.');
        }
    }

    /** @param array<string, mixed> $status
     * @param  array<string, mixed>  $capture
     */
    private function assertStatusCapture(array $status, array $capture): void
    {
        $summary = TaskLandingData::object($status['capture'] ?? null);
        if (($status['issue'] ?? null) !== ($capture['issue'] ?? null)
            || ($summary['attempt_id'] ?? null) !== ($capture['attempt_id'] ?? null)
            || ($summary['candidate_sha'] ?? null) !== ($capture['candidate_sha'] ?? null)
            || ($summary['plan_sha256'] ?? null) !== ($capture['plan_sha256'] ?? null)
            || ($summary['manifest_sha256'] ?? null) !== ($capture['manifest_sha256'] ?? null)
            || ($summary['fingerprint'] ?? null) !== ($capture['fingerprint'] ?? null)
            || ($status['proof'] ?? null) !== ($capture['proof'] ?? null)
            || ($status['retained_topology'] ?? null) !== ($capture['topology'] ?? null)) {
            throw new LogicException('The retained native proof status differs from its immutable capture.');
        }
    }

    /** @return array<string, mixed> */
    private function captureArchive(TaskWorkspace $workspace, string $attempt): array
    {
        $contents = $this->archive($workspace, $workspace->repository.'/.e2e/proof-evidence/'.$workspace->source_key.'/'.$attempt.'.json');

        return TaskLandingData::object(json_decode($contents, true, 512, JSON_THROW_ON_ERROR));
    }

    private function archive(TaskWorkspace $workspace, string $path): string
    {
        if (! str_starts_with($path, $workspace->repository.'/.e2e/')) {
            throw new LogicException('The proof archive path escapes the native primary state root.');
        }
        try {
            return TaskLandingData::file($path, 8_388_608);
        } catch (\Throwable $exception) {
            throw new LogicException('A required native primary proof archive is missing or unsafe.', previous: $exception);
        }
    }
}
