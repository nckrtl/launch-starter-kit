<?php

declare(strict_types=1);

namespace App\Tasks\Landing;

use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Repositories\OrbitCandidateReceipt;
use App\Models\TaskWorkspace;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Runtime\GitTaskWorktree;
use App\Tasks\Runtime\TaskArtifactReviews;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeTaskLandingRepository implements TaskLandingRepository
{
    public function __construct(private GitTaskWorktree $worktrees, private OrbitCandidateReceipt $receipts, private TaskArtifactReviews $artifacts) {}

    public function inspect(TaskWorkspace $workspace, string $candidate, string $gate): array
    {
        $identity = $this->inspectProofCandidate($workspace, $candidate);
        $common = $this->git($workspace, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $contents = $this->receipts->contents($gate, new PreparedWorktree($workspace->worktree, $candidate), $identity['tree'], $workspace->worktree, $common);
        if (strlen($contents) > 1_048_576 || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new LogicException('The validated Builder receipt is too large or not UTF-8.');
        }
        if ($this->worktrees->inspect($workspace->repository, $workspace->worktree) !== $candidate) {
            throw new LogicException('The candidate changed during Builder receipt inspection.');
        }

        return ['candidate' => $identity['candidate'], 'tree' => $identity['tree'], 'gate_path' => $gate,
            'gate_sha256' => hash('sha256', $contents), 'gate_contents' => $contents, 'flow_contents' => $identity['flow_contents']];
    }

    /** @return array{candidate:string,tree:string,flow_contents:string} */
    public function inspectProofCandidate(TaskWorkspace $workspace, string $candidate): array
    {
        $this->assertLocalObjects($workspace);
        if ($this->worktrees->inspect($workspace->repository, $workspace->worktree) !== $candidate
            || $this->git($workspace, ['branch', '--show-current']) !== mb_strtolower($workspace->source_key)
            || ! in_array($this->git($workspace, ['remote', 'get-url', 'origin']),
                ['git@github.com:nckrtl/orbit.git', 'https://github.com/nckrtl/orbit.git', 'https://github.com/nckrtl/orbit', 'ssh://git@github.com/nckrtl/orbit.git'], true)
            || $this->git($workspace, ['ls-tree', '-r', '--name-only', $candidate, '--', '.loop']) !== '') {
            throw new LogicException('The clean registered Orbit candidate, branch or repository identity changed.');
        }

        return ['candidate' => $candidate, 'tree' => $this->git($workspace, ['rev-parse', $candidate.'^{tree}']),
            'flow_contents' => $this->flow($workspace)];
    }

    public function artifact(TaskWorkspace $workspace, string $candidate, array $inputs): ?string
    {
        $this->assertLocalObjects($workspace);
        $files = $this->files($inputs);
        $this->validateProofInputs($workspace, $candidate, $inputs);
        $this->directory($workspace, $files);
        $ref = 'refs/tags/loop/'.mb_strtolower($workspace->source_key).'/'.$candidate;
        $localResult = $this->run($workspace, ['git', '--no-replace-objects', 'rev-parse', '--verify', '--quiet', $ref]);
        if ($localResult->failed() && $localResult->exitCode() !== 1) {
            throw new LogicException('The local artifact identity could not be inspected.');
        }
        $local = $localResult->successful() ? trim($localResult->output()) : null;
        $remote = $this->git($workspace, ['ls-remote', '--refs', 'origin', $ref]);
        $fields = $remote === '' ? [] : (preg_split('/\s+/', $remote) ?: []);
        if ($fields !== [] && (count($fields) !== 2 || $fields[1] !== $ref || preg_match('/\A[a-f0-9]{40}\z/', $fields[0]) !== 1)) {
            throw new LogicException('The published artifact response is malformed.');
        }
        $published = $fields[0] ?? null;
        if ($local !== null) {
            $this->validateArtifact($workspace, $candidate, $local, $files, ($inputs['schema'] ?? null) === 2);
        }
        if ($published !== null) {
            if ($local !== null && $local !== $published) {
                throw new LogicException('Local and published artifact identities conflict; no overwrite is permitted.');
            }
            $this->validateArtifact($workspace, $candidate, $published, $files, ($inputs['schema'] ?? null) === 2);
        }

        return $published;
    }

    public function publish(TaskWorkspace $workspace, string $candidate, array $inputs): void
    {
        if (($inputs['schema'] ?? null) === 2 && isset($inputs['artifact_inputs'])) {
            throw new LogicException('Schema-2 landing must adopt its pre-proof artifact; it cannot republish it.');
        }
        $repository = TaskLandingData::object($inputs['repository'] ?? null);
        $observed = ($inputs['schema'] ?? null) === 2
            ? $this->inspectProofCandidate($workspace, $candidate)
            : $this->inspect($workspace, $candidate, TaskLandingData::text($repository, 'gate_path'));
        if ($observed !== $repository) {
            throw new LogicException('The frozen repository evidence changed before publication.');
        }
        $files = $this->files($inputs);
        $this->validateProofInputs($workspace, $candidate, $inputs);
        $this->directory($workspace, $files);
        $directory = $workspace->worktree.'/.loop';
        if (! is_dir($directory) && ! mkdir($directory, 0700)) {
            throw new LogicException('Cannot create the exact artifact directory.');
        }
        foreach ($files as $name => $contents) {
            $path = $directory.'/'.$name;
            if (file_exists($path)) {
                if (TaskLandingData::file($path, 8_388_608) !== $contents) {
                    throw new LogicException('An existing artifact input differs; no overwrite is permitted.');
                }

                continue;
            }
            $parent = dirname($path);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new LogicException('Cannot create the exact artifact input directory.');
            }
            if (is_link($parent) || realpath($parent) !== $parent
                || ($parent !== $directory && ! str_starts_with($parent, $directory.'/'))) {
                throw new LogicException('The artifact input directory is unsafe.');
            }
            $stream = @fopen($path, 'x');
            if ($stream === false) {
                throw new LogicException('Artifact input creation is uncertain; inspect before proceeding.');
            }
            try {
                if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                    throw new LogicException('Artifact input persistence is uncertain; inspect before proceeding.');
                }
            } finally {
                fclose($stream);
            }
        }
        $this->directory($workspace, $files);
        if ($this->worktrees->inspect($workspace->repository, $workspace->worktree) !== $candidate) {
            throw new LogicException('The candidate changed before native artifact publication.');
        }
        $script = $workspace->repository.'/bin/loop-artifacts';
        if (realpath($script) !== $script || is_link($script) || ! is_executable($script)) {
            throw new LogicException('The native Orbit artifact publisher is unavailable.');
        }
        if ($this->run($workspace, [$script, 'publish', $workspace->source_key], 120)->failed()) {
            throw new LogicException('Native artifact publication has an unresolved response; inspect its exact ref.');
        }
    }

    private function flow(TaskWorkspace $workspace): string
    {
        $directory = $workspace->worktree.'/.loop';
        if (is_link($directory) || (file_exists($directory) && (! is_dir($directory) || realpath($directory) !== $directory))) {
            throw new LogicException('The Orbit artifact directory is unsafe.');
        }
        $file = $directory.'/flow.json';
        $contents = file_exists($file) || is_link($file) ? TaskLandingData::file($file) : TaskLandingData::json(['schema' => 1, 'flow' => 'discovery']);
        $flow = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $configuration = $workspace->getAttribute('configuration');
        $profile = is_array($configuration) ? OrbitTaskProfile::forWorkspace($workspace) : null;
        $expected = $profile['flow'] ?? 'discovery';
        if (! is_array($flow) || count($flow) !== 2 || ($flow['schema'] ?? null) !== 1 || ($flow['flow'] ?? null) !== $expected) {
            throw new LogicException('The native Orbit flow differs from the immutable Tasks profile.');
        }

        return $contents;
    }

    /** @param array<string, mixed> $inputs
     * @return array<string, string>
     */
    private function files(array $inputs): array
    {
        if (($inputs['schema'] ?? null) === 2 && isset($inputs['artifact_inputs'])) {
            $artifactInputs = TaskLandingData::object($inputs['artifact_inputs']);
            if (($artifactInputs['schema'] ?? null) !== 2 || isset($artifactInputs['artifact_inputs'])) {
                throw new LogicException('The adopted pre-proof artifact inputs are invalid.');
            }

            return $this->files($artifactInputs);
        }
        $files = ['flow.json' => TaskLandingData::text(TaskLandingData::object($inputs['repository'] ?? null), 'flow_contents'),
            'commander-tasks.json' => TaskLandingData::json($inputs)];
        if (($inputs['schema'] ?? null) !== 2) {
            return $files;
        }
        $contracts = $inputs['proof_contract'] ?? null;
        if (! is_array($contracts) || ! array_is_list($contracts) || $contracts === []) {
            throw new LogicException('Proof artifact inputs are incomplete.');
        }
        foreach ($contracts as $contract) {
            $contract = TaskLandingData::object($contract);
            $path = TaskLandingData::text($contract, 'path', 500);
            $contents = $contract['contents'] ?? null;
            if (array_diff(array_keys($contract), ['path', 'mode', 'sha256', 'contents']) !== []
                || ! is_string($contents) || strlen($contents) > 1_048_576 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")
                || preg_match('#\A(?!/)[A-Za-z0-9._/-]+\z#D', $path) !== 1
                || array_intersect(explode('/', $path), ['', '.', '..']) !== []
                || ! in_array($contract['mode'] ?? null, str_starts_with($path, '.loop/') ? ['100644'] : ['100644', '100755'], true)
                || ! is_string($contract['sha256'] ?? null)
                || ! hash_equals($contract['sha256'], hash('sha256', $contents))) {
                throw new LogicException('A proof artifact input path, mode, or hash is invalid.');
            }
            if (! str_starts_with($path, '.loop/')) {
                continue;
            }
            if (preg_match('#\A\.loop/proof/(?:[A-Z]+-[1-9][0-9]*\.json|[a-z0-9][a-z0-9._-]{0,127})\z#D', $path) !== 1) {
                throw new LogicException('Proof artifact additions must stay below the exact native proof directory.');
            }
            $relative = substr($path, strlen('.loop/'));
            if (isset($files[$relative])) {
                throw new LogicException('Proof artifact paths must be unique.');
            }
            $files[$relative] = $contents;
        }

        return $files;
    }

    /** @param array<string, mixed> $inputs */
    private function validateProofInputs(TaskWorkspace $workspace, string $candidate, array $inputs): void
    {
        if (($inputs['schema'] ?? null) !== 2) {
            return;
        }
        if (isset($inputs['artifact_inputs'])) {
            $inputs = TaskLandingData::object($inputs['artifact_inputs']);
        }
        $contracts = $inputs['proof_contract'] ?? [];
        if (! is_array($contracts)) {
            throw new LogicException('Proof artifact inputs are incomplete.');
        }
        $validatedContracts = [];
        foreach ($contracts as $contract) {
            $contract = TaskLandingData::object($contract);
            $path = TaskLandingData::text($contract, 'path', 500);
            if (! is_string($contract['contents'] ?? null)) {
                throw new LogicException('The proof artifact contents must be text.');
            }
            $validatedContracts[] = ['path' => $path, 'mode' => TaskLandingData::text($contract, 'mode'),
                'sha256' => TaskLandingData::text($contract, 'sha256'), 'contents' => $contract['contents']];
            if (str_starts_with($path, '.loop/')) {
                continue;
            }
            $entry = $this->git($workspace, ['ls-tree', '-z', $candidate, '--', $path], true);
            if (preg_match('/\A'.preg_quote(TaskLandingData::text($contract, 'mode'), '/').' blob [a-f0-9]{40}\t'.preg_quote($path, '/').'\x00\z/s', $entry) !== 1
                || $this->git($workspace, ['show', $candidate.':'.$path], true) !== $contract['contents']) {
                throw new LogicException('A proof fixture outside .loop must match its exact candidate blob.');
            }
        }
        $this->artifacts->assertPublication($workspace, $validatedContracts);
    }

    /** @param array<string, string> $files */
    private function directory(TaskWorkspace $workspace, array $files): void
    {
        $this->flow($workspace);
        $directory = $workspace->worktree.'/.loop';
        if (! is_dir($directory)) {
            return;
        }
        $seen = [];
        $entries = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            fn (\SplFileInfo $file): bool => $file->getPathname() !== $directory.'/runtime' || ! $file->isDir() || $file->isLink());
        $iterator = new \RecursiveIteratorIterator($entries, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo) {
                throw new LogicException('Invalid artifact directory entry.');
            }
            $name = substr($file->getPathname(), strlen($directory) + 1);
            if ($file->isDir() && ! $file->isLink()) {
                if (! array_any(array_keys($files), fn (string $path): bool => str_starts_with($path, $name.'/'))) {
                    throw new LogicException('Unexpected or changed .loop input; explicitly reconcile it before publication.');
                }

                continue;
            }
            if (! isset($files[$name]) || $file->isLink() || ! $file->isFile()
                || (str_starts_with($name, 'proof/') && ($file->getPerms() & 0111) !== 0)
                || TaskLandingData::file($file->getPathname(), 8_388_608) !== $files[$name]) {
                throw new LogicException('Unexpected or changed .loop input; explicitly reconcile it before publication.');
            }
            $seen[$name] = true;
        }
        foreach (array_keys($files) as $name) {
            $path = $directory.'/'.$name;
            if ((file_exists($path) || is_link($path)) && ! isset($seen[$name])) {
                throw new LogicException('Unexpected or changed .loop input; explicitly reconcile it before publication.');
            }
        }
    }

    /** @param array<string, string> $files */
    private function validateArtifact(TaskWorkspace $workspace, string $candidate, string $artifact, array $files, bool $proof): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/', $artifact) !== 1
            || $this->git($workspace, ['rev-list', '--parents', '-n', '1', $artifact]) !== $artifact.' '.$candidate) {
            throw new LogicException('The artifact must be a commit with the exact candidate as its only parent.');
        }
        $candidateEntries = $this->git($workspace, ['ls-tree', '-z', $candidate], true);
        $entries = explode("\0", $this->git($workspace, ['ls-tree', '-z', $artifact], true));
        $product = array_filter($entries, fn (string $entry): bool => ! str_ends_with($entry, "\t.loop"));
        if (implode("\0", $product) !== $candidateEntries) {
            throw new LogicException('The artifact changes product content outside .loop.');
        }
        $seen = [];
        $mode = $proof ? '100644' : '100(?:644|755)';
        foreach (explode("\0", $this->git($workspace, ['ls-tree', '-r', '-t', '-z', $artifact, '--', '.loop'], true)) as $entry) {
            if ($entry === '') {
                continue;
            }
            if (preg_match('/\A040000 tree [a-f0-9]{40}\t\.loop(?:\/(.+))?\z/s', $entry, $tree) === 1) {
                $prefix = isset($tree[1]) ? $tree[1].'/' : '';
                if ($prefix !== '' && ! array_any(array_keys($files), fn (string $path): bool => str_starts_with($path, $prefix))) {
                    throw new LogicException('The existing artifact contains an unexpected Tasks directory.');
                }

                continue;
            }
            if (preg_match('/\A'.$mode.' blob [a-f0-9]{40}\t\.loop\/(.+)\z/s', $entry, $match) !== 1
                || ! isset($files[$match[1]]) || isset($seen[$match[1]])
                || $this->git($workspace, ['show', $artifact.':.loop/'.$match[1]], true) !== $files[$match[1]]) {
                throw new LogicException('The existing artifact differs from the frozen Tasks package; no overwrite is permitted.');
            }
            $seen[$match[1]] = true;
        }
        if (count($seen) !== count($files)) {
            throw new LogicException('The existing artifact is missing frozen Tasks inputs.');
        }
    }

    private function assertLocalObjects(TaskWorkspace $workspace): void
    {
        $result = $this->run($workspace, ['git', '--no-replace-objects', 'config', '--get-regexp',
            '^(extensions\\.partialclone|remote\\..*\\.(promisor|partialclonefilter))$']);
        if ($result->successful() || $result->exitCode() !== 1) {
            throw new LogicException('Partial or promisor Orbit repositories are not supported; materialize objects separately.');
        }
    }

    /** @param list<string> $arguments */
    private function git(TaskWorkspace $workspace, array $arguments, bool $raw = false): string
    {
        $result = $this->run($workspace, ['git', '--no-replace-objects', ...$arguments]);
        if ($result->failed()) {
            throw new LogicException('The exact Orbit Git evidence could not be inspected.');
        }

        return $raw ? $result->output() : trim($result->output());
    }

    /** @param list<string> $command */
    private function run(TaskWorkspace $workspace, array $command, int $timeout = 30): ProcessResult
    {
        $environment = [...TaskProcessEnvironment::isolated(), 'GIT_OPTIONAL_LOCKS' => '0'];
        if ($command[0] === 'git') {
            $environment['GIT_NO_LAZY_FETCH'] = '1';
        }

        return Process::path($workspace->worktree)->env($environment)
            ->timeout($timeout)->run($command);
    }
}
