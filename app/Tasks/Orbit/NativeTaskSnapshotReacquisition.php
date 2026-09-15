<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/** Project adapter only. The caller owns durable intent, exclusive execution, reconciliation and independent acceptance. */
final class NativeTaskSnapshotReacquisition
{
    private const array OPERATIONS = ['discover', 'publish', 'observe-discovery', 'prove', 'capture',
        'observe-proof', 'release-proof', 'release-discovery'];

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function inspect(array $request): array
    {
        $this->guard($request);
        $native = $this->bridge('inspect', $request);
        $status = Data::object($native['status'] ?? null);
        $this->status($request, $status);

        return ['request_hash' => Data::hash($request), ...$native, 'artifact' => $this->artifact($request)];
    }

    /** Invoke exactly once after the caller commits this operation's durable intent. Never automatically retry an uncertain result.
     * @param  array<string,mixed>  $request
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function executeOnce(string $operation, array $request, array $evidence = []): array
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new LogicException('Unsupported snapshot reacquisition operation.');
        }
        $before = $this->inspect($request);
        $status = Data::object($before['status']);
        $this->precondition($operation, $request, $before, $evidence);
        $worktree = Data::text($request, 'validation_worktree');
        $issue = Data::text($request, 'issue');
        $topology = $worktree.'/bin/e2e-topology';
        $plan = '.loop/proof/'.$issue.'.json';
        $action = Data::object($request[$operation === 'observe-discovery' ? 'discovery_action' : 'proof_action']);
        if (str_starts_with($operation, 'release-')) {
            $result = $this->bridge($operation, $request, $evidence);
        } else {
            $command = match ($operation) {
                'discover' => [$topology, 'acquire', $issue, $worktree, '--json'],
                'publish' => [$worktree.'/bin/loop-artifacts', 'publish', $issue],
                'prove', 'capture' => [$topology, $operation, $issue, '--worktree='.$worktree, '--plan='.$plan, '--json'],
                default => [$topology, 'exec', $issue, Data::text($action, 'node'), '--worktree='.$worktree,
                    '--argv='.json_encode($action['argv'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ...($operation === 'observe-proof' ? ['--proof', '--review-action=snapshot-candidate-reacquire', '--required'] : []), '--json'],
            };
            $result = $this->json($this->run($worktree, $command, in_array($operation, ['discover', 'prove'], true) ? 7200 : 1200));
        }
        $after = $this->inspect($request);
        $this->result($operation, $request, $before, $after, $result, $evidence);

        return ['operation' => $operation, 'request_hash' => Data::hash($request), 'result' => $result,
            'before' => $status, 'after' => $after];
    }

    /** @param array<string,mixed> $request */
    private function guard(array $request): void
    {
        if (array_diff(array_keys($request), ['issue', 'repository', 'accepted_worktree', 'accepted_candidate',
            'validation_worktree', 'merged_main', 'generation', 'files', 'discovery_action', 'proof_action']) !== []
            || preg_match('/\AORB-[1-9][0-9]*\z/D', Data::text($request, 'issue')) !== 1) {
            throw new LogicException('Pin the exact Orbit reacquisition request.');
        }
        foreach (['accepted_candidate', 'merged_main'] as $key) {
            GitObjectId::validate(Data::text($request, $key));
        }
        $paths = [];
        foreach (['repository', 'accepted_worktree', 'validation_worktree'] as $key) {
            $path = Data::text($request, $key);
            if ($path === '/' || realpath($path) !== $path || ! is_dir($path) || is_link($path)) {
                throw new LogicException('Use canonical distinct repository and validation paths.');
            }
            $paths[$key] = $path;
        }
        if (count(array_unique($paths)) !== 3) {
            throw new LogicException('Do not move or reuse the original accepted worktree for reacquisition.');
        }
        $common = trim($this->git($paths['repository'], ['rev-parse', '--path-format=absolute', '--git-common-dir']));
        foreach (['accepted_worktree' => 'accepted_candidate', 'validation_worktree' => 'merged_main'] as $key => $sha) {
            if (trim($this->git($paths[$key], ['rev-parse', '--path-format=absolute', '--git-common-dir'])) !== $common
                || trim($this->git($paths[$key], ['rev-parse', '--show-toplevel'])) !== $paths[$key]
                || trim($this->git($paths[$key], ['rev-parse', 'HEAD'])) !== $request[$sha]
                || $this->git($paths[$key], ['status', '--porcelain=v1', '--untracked-files=all']) !== '') {
                throw new LogicException('The accepted or validation checkout identity changed or is dirty.');
            }
        }
        foreach (['bin/e2e-topology', 'bin/loop-artifacts', 'apps/e2e/vendor/autoload.php', 'apps/e2e/bootstrap/app.php'] as $relative) {
            $path = $paths['validation_worktree'].'/'.$relative;
            if (realpath($path) !== $path || is_link($path) || ! is_file($path)
                || (str_starts_with($relative, 'bin/') && ! is_executable($path))) {
                throw new LogicException('The exact merged native bootstrap or command is unavailable.');
            }
        }
        $generation = Data::object($request['generation'] ?? null);
        if (($generation['main_sha'] ?? null) !== $request['merged_main']) {
            throw new LogicException('The installed generation must be bound to verified merged main.');
        }
        $this->files($request);
        $plan = Data::object(json_decode(Data::file($paths['validation_worktree'].'/.loop/proof/'.Data::text($request, 'issue').'.json'), true, flags: JSON_THROW_ON_ERROR));
        $flow = Data::object(json_decode(Data::file($paths['validation_worktree'].'/.loop/flow.json'), true, flags: JSON_THROW_ON_ERROR));
        if ($flow !== ['schema' => 1, 'flow' => 'proof'] || ($plan['snapshot_replacement'] ?? null) !== false
            || ($plan['extension'] ?? null) !== null || ($plan['ends_with'] ?? null) !== null) {
            throw new LogicException('Select proof with explicit snapshot_replacement false and a complete ordinary topology.');
        }
        foreach (['discovery_action', 'proof_action'] as $key) {
            $this->action(Data::object($request[$key] ?? null));
        }
        if (! is_array($plan['acceptance'] ?? null) || ! in_array($request['proof_action'], $plan['acceptance'], true)) {
            throw new LogicException('The approved proof observation must occur in the frozen acceptance plan.');
        }
    }

    /** @param array<string,mixed> $request */
    private function files(array $request): void
    {
        $worktree = Data::text($request, 'validation_worktree');
        $files = Data::object($request['files'] ?? null);
        if (count($files) < 2 || count($files) > 100 || ! isset($files['.loop/flow.json'], $files['.loop/proof/'.Data::text($request, 'issue').'.json'])) {
            throw new LogicException('Pin the complete approved postinstall input inventory.');
        }
        foreach ($files as $path => $expected) {
            if (! preg_match('~\A\.loop/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\z~D', $path)
                || str_contains($path, '..') || str_starts_with($path, '.loop/runtime/')) {
                throw new LogicException('Reacquisition inputs must be explicit regular .loop files.');
            }
            $expected = Data::object($expected);
            $contents = Data::file($worktree.'/'.$path);
            if (array_keys($expected) !== ['sha256', 'mode'] || ($expected['sha256'] ?? null) !== hash('sha256', $contents)
                || ! in_array($expected['mode'] ?? null, ['644', '755'], true)
                || substr(sprintf('%o', fileperms($worktree.'/'.$path)), -3) !== $expected['mode']) {
                throw new LogicException('The frozen postinstall input bytes or modes changed.');
            }
        }
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($worktree.'/.loop', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || $file->isLink() || ! $file->isFile()) {
                throw new LogicException('Reacquisition artifacts cannot contain links or special files.');
            }
            $actual[] = substr($file->getPathname(), strlen($worktree) + 1);
        }
        $expectedPaths = array_keys($files);
        sort($actual);
        sort($expectedPaths);
        if ($actual !== $expectedPaths) {
            throw new LogicException('The postinstall artifact inventory differs from the approved inputs.');
        }
    }

    /** @param array<string,mixed> $action */
    private function action(array $action): void
    {
        if (array_keys($action) !== ['id', 'node', 'argv', 'timeout_seconds']
            || ($action['id'] ?? null) !== 'snapshot-candidate-reacquire'
            || ! in_array($action['node'] ?? null, ['gateway', 'app-dev', 'app-prod'], true)
            || ! is_array($action['argv'] ?? null) || ! array_is_list($action['argv']) || $action['argv'] === []
            || count($action['argv']) > 100 || ! is_int($action['timeout_seconds'] ?? null)
            || $action['timeout_seconds'] < 1 || $action['timeout_seconds'] > 900) {
            throw new LogicException('Pin one bounded native snapshot-candidate-reacquire observation per topology.');
        }
        foreach ($action['argv'] as $argument) {
            if (! is_string($argument) || strlen($argument) > 16_384 || str_contains($argument, "\0")) {
                throw new LogicException('Observation argv must contain bounded strings.');
            }
        }
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $status
     */
    private function status(array $request, array $status): void
    {
        if (($status['candidate_attempt'] ?? null) !== null) {
            throw new LogicException('Auxiliary validation must not have a candidate-convergence attempt.');
        }
        foreach (['discovery', 'proof'] as $purpose) {
            $attempt = $status[$purpose.'_attempt'] ?? null;
            $topology = $status[$purpose] ?? null;
            if ($attempt === null && $topology === null) {
                continue;
            }
            $attempt = Data::object($attempt);
            $topology = Data::object($topology);
            $construction = Data::object($topology['construction'] ?? null);
            $source = Data::object($topology['source'] ?? null);
            if (($attempt['issue'] ?? null) !== $request['issue'] || ($attempt['purpose'] ?? null) !== $purpose
                || preg_match('/\A[0-9a-f]{32}\z/D', Data::text($attempt, 'attempt_id')) !== 1
                || ($topology['issue'] ?? null) !== $request['issue'] || ($topology['purpose'] ?? null) !== $purpose
                || ($topology['attempt_id'] ?? null) !== $attempt['attempt_id'] || ($topology['generation'] ?? null) !== $request['generation']
                || ($construction['source_generation'] ?? null) !== Data::object($request['generation'])['id']
                || ($construction['snapshot_replacement'] ?? null) !== false || ($construction['extension'] ?? null) !== null
                || ($source['host_sha'] ?? null) !== $request['merged_main'] || ($source['guest_sha'] ?? null) !== $request['merged_main']
                || ($source['dirty'] ?? null) !== false) {
                throw new LogicException('The reacquired topology does not match the pinned issue, source, generation or ordinary construction.');
            }
        }
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $evidence
     */
    private function precondition(string $operation, array $request, array $before, array $evidence): void
    {
        $status = Data::object($before['status']);
        if (in_array($operation, ['discover', 'prove'], true)) {
            $purpose = $operation === 'discover' ? 'discovery' : 'proof';
            if (($status[$purpose.'_attempt'] ?? null) !== null || ($status['capture'] ?? null) !== null
                || ($purpose === 'proof' && $before['artifact'] === null)) {
                throw new LogicException('Reacquisition needs a fresh attempt and proof needs published immutable inputs; reconcile existing state first.');
            }
        }
        if ($operation === 'publish' && $before['artifact'] !== null) {
            throw new LogicException('The immutable postinstall artifact already exists; reconcile it without another publish.');
        }
        if (in_array($operation, ['observe-discovery', 'release-discovery'], true)) {
            $this->attempt($status, $evidence, 'discovery');
        }
        if (in_array($operation, ['capture', 'observe-proof'], true)
            || ($operation === 'release-proof' && ($status['proof_attempt'] ?? null) !== null)) {
            $this->attempt($status, $evidence, 'proof');
        }
        if ($operation === 'capture' && ($status['capture'] ?? null) !== null) {
            throw new LogicException('A capture already exists; reconcile the immutable result before retrying.');
        }
        if (in_array($operation, ['observe-proof', 'release-proof'], true)) {
            $capture = Data::object($status['capture'] ?? null);
            if (($capture['fingerprint'] ?? null) !== Data::text($evidence, 'capture_fingerprint')
                || ($capture['candidate_sha'] ?? null) !== $request['merged_main']
                || ($capture['attempt_id'] ?? null) !== Data::text($evidence, 'proof_attempt')) {
                throw new LogicException('The exact auxiliary capture must be pinned.');
            }
            $review = $status['review_record'] ?? null;
            if ($operation === 'observe-proof' && is_array($review) && ($review['actions'] ?? []) !== []) {
                throw new LogicException('The auxiliary review action already exists; reconcile without rerunning it.');
            }
        }
        if (str_starts_with($operation, 'release-')
            && preg_match('/\A[0-9a-f]{64}\z/D', Data::text($evidence, 'acceptance_hash')) !== 1) {
            throw new LogicException('Auxiliary cleanup requires independently accepted exact evidence.');
        }
    }

    /** @param array<string,mixed> $status
     * @param  array<string,mixed>  $evidence
     */
    private function attempt(array $status, array $evidence, string $purpose): void
    {
        $attempt = Data::object($status[$purpose.'_attempt'] ?? null);
        if (($attempt['attempt_id'] ?? null) !== Data::text($evidence, $purpose.'_attempt')) {
            throw new LogicException('The current auxiliary attempt differs from its durable identity.');
        }
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $evidence
     */
    private function result(string $operation, array $request, array $before, array $after, array $result, array $evidence): void
    {
        $status = Data::object($after['status']);
        if ($operation === 'publish') {
            $artifact = Data::object($after['artifact'] ?? null);
            if (($result['candidate'] ?? null) !== $request['merged_main'] || ($result['artifacts'] ?? null) !== $artifact['artifacts']
                || ($result['ref'] ?? null) !== $artifact['ref']) {
                throw new LogicException('The publication response does not match the immutable remote artifact.');
            }
        } elseif (in_array($operation, ['discover', 'prove'], true)) {
            $purpose = $operation === 'discover' ? 'discovery' : 'proof';
            $topology = Data::object($status[$purpose] ?? null);
            if (($result['attempt_id'] ?? null) !== $topology['attempt_id'] || ($result['issue'] ?? null) !== $request['issue']
                || (Data::object($topology['verification'] ?? null)['passed'] ?? null) !== true
                || ($operation === 'prove' && (($result['status'] ?? null) !== 'proved'
                    || ($result['candidate_sha'] ?? null) !== $request['merged_main'] || ($result['plan_sha256'] ?? null) !== $before['plan_sha256']))) {
                throw new LogicException('Native acquisition did not record complete matching verification.');
            }
            if ($operation === 'discover' && (($result['topology'] ?? null) !== $topology
                || ($result['worktree'] ?? null) !== $request['validation_worktree'] || ($result['state'] ?? null) !== 'discovery')) {
                throw new LogicException('Discovery returned another topology or worktree.');
            }
            if ($operation === 'prove') {
                $plan = Data::object(json_decode(Data::file(Data::text($request, 'validation_worktree').'/.loop/proof/'.Data::text($request, 'issue').'.json'), true, flags: JSON_THROW_ON_ERROR));
                $setup = $plan['setup'] ?? [];
                $acceptance = $plan['acceptance'] ?? [];
                if (! is_array($setup) || ! is_array($acceptance)) {
                    throw new LogicException('The exact proof action inventory is missing.');
                }
                $actions = array_map(static function (mixed $value): array {
                    $action = Data::object($value);

                    return ['id' => Data::text($action, 'id'), 'node' => Data::text($action, 'node'), 'exit_code' => 0];
                }, [...$setup, ...$acceptance]);
                if ($result !== ($status['proof_result'] ?? null) || ($result['actions'] ?? null) !== $actions) {
                    throw new LogicException('Proof did not retain all declared zero-exit actions.');
                }
            }
        } elseif ($operation === 'capture') {
            if ($result !== ($status['capture'] ?? null) || ($result['attempt_id'] ?? null) !== $evidence['proof_attempt']
                || ($result['candidate_sha'] ?? null) !== $request['merged_main'] || ($result['plan_sha256'] ?? null) !== $before['plan_sha256']) {
                throw new LogicException('Native capture does not match the exact successful auxiliary proof.');
            }
        } elseif (str_starts_with($operation, 'observe-')) {
            $purpose = $operation === 'observe-proof' ? 'proof' : 'discovery';
            $this->attempt($status, $evidence, $purpose);
            if (($result['state'] ?? null) !== 'executed' || ($result['exit_code'] ?? null) !== 0
                || ! is_string($result['stdout'] ?? null) || trim($result['stdout']) === '' || ! is_string($result['stderr'] ?? null)) {
                throw new LogicException('Retain concrete successful observation output, not only an exit code.');
            }
            if ($purpose === 'proof') {
                $review = Data::object($status['review_record'] ?? null);
                $actions = $review['actions'] ?? null;
                $action = is_array($actions) && count($actions) === 1 ? Data::object($actions[0]) : [];
                if (($review['candidate_sha'] ?? null) !== $request['merged_main'] || ($review['attempt_id'] ?? null) !== $evidence['proof_attempt']
                    || ($review['issue'] ?? null) !== $request['issue'] || ($action['id'] ?? null) !== 'snapshot-candidate-reacquire'
                    || ($action['required'] ?? null) !== true || ($action['status'] ?? null) !== 'passed' || ($action['exit_code'] ?? null) !== 0
                    || ($action['type'] ?? null) !== 'exec' || ($action['node'] ?? null) !== Data::object($request['proof_action'])['node']
                    || ($action['stdout'] ?? null) !== (strlen($result['stdout']) <= 4096 ? $result['stdout'] : mb_strcut($result['stdout'], -4096))
                    || ($action['argv'] ?? null) !== Data::object($request['proof_action'])['argv']) {
                    throw new LogicException('The concrete proof observation lacks its exact required native review record.');
                }
            }
        } else {
            $purpose = $operation === 'release-proof' ? 'proof' : 'discovery';
            if (($result['state'] ?? null) !== 'released' || ($result['issue'] ?? null) !== $request['issue']
                || ($status[$purpose.'_attempt'] ?? null) !== null || ($status[$purpose] ?? null) !== null) {
                throw new LogicException('Exact auxiliary cleanup has not been proved; reconcile before retry.');
            }
            $attempts = $result['attempts'] ?? null;
            $receipt = $purpose === 'proof' ? $result : Data::object(is_array($attempts) && count($attempts) === 1 ? $attempts[0] : null);
            if (($receipt['attempt_id'] ?? null) !== $evidence[$purpose.'_attempt'] || ($receipt['purpose'] ?? null) !== $purpose) {
                throw new LogicException('The cleanup receipt names another auxiliary attempt.');
            }
        }
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>|null
     */
    private function artifact(array $request): ?array
    {
        $worktree = Data::text($request, 'validation_worktree');
        $ref = 'refs/tags/loop/'.strtolower(Data::text($request, 'issue')).'/'.Data::text($request, 'merged_main');
        $remote = trim($this->git($worktree, ['ls-remote', '--refs', 'origin', $ref]));
        if ($remote === '') {
            return null;
        }
        if (! preg_match('/\A([0-9a-f]{40})\t'.preg_quote($ref, '/').'\z/D', $remote, $match)) {
            throw new LogicException('The remote postinstall artifact identity is malformed.');
        }
        $artifact = $match[1];
        if (trim($this->git($worktree, ['rev-parse', '--verify', $ref])) !== $artifact
            || trim($this->git($worktree, ['rev-list', '--parents', '-n', '1', $artifact])) !== $artifact.' '.Data::text($request, 'merged_main')
            || $this->productEntries($worktree, $artifact) !== $this->productEntries($worktree, Data::text($request, 'merged_main'), candidate: true)) {
            throw new LogicException('The native M artifact is missing locally or changes product contents.');
        }
        $files = Data::object($request['files']);
        $inventory = [];
        foreach (explode("\0", $this->git($worktree, ['ls-tree', '-r', '-z', $artifact, '--', '.loop'])) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (! preg_match('/\A(100644|100755) blob ([0-9a-f]{40})\t(.+)\z/Ds', $entry, $parts)) {
                throw new LogicException('Native postinstall artifacts must contain regular files.');
            }
            $inventory[$parts[3]] = ['sha256' => hash('sha256', $this->git($worktree, ['show', $artifact.':'.$parts[3]])), 'mode' => substr($parts[1], -3)];
        }
        ksort($inventory);
        ksort($files);
        if ($inventory !== $files) {
            throw new LogicException('The native M artifact differs from the frozen approved postinstall inputs.');
        }

        return ['candidate' => $request['merged_main'], 'ref' => $ref, 'artifacts' => $artifact];
    }

    /** @return list<string> */
    private function productEntries(string $worktree, string $commit, bool $candidate = false): array
    {
        $entries = [];
        foreach (explode("\0", $this->git($worktree, ['ls-tree', '-z', $commit])) as $entry) {
            if (str_ends_with($entry, "\t.loop")) {
                if ($candidate) {
                    throw new LogicException('The merged product candidate cannot track .loop artifacts.');
                }

                continue;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param array<string,mixed> $request
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    private function bridge(string $operation, array $request, array $evidence = []): array
    {
        return $this->json($this->run(Data::text($request, 'validation_worktree'), [PHP_BINARY, '-r', SnapshotReacquisitionBridge::script()],
            $operation === 'inspect' ? 30 : 1200, Data::json(compact('operation', 'request', 'evidence'))));
    }

    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): string
    {
        $result = $this->run($directory, ['git', '--no-replace-objects', ...$arguments], 60);
        $this->success($result);

        return $result->output();
    }

    /** @param list<string> $command */
    private function run(string $directory, array $command, int $timeout, ?string $input = null): ProcessResult
    {
        return Process::path($directory)->env([...TaskProcessEnvironment::isolated(), 'GIT_NO_LAZY_FETCH' => '1',
            'GIT_OPTIONAL_LOCKS' => '0', 'GIT_TERMINAL_PROMPT' => '0'])->timeout($timeout)->input($input)->run($command);
    }

    /** @return array<string,mixed> */
    private function json(ProcessResult $result): array
    {
        $this->success($result);

        return Data::object(json_decode($result->output(), true, 64, JSON_THROW_ON_ERROR));
    }

    private function success(ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new LogicException('Native reacquisition failed or its response is uncertain; reconcile durable intent and native state before any retry.');
        }
    }
}
