<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData as Data;
use App\Tasks\Preparation\TaskPreparationConfiguration;
use App\Tasks\Runtime\GitTaskWorktree;
use App\Tasks\Runtime\TaskProcessEnvironment;
use FilesystemIterator;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The caller owns approval, generation verification, locks and durable operation intents.
 * Neither an absent target nor missing output authorizes replay of an uncertain intent.
 *
 * @phpstan-type FilePin array{sha256: string, mode: '644'|'755'}
 * @phpstan-type Identity array{schema: 1, issue: string, repository: string, worktree_root: string, accepted_worktree: string, accepted_candidate: string, accepted_artifact: string, merged_main: string}
 * @phpstan-type Request array{schema: 1, issue: string, repository: string, worktree_root: string, accepted_worktree: string, accepted_candidate: string, accepted_artifact: string, merged_main: string, validation_worktree: string, validation_branch: string, bootstrap_directory: string, descriptor_sha256: string, source_files: array<string, FilePin>, files: array<string, FilePin>, discovery_action: array<string, mixed>, proof_action: array<string, mixed>, inputs: list<string>, bootstrap_inputs: array<string, FilePin>}
 */
final readonly class PrepareTaskSnapshotReacquisition
{
    private const string DESCRIPTOR = '.loop/proof/snapshot-reacquire.json';

    private const array IDENTITY_KEYS = ['schema', 'issue', 'repository', 'worktree_root', 'accepted_worktree',
        'accepted_candidate', 'accepted_artifact', 'merged_main'];

    private const array PROJECTS = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    public function __construct(private GitTaskWorktree $checkouts, private TaskPreparationConfiguration $configuration) {}

    /** @param array<string, mixed> $identity
     * @return Request
     */
    public function freeze(array $identity): array
    {
        if (array_diff(array_keys($identity), self::IDENTITY_KEYS) !== []
            || ($identity['schema'] ?? null) !== 1 || ($identity['issue'] ?? null) !== 'ORB-91') {
            throw new LogicException('Pin the approved ORB-91 snapshot preparation identity.');
        }
        $paths = [];
        foreach (['repository', 'worktree_root', 'accepted_worktree'] as $key) {
            $paths[$key] = Data::text($identity, $key);
            $this->directory($paths[$key]);
        }
        $repository = $paths['repository'];
        $root = $paths['worktree_root'];
        $accepted = $paths['accepted_worktree'];
        if (count(array_unique($paths)) !== 3 || ! is_dir($repository.'/.git') || is_link($repository.'/.git')
            || $this->git($repository, ['rev-parse', '--show-toplevel']) !== $repository
            || $this->git($repository, ['rev-parse', '--path-format=absolute', '--git-common-dir']) !== $repository.'/.git'
            || str_starts_with($root.'/', $repository.'/') || str_starts_with($repository.'/', $root.'/')
            || str_starts_with($root.'/', $accepted.'/')
            || $this->git($repository, ['config', '--local', '--path', '--get', 'orbit.worktreeRoot']) !== $root) {
            throw new LogicException('Use the canonical primary repository and its configured external worktree root.');
        }
        $this->checkouts->assertSupportedCheckout($repository);
        $filters = $this->runGit($repository, ['config', '--get-regexp', '^filter\..*\.(clean|process|smudge)$']);
        if ($filters->successful() || $filters->exitCode() !== 1) {
            throw new LogicException('External checkout filters are unsupported.');
        }
        $commits = [];
        foreach (['accepted_candidate', 'accepted_artifact', 'merged_main'] as $key) {
            $commits[$key] = Data::text($identity, $key);
            GitObjectId::validate($commits[$key]);
            if ($this->git($repository, ['cat-file', '-t', $commits[$key]]) !== 'commit') {
                throw new LogicException('Preparation identities must be exact local commits.');
            }
        }
        $candidate = $commits['accepted_candidate'];
        $artifact = $commits['accepted_artifact'];
        $merged = $commits['merged_main'];
        if ($this->checkouts->inspect($repository, $accepted) !== $candidate
            || $this->git($repository, ['rev-parse', '--verify', 'refs/heads/main']) !== $merged
            || $this->git($repository, ['rev-parse', '--verify', 'refs/tags/loop/orb-91/'.$candidate]) !== $artifact
            || $this->git($repository, ['rev-list', '--parents', '-n', '1', $artifact]) !== $artifact.' '.$candidate) {
            throw new LogicException('Accepted C, its sole-parent native artifact A, or merged main M changed.');
        }
        $candidateTree = $this->tree($repository, $candidate);
        $artifactTree = $this->tree($repository, $artifact);
        $mergedTree = $this->tree($repository, $merged);
        foreach ([$candidateTree, $mergedTree] as $product) {
            foreach ($product as $path => $entry) {
                if ($path === '.loop' || str_starts_with($path, '.loop/') || $entry['mode'] === '160000') {
                    throw new LogicException('Product commits cannot contain .loop artifacts or submodules.');
                }
            }
        }
        $product = $artifactTree;
        foreach ($artifactTree as $path => $entry) {
            if (str_starts_with($path, '.loop/')) {
                $this->regularMode($entry['mode']);
                unset($product[$path]);
            }
        }
        if ($product !== $candidateTree) {
            throw new LogicException('The accepted artifact must only add regular .loop files to C.');
        }
        $descriptor = $this->blob($repository, $artifactTree, self::DESCRIPTOR);
        $contract = Data::object(json_decode($descriptor, true, 32, JSON_THROW_ON_ERROR));
        if (($contract['schema'] ?? null) !== 1
            || array_diff(array_keys($contract), ['schema', 'files', 'discovery_action', 'proof_action', 'inputs']) !== []) {
            throw new LogicException('Unsupported artifact-approved snapshot descriptor.');
        }
        $sourceFiles = $this->fixturePins(Data::object($contract['files'] ?? null));
        foreach ($sourceFiles as $path => $pin) {
            if ($this->pin($this->blob($repository, $artifactTree, $path), $this->regularMode($artifactTree[$path]['mode'])) !== $pin) {
                throw new LogicException('The approved fixture bytes or modes differ from artifact A.');
            }
        }
        $sourceFiles[self::DESCRIPTOR] = $this->pin($descriptor, $this->regularMode($artifactTree[self::DESCRIPTOR]['mode']));
        ksort($sourceFiles);
        $discovery = $this->action(Data::object($contract['discovery_action'] ?? null));
        $proof = $this->action(Data::object($contract['proof_action'] ?? null));
        $inputs = $this->inputs($contract['inputs'] ?? []);
        $files = $sourceFiles;
        foreach ($this->generated($proof, $inputs) as $path => $contents) {
            $files[$path] = $this->pin($contents, '644');
        }
        ksort($files);
        $branch = 'orb-91-postinstall-'.substr($merged, 0, 12);
        $worktree = $root.'/'.$branch;

        return ['schema' => 1, 'issue' => 'ORB-91', 'repository' => $repository, 'worktree_root' => $root,
            'accepted_worktree' => $accepted, 'accepted_candidate' => $candidate, 'accepted_artifact' => $artifact,
            'merged_main' => $merged, 'validation_worktree' => $worktree, 'validation_branch' => $branch,
            'bootstrap_directory' => $worktree.'/.e2e/commander-snapshot-bootstrap',
            'descriptor_sha256' => hash('sha256', $descriptor), 'source_files' => $sourceFiles, 'files' => $files,
            'discovery_action' => $discovery, 'proof_action' => $proof, 'inputs' => $inputs,
            'bootstrap_inputs' => $this->bootstrapInputs($repository, $mergedTree)];
    }

    /** Read-only reconciliation. Partial or foreign targets are preserved and refused.
     * @param  array<string, mixed>  $request
     * @return array{state: 'absent'|'prepared', request_hash: string}
     */
    public function inspect(array $request): array
    {
        $pins = $this->request($request);
        $worktree = $pins['validation_worktree'];
        $branch = 'refs/heads/'.$pins['validation_branch'];
        $registered = false;
        foreach (explode("\0\0", $this->git($pins['repository'], ['worktree', 'list', '--porcelain', '-z'])) as $record) {
            $fields = explode("\0", $record);
            if ($fields[0] === 'worktree '.$worktree || in_array('branch '.$branch, $fields, true)) {
                if ($fields[0] !== 'worktree '.$worktree || ! in_array('branch '.$branch, $fields, true)
                    || array_any($fields, fn (string $field): bool => str_starts_with($field, 'prunable') || str_starts_with($field, 'locked'))) {
                    throw new LogicException('A foreign, locked or stale registration owns the validation target.');
                }
                $registered = true;
            }
        }
        $ref = $this->runGit($pins['repository'], ['show-ref', '--verify', '--quiet', $branch]);
        if (! $this->exists($worktree) && ! $registered && $ref->exitCode() === 1) {
            return ['state' => 'absent', 'request_hash' => Data::hash($pins)];
        }
        if (! $registered || ! $ref->successful() || ! $this->exists($worktree)
            || $this->checkouts->inspect($pins['repository'], $worktree) !== $pins['merged_main']
            || $this->git($worktree, ['symbolic-ref', '--quiet', 'HEAD']) !== $branch) {
            throw new LogicException('The validation target is partial, foreign or changed; reconcile without replay.');
        }
        $this->assertFiles($worktree, $pins['files']);
        $this->assertBootstrapInputs($pins);

        return ['state' => 'prepared', 'request_hash' => Data::hash($pins)];
    }

    /** Call only after the caller has persisted a fresh creation intent under its lock.
     * @param  array<string, mixed>  $request
     * @return array{state: 'absent'|'prepared', request_hash: string}
     */
    public function executeOnce(array $request): array
    {
        $pins = $this->request($request);
        if ($this->inspect($pins)['state'] !== 'absent') {
            throw new LogicException('Validation creation is create-only; do not replay it.');
        }
        $this->createDirectory($pins['validation_worktree']);
        $previousMask = umask(0022);
        try {
            $result = $this->runGit($pins['repository'], ['worktree', 'add', '-b', $pins['validation_branch'],
                '--', $pins['validation_worktree'], $pins['merged_main']]);
        } finally {
            umask($previousMask);
        }
        if ($result->failed()) {
            throw new LogicException('Validation worktree creation failed; retain partial state for reconciliation.');
        }
        $worktree = $pins['validation_worktree'];
        $this->directory($worktree);
        $this->createDirectory($worktree.'/.loop');
        $this->createDirectory($worktree.'/.loop/proof');
        $tree = $this->tree($pins['repository'], $pins['accepted_artifact']);
        foreach ($pins['source_files'] as $path => $pin) {
            $this->writeOnce($worktree.'/'.$path, $this->blob($pins['repository'], $tree, $path), $pin['mode']);
        }
        foreach ($this->generated($pins['proof_action'], $pins['inputs']) as $path => $contents) {
            $this->writeOnce($worktree.'/'.$path, $contents, '644');
        }

        return $this->inspect($pins);
    }

    /** Call only after a separate durable fresh bootstrap intent. Never retries or resumes.
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function bootstrapOnce(array $request): array
    {
        $pins = $this->request($request);
        if ($this->inspect($pins)['state'] !== 'prepared') {
            throw new LogicException('Prepare the exact validation worktree before bootstrap.');
        }
        $worktree = $pins['validation_worktree'];
        $runtime = $pins['bootstrap_directory'];
        if ($this->exists($runtime)) {
            throw new LogicException('Bootstrap was already attempted; unknown or failed outcomes cannot be replayed.');
        }
        foreach (self::PROJECTS as $project) {
            foreach (['vendor', '.env', '.env.testing', 'bootstrap/cache/config.php'] as $relative) {
                if ($this->exists($worktree.'/'.$project.'/'.$relative)) {
                    throw new LogicException('Bootstrap requires absent local dependencies and runtime overrides.');
                }
            }
        }
        $this->configuration->assertGuidanceInputs($worktree);
        if (! $this->exists($worktree.'/.e2e')) {
            $this->createDirectory($worktree.'/.e2e');
        }
        $this->directory($worktree.'/.e2e');
        $this->createDirectory($runtime);
        foreach (['orbit', 'composer', 'temporary', 'cache', 'config', 'data'] as $directory) {
            $this->createDirectory($runtime.'/'.$directory);
        }
        $this->writeOnce($runtime.'/attempt.json', Data::json(['schema' => 1, 'request_hash' => Data::hash($pins)]));
        $streams = [];
        try {
            foreach (['out' => 'stdout.log', 'err' => 'stderr.log'] as $type => $name) {
                $handle = fopen($runtime.'/'.$name, 'x+b');
                if ($handle === false || ! chmod($runtime.'/'.$name, 0600)) {
                    throw new LogicException('Cannot create exclusive bootstrap output.');
                }
                $streams[$type] = $handle;
            }
            $result = Process::path($worktree)->timeout(1800)->env($this->bootstrapEnvironment($runtime))
                ->run([$worktree.'/bin/bootstrap'], function (string $type, string $buffer) use ($streams): void {
                    $handle = $streams[$type];
                    if (fwrite($handle, $buffer) !== strlen($buffer) || ! fflush($handle) || ! fsync($handle)) {
                        throw new LogicException('Cannot retain bootstrap output; outcome is unknown.');
                    }
                });
            $this->writeOnce($runtime.'/exit.json', Data::json(['schema' => 1, 'request_hash' => Data::hash($pins),
                'exit_code' => $result->exitCode(), 'stdout_sha256' => $this->fileHash($runtime.'/stdout.log'),
                'stderr_sha256' => $this->fileHash($runtime.'/stderr.log')]));
        } finally {
            foreach ($streams as $handle) {
                fclose($handle);
            }
        }
        if ($result->successful()) {
            $this->inspect($pins);
            $snapshot = $this->runtimeSnapshot($pins);
            $this->writeOnce($runtime.'/ready.json', Data::json(['schema' => 1, 'request_hash' => Data::hash($pins),
                'snapshot' => $snapshot]));
        }

        return $this->inspectReadiness($pins);
    }

    /** Read-only; ready requires observed zero exit, matching logs and current contained dependencies.
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function inspectReadiness(array $request): array
    {
        $pins = $this->request($request);
        $prepared = $this->inspect($pins);
        $runtime = $pins['bootstrap_directory'];
        if ($prepared['state'] !== 'prepared' || ! $this->exists($runtime)) {
            return ['state' => 'not_started', 'request_hash' => Data::hash($pins)];
        }
        $this->directory($runtime);
        $attempt = $this->readRecord($runtime.'/attempt.json');
        if ($attempt !== ['schema' => 1, 'request_hash' => Data::hash($pins)]) {
            throw new LogicException('Bootstrap attempt belongs to another request.');
        }
        if (! $this->exists($runtime.'/exit.json')) {
            return ['state' => 'unknown', 'request_hash' => Data::hash($pins)];
        }
        $exit = $this->readRecord($runtime.'/exit.json');
        if (array_keys($exit) !== ['schema', 'request_hash', 'exit_code', 'stdout_sha256', 'stderr_sha256']
            || $exit['schema'] !== 1 || $exit['request_hash'] !== Data::hash($pins) || ! is_int($exit['exit_code'])
            || $exit['stdout_sha256'] !== $this->fileHash($runtime.'/stdout.log')
            || $exit['stderr_sha256'] !== $this->fileHash($runtime.'/stderr.log')) {
            throw new LogicException('Bootstrap exit or retained output changed.');
        }
        if ($exit['exit_code'] !== 0) {
            return ['state' => 'failed', 'request_hash' => Data::hash($pins), 'exit' => $exit];
        }
        if (! $this->exists($runtime.'/ready.json')) {
            return ['state' => 'unknown', 'request_hash' => Data::hash($pins), 'exit' => $exit];
        }
        $snapshot = $this->runtimeSnapshot($pins);
        if ($this->readRecord($runtime.'/ready.json') !== ['schema' => 1, 'request_hash' => Data::hash($pins), 'snapshot' => $snapshot]) {
            throw new LogicException('Prepared dependency state changed after bootstrap.');
        }

        return ['state' => 'ready', 'request_hash' => Data::hash($pins), 'exit' => $exit, 'snapshot' => $snapshot];
    }

    /** Read-only exact auxiliary cleanup reconciliation; never matches another ORB-91 worktree.
     * @param  array<string, mixed>  $request
     * @return array{state: 'prepared'|'worktree_removed'|'removed', request_hash: string}
     */
    public function inspectRemoval(array $request): array
    {
        $pins = $this->request($request);
        $path = $pins['validation_worktree'];
        $branch = 'refs/heads/'.$pins['validation_branch'];
        $ref = $this->runGit($pins['repository'], ['show-ref', '--verify', '--quiet', $branch]);
        if ($ref->successful() && $this->git($pins['repository'], ['rev-parse', '--verify', $branch]) !== $pins['merged_main']) {
            throw new LogicException('The exact auxiliary branch moved; cleanup is refused.');
        }
        if (! $ref->successful() && $ref->exitCode() !== 1) {
            throw new LogicException('Cannot establish exact auxiliary branch ownership.');
        }
        $registered = false;
        foreach (explode("\0\0", $this->git($pins['repository'], ['worktree', 'list', '--porcelain', '-z'])) as $record) {
            $fields = explode("\0", $record);
            if ($fields[0] === 'worktree '.$path || in_array('branch '.$branch, $fields, true)) {
                $registered = true;
            }
        }
        if ($this->exists($path) || $registered) {
            if ($this->inspect($pins)['state'] !== 'prepared') {
                throw new LogicException('Partial auxiliary removal must be reconciled without replay.');
            }

            return ['state' => 'prepared', 'request_hash' => Data::hash($pins)];
        }

        return ['state' => $ref->successful() ? 'worktree_removed' : 'removed', 'request_hash' => Data::hash($pins)];
    }

    /** Caller must verify both exact native releases and independent review before journaling this intent.
     * @param  array<string, mixed>  $request
     * @return array{state: 'prepared'|'worktree_removed'|'removed', request_hash: string}
     */
    public function removeWorktreeOnce(array $request): array
    {
        $pins = $this->request($request);
        if ($this->inspectRemoval($pins)['state'] !== 'prepared') {
            throw new LogicException('Worktree removal is single-attempt; reconcile prior removal instead.');
        }
        $result = $this->runGit($pins['repository'], ['worktree', 'remove', '--', $pins['validation_worktree']]);
        if ($result->failed()) {
            throw new LogicException('Exact auxiliary worktree removal failed; preserve and reconcile partial state.');
        }

        return $this->inspectRemoval($pins);
    }

    /** Caller must journal this branch deletion separately from worktree removal.
     * @param  array<string, mixed>  $request
     * @return array{state: 'prepared'|'worktree_removed'|'removed', request_hash: string}
     */
    public function removeBranchOnce(array $request): array
    {
        $pins = $this->request($request);
        if ($this->inspectRemoval($pins)['state'] !== 'worktree_removed') {
            throw new LogicException('Remove the exact auxiliary worktree before its branch; never replay branch deletion.');
        }
        $result = $this->runGit($pins['repository'], ['update-ref', '-d', 'refs/heads/'.$pins['validation_branch'], $pins['merged_main']]);
        if ($result->failed()) {
            throw new LogicException('Exact auxiliary compare-and-delete failed; do not retry.');
        }

        return $this->inspectRemoval($pins);
    }

    /** @param array<string, mixed> $request
     * @return Request
     */
    private function request(array $request): array
    {
        $pins = $this->freeze(array_intersect_key($request, array_flip(self::IDENTITY_KEYS)));
        if ($pins !== $request) {
            throw new LogicException('Use the complete exact frozen preparation request without amendments.');
        }

        return $pins;
    }

    /** @param array<string, mixed> $files
     * @return array<string, FilePin>
     */
    private function fixturePins(array $files): array
    {
        if ($files === [] || count($files) > 90) {
            throw new LogicException('Approve one to ninety explicit flat proof fixtures.');
        }
        $pins = [];
        foreach ($files as $path => $value) {
            $pin = Data::object($value);
            if (! preg_match('~\A\.loop/proof/[a-z0-9][a-z0-9._-]{0,127}\z~D', $path)
                || str_contains($path, '..') || $path === self::DESCRIPTOR
                || array_keys($pin) !== ['sha256', 'mode'] || ! is_string($pin['sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/D', $pin['sha256']) || ! in_array($pin['mode'] ?? null, ['644', '755'], true)) {
                throw new LogicException('Fixture inventory requires exact raw hashes, regular modes and flat approved paths.');
            }
            $pins[$path] = ['sha256' => $pin['sha256'], 'mode' => $pin['mode']];
        }
        ksort($pins);

        return $pins;
    }

    /** @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function action(array $action): array
    {
        if (array_keys($action) !== ['id', 'node', 'argv', 'timeout_seconds']
            || ($action['id'] ?? null) !== 'snapshot-candidate-reacquire'
            || ! in_array($action['node'] ?? null, ['gateway', 'app-dev', 'app-prod'], true)
            || ! is_array($action['argv'] ?? null) || ! array_is_list($action['argv']) || $action['argv'] === []
            || count($action['argv']) > 100 || ! is_int($action['timeout_seconds'] ?? null)
            || $action['timeout_seconds'] < 1 || $action['timeout_seconds'] > 900) {
            throw new LogicException('Approve one exact bounded native reacquisition action.');
        }
        foreach ($action['argv'] as $argument) {
            if (! is_string($argument) || strlen($argument) > 16_384 || preg_match('/[\x00\r\n]/', $argument)) {
                throw new LogicException('Approved argv must contain bounded single-line strings.');
            }
        }
        if ($action['argv'][0] === '' || preg_match('/\A[-=]/', $action['argv'][0])) {
            throw new LogicException('The first argv value must name a program.');
        }

        return $action;
    }

    /** @return list<string> */
    private function inputs(mixed $inputs): array
    {
        if (! is_array($inputs) || ! array_is_list($inputs) || count($inputs) > 100) {
            throw new LogicException('Approve a bounded list of native relative proof inputs.');
        }
        $paths = [];
        foreach ($inputs as $path) {
            if (! is_string($path) || strlen($path) > 4096 || $path === '' || str_starts_with($path, '/')
                || preg_match('/[\x00\r\n\\\\]/', $path) || array_intersect(explode('/', $path), ['', '.', '..']) !== []
                || in_array($path, $paths, true)) {
                throw new LogicException('Proof inputs must be unique normalized relative paths.');
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /** @param array<string, mixed> $proof
     * @param  list<string>  $inputs
     * @return array<string, string>
     */
    private function generated(array $proof, array $inputs): array
    {
        $plan = ['setup' => [], 'acceptance' => [$proof], 'snapshot_replacement' => false];
        if ($inputs !== []) {
            $plan['inputs'] = $inputs;
        }

        return ['.loop/flow.json' => Data::json(['schema' => 1, 'flow' => 'proof']),
            '.loop/proof/ORB-91.json' => Data::json($plan)];
    }

    /** @return array<string, array{mode: string, object: string}> */
    private function tree(string $repository, string $commit): array
    {
        $tree = [];
        foreach (explode("\0", $this->git($repository, ['ls-tree', '-r', '-z', $commit])) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (! preg_match('/\A([0-9]{6}) (?:blob|commit) ([a-f0-9]{40,64})\t(.+)\z/sD', $entry, $matches)) {
                throw new LogicException('Unsupported Git tree entry.');
            }
            $tree[$matches[3]] = ['mode' => $matches[1], 'object' => $matches[2]];
        }
        ksort($tree);

        return $tree;
    }

    /** @param array<string, array{mode: string, object: string}> $tree */
    private function blob(string $repository, array $tree, string $path): string
    {
        $entry = $tree[$path] ?? throw new LogicException('Missing approved artifact or bootstrap input: '.$path);
        $this->regularMode($entry['mode']);
        $size = $this->git($repository, ['cat-file', '-s', $entry['object']]);
        if (! ctype_digit($size) || (int) $size > 1_048_576) {
            throw new LogicException('Approved source files must be bounded.');
        }
        $result = $this->runGit($repository, ['cat-file', 'blob', $entry['object']]);
        $contents = $result->output();
        if ($result->failed() || strlen($contents) !== (int) $size || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw new LogicException('Approved source files must be exact bounded UTF-8 text.');
        }

        return $contents;
    }

    /** @param array<string, array{mode: string, object: string}> $tree
     * @return array<string, FilePin>
     */
    private function bootstrapInputs(string $repository, array $tree): array
    {
        $paths = ['bin/bootstrap', 'bin/worktree-cache', 'bin/tia-cache', 'bin/pest-setup',
            'bin/pest-support/manifest.json', 'bin/pest-support/monorepo.patch', 'bin/e2e-topology', 'bin/loop-artifacts'];
        foreach (self::PROJECTS as $project) {
            foreach (['composer.json', 'composer.lock', 'phpunit.guidance.xml'] as $relative) {
                $paths[] = $project.'/'.$relative;
            }
            foreach (['.env.example', 'phpunit.xml', 'phpunit.xml.dist', 'tests/bootstrap.php', 'tests/Support/TestDatabaseEnvironment.php'] as $relative) {
                if (isset($tree[$project.'/'.$relative])) {
                    $paths[] = $project.'/'.$relative;
                }
            }
        }
        $pins = [];
        foreach ($paths as $path) {
            $contents = $this->blob($repository, $tree, $path);
            $mode = $this->regularMode($tree[$path]['mode']);
            if (str_starts_with($path, 'bin/') && ! str_starts_with($path, 'bin/pest-support/') && $mode !== '755') {
                throw new LogicException('Native bootstrap commands must be executable regular files.');
            }
            $pins[$path] = $this->pin($contents, $mode);
        }
        ksort($pins);

        return $pins;
    }

    /** @param Request $pins */
    private function assertBootstrapInputs(array $pins): void
    {
        foreach ($pins['bootstrap_inputs'] as $path => $pin) {
            $file = $pins['validation_worktree'].'/'.$path;
            if ($this->pin(Data::file($file), $this->fileMode($file)) !== $pin) {
                throw new LogicException('Merged bootstrap inputs changed in the validation checkout.');
            }
        }
    }

    /** @param array<string, FilePin> $pins */
    private function assertFiles(string $worktree, array $pins): void
    {
        $this->directory($worktree.'/.loop');
        $this->directory($worktree.'/.loop/proof');
        $actual = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($worktree.'/.loop', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if (! $file instanceof SplFileInfo || $file->isLink()) {
                throw new LogicException('Prepared .loop files cannot contain links.');
            }
            $path = substr($file->getPathname(), strlen($worktree) + 1);
            if ($file->isDir()) {
                if ($path !== '.loop/proof') {
                    throw new LogicException('Unexpected artifact directory.');
                }

                continue;
            }
            $actual[$path] = $this->pin(Data::file($file->getPathname()), $this->fileMode($file->getPathname()));
        }
        ksort($actual);
        if ($actual !== $pins) {
            throw new LogicException('The exact prepared .loop file inventory, bytes or modes changed.');
        }
    }

    /** @param Request $pins
     * @return array<string, mixed>
     */
    private function runtimeSnapshot(array $pins): array
    {
        $worktree = $pins['validation_worktree'];
        $configuration = $this->configuration->snapshot($worktree);
        $this->configuration->assertGuidanceInputs($worktree);
        foreach ($configuration as $project => $files) {
            foreach ($files as $relative => $value) {
                if (! str_starts_with($value, 'link:')) {
                    $this->fileMode($worktree.'/'.$project.'/'.$relative);
                }
            }
        }
        $dependencies = [];
        foreach (self::PROJECTS as $project) {
            $vendor = $worktree.'/'.$project.'/vendor';
            foreach (['autoload.php', 'composer/installed.json'] as $required) {
                $this->fileHash($vendor.'/'.$required);
            }
            $files = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($vendor, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if (! $file instanceof SplFileInfo) {
                    throw new LogicException('Unsupported dependency entry.');
                }
                $path = $file->getPathname();
                $relative = substr($path, strlen($worktree) + 1);
                if ($file->isLink()) {
                    $target = $file->getRealPath();
                    if ($target === false || ! str_starts_with($target, $worktree.'/')) {
                        throw new LogicException('A dependency link escapes the validation checkout.');
                    }
                    $files[$relative] = 'link:'.substr($target, strlen($worktree) + 1);
                } elseif ($file->isFile()) {
                    $files[$relative] = $this->fileHash($path).':'.($file->getPerms() & 0777);
                } elseif (! $file->isDir()) {
                    throw new LogicException('Dependencies cannot contain special files.');
                }
            }
            ksort($files);
            $dependencies[$project] = Data::hash($files);
        }

        return ['configuration' => $configuration, 'dependencies' => $dependencies];
    }

    /** @return array<string, string|false> */
    private function bootstrapEnvironment(string $runtime): array
    {
        return array_merge($this->gitEnvironment(), ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'ORBIT_HOME' => $runtime.'/orbit', 'COMPOSER_HOME' => $runtime.'/composer', 'COMPOSER_CACHE_DIR' => $runtime.'/composer/cache',
            'TMPDIR' => $runtime.'/temporary', 'TMP' => $runtime.'/temporary', 'TEMP' => $runtime.'/temporary',
            'XDG_CACHE_HOME' => $runtime.'/cache', 'XDG_CONFIG_HOME' => $runtime.'/config', 'XDG_DATA_HOME' => $runtime.'/data',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'PAO_DISABLE' => 'true',
            'GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'core.hooksPath', 'GIT_CONFIG_VALUE_0' => '/dev/null']);
    }

    /** @return array<string, mixed> */
    private function readRecord(string $path): array
    {
        if ($this->fileMode($path) !== '600') {
            throw new LogicException('Bootstrap records must remain private regular files.');
        }

        return Data::object(json_decode(Data::file($path, 8_388_608), true, 32, JSON_THROW_ON_ERROR));
    }

    private function directory(string $path): void
    {
        if ($path === '/' || ! str_starts_with($path, '/') || realpath($path) !== $path || ! is_dir($path) || is_link($path)) {
            throw new LogicException('Use canonical existing directories without redirected ancestors.');
        }
    }

    private function createDirectory(string $path): void
    {
        $this->directory(dirname($path));
        if ($this->exists($path) || ! mkdir($path, 0700)) {
            throw new LogicException('Create-only directory already exists or could not be created; preserve partial state.');
        }
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    private function writeOnce(string $path, string $contents, string $mode = '600'): void
    {
        $permissions = match ($mode) {
            '600' => 0600, '644' => 0644, '755' => 0755,
            default => throw new LogicException('Unsupported create-only output permissions.'),
        };
        $this->directory(dirname($path));
        if ($this->exists($path)) {
            throw new LogicException('Create-only output already exists; do not overwrite or replay.');
        }
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new LogicException('Cannot exclusively create preparation output.');
        }
        try {
            if (! chmod($path, $permissions) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                throw new LogicException('Cannot persist preparation output; preserve partial state.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function fileHash(string $path): string
    {
        $this->fileMode($path);

        return hash_file('sha256', $path) ?: throw new LogicException('Cannot hash preparation file.');
    }

    private function fileMode(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || realpath($path) !== $path || ! is_file($path) || is_link($path) || $stat['nlink'] !== 1
            || ($stat['mode'] & 07000) !== 0) {
            throw new LogicException('Preparation files must be canonical regular files with no shared writable hard links.');
        }

        return sprintf('%o', $stat['mode'] & 0777);
    }

    /** @return '644'|'755' */
    private function regularMode(string $mode): string
    {
        return match ($mode) {
            '100644' => '644', '100755' => '755',
            default => throw new LogicException('Artifact and bootstrap inputs must be regular Git files.'),
        };
    }

    /** @return FilePin */
    private function pin(string $contents, string $mode): array
    {
        if (! in_array($mode, ['644', '755'], true)) {
            throw new LogicException('Approved files require exact modes 644 or 755.');
        }

        return ['sha256' => hash('sha256', $contents), 'mode' => $mode];
    }

    /** @param list<string> $arguments */
    private function git(string $repository, array $arguments): string
    {
        $result = $this->runGit($repository, $arguments);
        if ($result->failed()) {
            throw new LogicException('Snapshot preparation Git inspection failed: '.trim($result->errorOutput()));
        }

        return rtrim($result->output(), "\n");
    }

    /** @param list<string> $arguments */
    private function runGit(string $repository, array $arguments): ProcessResult
    {
        return Process::path($repository)->timeout(30)->env($this->gitEnvironment())->run(['git',
            '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false',
            '-c', 'core.fileMode=true', '-c', 'core.symlinks=true', '-c', 'core.ignoreStat=false',
            '-c', 'core.ignoreCase=false', '-c', 'core.splitIndex=false', '-c', 'core.autocrlf=false', ...$arguments]);
    }

    /** @return array<string, string|false> */
    private function gitEnvironment(): array
    {
        return array_merge(TaskProcessEnvironment::isolated(), ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_OPTIONAL_LOCKS' => '0', 'GIT_NO_REPLACE_OBJECTS' => '1',
            'GIT_NO_LAZY_FETCH' => '1', 'GIT_LITERAL_PATHSPECS' => '1', 'LC_ALL' => 'C']);
    }
}
