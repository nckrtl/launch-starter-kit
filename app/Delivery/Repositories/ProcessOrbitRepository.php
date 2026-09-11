<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitProofTopologyCloser;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;
use App\Delivery\Data\RequestedOrbitMainCacheRefresh;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitMergeLineage;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final readonly class ProcessOrbitRepository implements OrbitImplementationRepository, OrbitMainCacheRefreshRequester, OrbitMainCorrectnessInspector, OrbitMergeLineageVerifier, OrbitPrimaryCheckoutReconciler, OrbitProofTopologyCloser, OrbitRepository
{
    private const array PROJECTS = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array COMMANDS = [
        ['composer', 'validate', '--strict'],
        ['composer', 'check'],
        ['composer', 'test:affected'],
    ];

    public function verifyMergeLineage(
        OrbitProjectConfig $config,
        string $worktree,
        string $candidateSha,
        string $mergeCommitSha,
    ): VerifiedOrbitMergeLineage {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree);
        $common = $repository === false ? false : realpath($repository.'/.git');
        $scriptPath = $repository === false ? '' : $repository.'/bin/loop-flow';
        $script = $repository === false ? false : realpath($scriptPath);

        if ($repository === false || $repository !== $config->repository
            || $root === false || $root !== $config->worktreeRoot
            || $path === false || $path !== $worktree || ! str_starts_with($path, $root.'/')
            || $common === false || ! is_dir($common) || is_link($repository.'/.git')
            || $script === false || $script !== $scriptPath
            || is_link($scriptPath) || ! is_executable($script)
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $mergeCommitSha) !== 1) {
            throw new OrbitRepositoryFailed('The configured Orbit merge lineage adapter is unavailable.');
        }

        try {
            $worktreeCommon = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit merge worktree could not be inspected.',
                previous: $exception,
            );
        }

        $resolvedWorktreeCommon = $worktreeCommon->failed()
            ? false
            : realpath(trim($worktreeCommon->output()));

        if ($resolvedWorktreeCommon === false || $resolvedWorktreeCommon !== $common) {
            throw new OrbitRepositoryFailed('The Orbit merge worktree no longer belongs to the configured repository.');
        }

        try {
            $fetch = Process::path($repository)->timeout(120)->run(['git', 'fetch', 'origin', 'main']);

            if ($fetch->failed()) {
                $details = trim($fetch->errorOutput()) ?: trim($fetch->output());
                $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

                throw new OrbitRepositoryFailed('Orbit merge lineage verification failed: '.$details);
            }

            $published = Process::path($repository)->timeout(10)->run([
                'git', 'merge-base', '--is-ancestor', $mergeCommitSha, 'origin/main',
            ]);

            if ($published->failed()) {
                $details = trim($published->errorOutput()) ?: trim($published->output());
                $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

                throw new OrbitRepositoryFailed('Orbit merge lineage verification failed: '.$details);
            }

            $verification = Process::path($repository)->timeout(60)->run([
                $script,
                'verify-merge',
                '--worktree='.$path,
                '--candidate='.$candidateSha,
                '--merge='.$mergeCommitSha,
            ]);
        } catch (OrbitRepositoryFailed $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit merge lineage adapter could not run.',
                previous: $exception,
            );
        }

        if ($verification->failed()) {
            $details = trim($verification->errorOutput()) ?: trim($verification->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit merge lineage verification failed: '.$details);
        }

        try {
            $evidence = json_decode($verification->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit merge lineage adapter returned invalid JSON.',
                previous: $exception,
            );
        }

        $flow = is_array($evidence) ? ($evidence['flow'] ?? null) : null;
        $candidate = is_array($evidence) ? ($evidence['candidate'] ?? null) : null;
        $merge = is_array($evidence) ? ($evidence['merge'] ?? null) : null;
        $tree = is_array($evidence) ? ($evidence['tree'] ?? null) : null;

        if (! is_array($evidence) || array_is_list($evidence) || count($evidence) !== 4
            || ! in_array($flow, ['discovery', 'proof'], true)
            || $candidate !== $candidateSha || $merge !== $mergeCommitSha
            || ! is_string($tree) || preg_match('/^[a-f0-9]{40}$/', $tree) !== 1) {
            throw new OrbitRepositoryFailed(
                'The Orbit merge lineage adapter returned incomplete verification evidence.',
            );
        }

        return new VerifiedOrbitMergeLineage($flow, $candidate, $merge, $tree);
    }

    public function reconcilePrimaryCheckout(
        OrbitProjectConfig $config,
        string $mergeCommitSha,
    ): ReconciledOrbitPrimaryCheckout {
        $repository = realpath($config->repository);
        $common = $repository === false ? false : realpath($repository.'/.git');

        if ($repository === false || $repository !== $config->repository
            || $common === false || ! is_dir($common) || is_link($repository.'/.git')
            || preg_match('/^[a-f0-9]{40}$/', $mergeCommitSha) !== 1) {
            throw new OrbitRepositoryFailed('The configured Orbit primary checkout is unavailable.');
        }

        $root = $common.'/orbit-delivery';
        $base = $root.'/v1';
        $this->ensureReservationDirectory($root);
        $this->ensureReservationDirectory($base);
        $lockPath = $base.'/checkout.lock';

        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new OrbitRepositoryFailed('The Orbit primary checkout lock is unsafe.');
        }

        [$handle, $lockCreated] = $this->openReservationLock($base, $lockPath);

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new OrbitRepositoryFailed('Another Orbit controller currently owns the primary checkout.');
        }

        try {
            if (! $this->isOpenedReservationLock($handle, $lockPath)
                || ($lockCreated && ! $this->isPrivateOpenedReservationLock($handle))) {
                throw new OrbitRepositoryFailed('The Orbit primary checkout lock is unsafe.');
            }

            try {
                $status = Process::path($repository)->timeout(10)->run(['git', 'status', '--porcelain']);
                $branch = Process::path($repository)->timeout(10)->run(['git', 'branch', '--show-current']);
                $ancestor = Process::path($repository)->timeout(10)->run([
                    'git', 'merge-base', '--is-ancestor', $mergeCommitSha, 'origin/main',
                ]);
            } catch (RuntimeException $exception) {
                throw new OrbitRepositoryFailed(
                    'The Orbit primary checkout could not be inspected.',
                    previous: $exception,
                );
            }

            if ($status->failed() || trim($status->output()) !== '') {
                throw new OrbitRepositoryFailed('The Orbit primary main checkout is dirty.');
            }

            if ($branch->failed() || trim($branch->output()) !== 'main') {
                throw new OrbitRepositoryFailed('The Orbit primary checkout is not on main.');
            }

            if ($ancestor->failed()) {
                throw new OrbitRepositoryFailed('The confirmed Orbit merge is not present in origin/main.');
            }

            try {
                $merge = Process::path($repository)->timeout(120)->run([
                    'git', 'merge', '--ff-only', 'origin/main',
                ]);
                $head = Process::path($repository)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
                $main = Process::path($repository)->timeout(10)->run(['git', 'rev-parse', 'main']);
                $origin = Process::path($repository)->timeout(10)->run(['git', 'rev-parse', 'origin/main']);
            } catch (RuntimeException $exception) {
                throw new OrbitRepositoryFailed(
                    'The Orbit primary checkout could not be reconciled.',
                    previous: $exception,
                );
            }

            $headSha = trim($head->output());
            $mainSha = trim($main->output());
            $originMainSha = trim($origin->output());

            if ($merge->failed() || $head->failed() || $main->failed() || $origin->failed()
                || $headSha !== $mainSha || $mainSha !== $originMainSha
                || preg_match('/^[a-f0-9]{40}$/', $mainSha) !== 1) {
                throw new OrbitRepositoryFailed('The Orbit primary checkout did not reconcile to origin/main.');
            }

            return new ReconciledOrbitPrimaryCheckout(
                $repository,
                $mergeCommitSha,
                $mainSha,
                $originMainSha,
            );
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function closeProofTopology(
        OrbitProjectConfig $config,
        string $worktree,
        string $issueKey,
        string $candidateSha,
        string $artifactSha,
        string $mergeCommitSha,
        string $mainSha,
    ): OrbitProofCloseout {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree);
        $common = $repository === false ? false : realpath($repository.'/.git');
        $scriptPath = $repository === false ? '' : $repository.'/bin/e2e-topology';
        $script = $repository === false ? false : realpath($scriptPath);

        if ($repository === false || $repository !== $config->repository
            || $root === false || $root !== $config->worktreeRoot
            || $path === false || $path !== $worktree
            || $path !== $root.'/'.Str::lower($issueKey)
            || $common === false || ! is_dir($common) || is_link($repository.'/.git')
            || $script === false || $script !== $scriptPath
            || is_link($scriptPath) || ! is_executable($script)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $mergeCommitSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $mainSha) !== 1) {
            throw new OrbitRepositoryFailed('The configured Orbit proof closeout adapter is unavailable.');
        }

        try {
            $worktreeCommon = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof worktree could not be inspected.',
                previous: $exception,
            );
        }

        $resolvedWorktreeCommon = $worktreeCommon->failed()
            ? false
            : realpath(trim($worktreeCommon->output()));

        if ($resolvedWorktreeCommon === false || $resolvedWorktreeCommon !== $common) {
            throw new OrbitRepositoryFailed('The Orbit proof worktree no longer belongs to the configured repository.');
        }

        try {
            $result = Process::path($repository)->timeout(480)->run([
                $script,
                'closeout',
                $issueKey,
                '--worktree='.$path,
                '--candidate='.$candidateSha,
                '--artifact='.$artifactSha,
                '--merge='.$mergeCommitSha,
                '--main-sha='.$mainSha,
                '--json',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof closeout adapter could not run.',
                previous: $exception,
            );
        }

        try {
            $evidence = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed(
                'Orbit proof closeout failed: '.$details,
                previous: $exception,
            );
        }

        if (! is_array($evidence) || array_is_list($evidence)
            || array_keys($evidence) !== [
                'schema',
                'state',
                'issue',
                'attempt_id',
                'candidate_sha',
                'artifact_sha',
                'merge_sha',
                'main_sha',
                'generation_id',
                'error',
                'recorded_at',
            ]
            || ($evidence['schema'] ?? null) !== OrbitProofCloseout::SCHEMA
            || ! is_string($evidence['state'])
            || ! is_string($evidence['issue'])
            || ! is_string($evidence['attempt_id'])
            || ! is_string($evidence['candidate_sha'])
            || ! is_string($evidence['artifact_sha'])
            || ! is_string($evidence['merge_sha'])
            || ! is_string($evidence['main_sha'])
            || $evidence['generation_id'] !== null && ! is_string($evidence['generation_id'])
            || $evidence['error'] !== null && ! is_string($evidence['error'])
            || ! is_string($evidence['recorded_at'])) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit proof closeout returned incomplete evidence: '.$details);
        }

        try {
            $closeout = new OrbitProofCloseout(
                state: $evidence['state'],
                issueKey: $evidence['issue'],
                attemptId: $evidence['attempt_id'],
                candidateSha: $evidence['candidate_sha'],
                artifactSha: $evidence['artifact_sha'],
                mergeCommitSha: $evidence['merge_sha'],
                mainSha: $evidence['main_sha'],
                generationId: $evidence['generation_id'],
                error: $evidence['error'],
                recordedAt: $evidence['recorded_at'],
            );
        } catch (\InvalidArgumentException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof closeout adapter returned invalid evidence.',
                previous: $exception,
            );
        }

        if ($closeout->issueKey !== $issueKey
            || $closeout->candidateSha !== $candidateSha
            || $closeout->artifactSha !== $artifactSha
            || $closeout->mergeCommitSha !== $mergeCommitSha
            || $closeout->mainSha !== $mainSha
            || $result->successful() !== $closeout->complete()) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof closeout adapter returned inconsistent evidence.',
            );
        }

        return $closeout;
    }

    public function request(string $repository): RequestedOrbitMainCacheRefresh
    {
        $resolvedRepository = realpath($repository);
        $script = $resolvedRepository === false ? false : realpath($resolvedRepository.'/bin/tia-cache');

        if ($resolvedRepository === false || $resolvedRepository !== $repository
            || ! is_dir($resolvedRepository) || $script === false
            || $script !== $resolvedRepository.'/bin/tia-cache'
            || is_link($resolvedRepository.'/bin/tia-cache') || ! is_executable($script)) {
            throw new OrbitRepositoryFailed('The configured Orbit main cache refresh adapter is unavailable.');
        }

        try {
            $result = Process::path($resolvedRepository)
                ->timeout(30)
                ->run([
                    $script,
                    'refresh',
                    '--background',
                    '--repository='.$resolvedRepository,
                ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit main cache refresh adapter could not run.',
                previous: $exception,
            );
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit main cache refresh request failed: '.$details);
        }

        $message = trim($result->output());
        $disposition = match (true) {
            $message === 'Main caches already published; no refresh needed.' => 'already_current',
            preg_match('/^Cache request retained by active worker; log: .+$/', $message) === 1 => 'coalesced',
            preg_match('/^Main cache refresh queued \(pid [1-9][0-9]*\); log: .+$/', $message) === 1 => 'queued',
            default => null,
        };

        if ($disposition === null) {
            throw new OrbitRepositoryFailed(
                'The Orbit main cache refresh adapter returned incomplete request evidence.',
            );
        }

        return new RequestedOrbitMainCacheRefresh($resolvedRepository, $disposition, $message);
    }

    public function inspectMainCorrectness(OrbitProjectConfig $config): OrbitMainCorrectness
    {
        $repository = realpath($config->repository);
        $script = $repository === false ? false : realpath($repository.'/bin/tia-cache');

        if ($repository === false || ! is_dir($repository)
            || $script === false || ! is_executable($script)) {
            throw new OrbitRepositoryFailed('The configured Orbit main correctness adapter is unavailable.');
        }

        try {
            $result = Process::path($repository)
                ->timeout(120)
                ->run([$script, 'status', '--json', '--remote']);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit main correctness adapter could not run.',
                previous: $exception,
            );
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit main correctness inspection failed: '.$details);
        }

        try {
            $status = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit main correctness adapter returned invalid JSON.',
                previous: $exception,
            );
        }

        $mainSha = is_array($status) ? ($status['main'] ?? null) : null;
        $failures = is_array($status) ? ($status['correctness_failures'] ?? null) : null;

        if (! is_array($status) || array_is_list($status) || ($status['schema'] ?? null) !== 1
            || ! is_string($mainSha) || preg_match('/^[a-f0-9]{40}$/', $mainSha) !== 1
            || ! is_array($failures)) {
            throw new OrbitRepositoryFailed(
                'The Orbit main correctness adapter returned incomplete status.',
            );
        }

        $normalizedFailures = [];

        foreach ($failures as $project => $failure) {
            if (! is_string($project) || trim($project) === '') {
                throw new OrbitRepositoryFailed(
                    'The Orbit main correctness adapter returned incomplete status.',
                );
            }

            $normalizedFailures[$project] = $failure;
        }

        return new OrbitMainCorrectness($mainSha, $normalizedFailures);
    }

    public function reserveDelivery(OrbitProjectConfig $config, string $issueKey): OrbitDeliveryReservation
    {
        $repository = realpath($config->repository);

        if ($repository === false || ! is_dir($repository)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1) {
            throw new OrbitRepositoryFailed('The Orbit delivery reservation is invalid.');
        }

        $common = realpath($repository.'/.git');

        if ($common === false || ! is_dir($common) || is_link($repository.'/.git')) {
            throw new OrbitRepositoryFailed('The Orbit Git common directory is unavailable.');
        }

        $root = $common.'/orbit-delivery';
        $base = $root.'/v1';
        $directory = $base.'/'.Str::lower($issueKey);

        $this->ensureReservationDirectory($root);
        $this->ensureReservationDirectory($base);
        $this->ensureReservationDirectory($directory);

        $lockPath = $directory.'/controller.lock';

        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new OrbitRepositoryFailed('The Orbit delivery reservation lock is unsafe.');
        }

        [$handle, $lockCreated] = $this->openReservationLock($directory, $lockPath);

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new OrbitRepositoryFailed('Another Orbit delivery controller currently owns this issue.');
        }

        if (! $this->isOpenedReservationLock($handle, $lockPath)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            throw new OrbitRepositoryFailed('The Orbit delivery reservation lock is unsafe.');
        }

        foreach (['state.json', 'worker.json'] as $journal) {
            $path = $directory.'/'.$journal;

            if (file_exists($path) || is_link($path)) {
                flock($handle, LOCK_UN);
                fclose($handle);

                throw new OrbitRepositoryFailed('This issue already has a legacy Orbit controller journal.');
            }
        }

        if (! $this->isOpenedReservationLock($handle, $lockPath)
            || ($lockCreated && ! $this->isPrivateOpenedReservationLock($handle))) {
            flock($handle, LOCK_UN);
            fclose($handle);

            throw new OrbitRepositoryFailed('The Orbit delivery reservation lock is unsafe.');
        }

        return new OrbitDeliveryReservation($handle, $lockPath);
    }

    private function ensureReservationDirectory(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            @mkdir($path, 0700);
        }

        if (is_link($path) || ! is_dir($path) || realpath($path) !== $path) {
            throw new OrbitRepositoryFailed('The Orbit delivery reservation directory is unsafe.');
        }
    }

    /** @return array{resource, bool} */
    private function openReservationLock(string $directory, string $lockPath): array
    {
        if (file_exists($lockPath)) {
            $handle = @fopen($lockPath, 'c+');

            if ($handle === false) {
                throw new OrbitRepositoryFailed('The Orbit delivery reservation lock could not be opened.');
            }

            return [$handle, false];
        }

        $temporary = tempnam($directory, '.controller-lock-');

        if ($temporary === false) {
            throw new OrbitRepositoryFailed('The Orbit delivery reservation lock could not be opened.');
        }

        try {
            $handle = @fopen($temporary, 'r+');

            if ($handle === false
                || ! $this->isOpenedReservationLock($handle, $temporary)
                || ! $this->isPrivateOpenedReservationLock($handle)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                throw new OrbitRepositoryFailed('The Orbit delivery reservation lock is unsafe.');
            }

            if (@link($temporary, $lockPath)) {
                return [$handle, true];
            }

            fclose($handle);

            if (is_link($lockPath) || ! is_file($lockPath)) {
                throw new OrbitRepositoryFailed('The Orbit delivery reservation lock is unsafe.');
            }

            $handle = @fopen($lockPath, 'c+');

            if ($handle === false) {
                throw new OrbitRepositoryFailed('The Orbit delivery reservation lock could not be opened.');
            }

            return [$handle, false];
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  resource  $handle
     *
     * @phpstan-impure
     */
    private function isPrivateOpenedReservationLock(mixed $handle): bool
    {
        $opened = fstat($handle);

        return $opened !== false && ($opened['mode'] & 0777) === 0600;
    }

    /**
     * @param  resource  $handle
     *
     * @phpstan-impure
     */
    private function isOpenedReservationLock(mixed $handle, string $path): bool
    {
        clearstatcache(true, $path);
        $opened = fstat($handle);
        $pathStat = @lstat($path);

        return $opened !== false
            && $pathStat !== false
            && ! is_link($path)
            && ($opened['mode'] & 0170000) === 0100000
            && ($pathStat['mode'] & 0170000) === 0100000
            && $opened['dev'] === $pathStat['dev']
            && $opened['ino'] === $pathStat['ino'];
    }

    public function prepareWorktree(OrbitProjectConfig $config, string $issueKey): PreparedWorktree
    {
        $repository = realpath($config->repository);
        $script = $repository === false ? false : realpath($repository.'/bin/worktree-create');

        if ($repository === false || ! is_dir($repository) || $script === false || ! is_executable($script)) {
            throw new OrbitRepositoryFailed('The configured Orbit worktree adapter is unavailable.');
        }

        try {
            $result = Process::path($repository)
                ->timeout(300)
                ->run([$script, $issueKey, '--flow='.$config->defaultFlow]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit worktree adapter could not run.', 0, $exception);
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit worktree preparation failed: '.$details);
        }

        $lines = preg_split('/\R/', trim($result->output())) ?: [];
        $reportedPath = end($lines);
        $worktree = is_string($reportedPath) ? realpath($reportedPath) : false;
        $root = realpath($config->worktreeRoot);

        if ($root === false || $worktree === false || ! is_dir($worktree)
            || ($worktree !== $root && ! str_starts_with($worktree, $root.'/'))) {
            throw new OrbitRepositoryFailed('Orbit returned a worktree outside the configured worktree root.');
        }

        try {
            $headResult = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The prepared worktree Git HEAD could not be inspected.', 0, $exception);
        }

        $headSha = trim($headResult->output());

        if ($headResult->failed() || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $headSha) !== 1) {
            throw new OrbitRepositoryFailed('Could not resolve a valid Git HEAD for the prepared worktree.');
        }

        return new PreparedWorktree($worktree, $headSha);
    }

    public function checkCandidate(OrbitProjectConfig $config, PreparedWorktree $worktree): CandidateCheck
    {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree->path);

        if ($repository === false || $root === false || $path === false || ! is_dir($path)
            || ($path !== $root && ! str_starts_with($path, $root.'/'))
            || preg_match('/^[a-f0-9]{40}$/', $worktree->headSha) !== 1) {
            throw new OrbitRepositoryFailed('The configured Orbit candidate check is unavailable.');
        }

        try {
            $result = Process::path($path)->timeout(3600)->run(['composer', 'check']);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit candidate check could not run.', 0, $exception);
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit candidate check failed: '.$details);
        }

        if (preg_match_all('/receipt: (.+)$/m', $result->output(), $matches) !== 1) {
            throw new OrbitRepositoryFailed('Orbit candidate check did not return one receipt path.');
        }

        $reportedReceipt = trim($matches[1][0]);

        try {
            $treeResult = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $commonResult = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The checked candidate Git metadata could not be inspected.', 0, $exception);
        }

        $treeSha = trim($treeResult->output());
        $common = realpath(trim($commonResult->output()));

        if ($treeResult->failed() || $commonResult->failed()
            || preg_match('/^[a-f0-9]{40}$/', $treeSha) !== 1 || $common === false || ! is_dir($common)) {
            throw new OrbitRepositoryFailed('The checked candidate Git metadata is invalid.');
        }

        $receiptPath = $this->validatedCandidateReceiptPath($reportedReceipt, $worktree, $treeSha, $path, $common);

        return new CandidateCheck($receiptPath, $worktree->headSha, $treeSha);
    }

    public function verifyPlanningHandoff(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        CandidateCheck $candidate,
        PreparedIssueSnapshot $snapshot,
    ): VerifiedOrbitPlanningRepository {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree->path);
        $configuredCommon = $repository === false ? false : realpath($repository.'/.git');
        $flow = $repository === false ? false : realpath($repository.'/bin/loop-flow');

        if ($repository === false || $root === false || $path === false || $path !== $worktree->path
            || $configuredCommon === false || ! is_dir($configuredCommon) || is_link($repository.'/.git')
            || $flow === false || ! is_executable($flow)
            || ! str_starts_with($path, $root.'/')
            || preg_match('/^ORB-[0-9]+$/', $snapshot->issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $worktree->headSha) !== 1
            || $candidate->candidateSha !== $worktree->headSha
            || preg_match('/^[a-f0-9]{40}$/', $candidate->treeSha) !== 1) {
            throw new OrbitRepositoryFailed('The Orbit planning candidate metadata is invalid.');
        }

        try {
            $inventory = Process::path($repository)->timeout(10)->run(['git', 'worktree', 'list', '--porcelain']);
            $status = Process::path($path)->timeout(10)->run(['git', 'status', '--porcelain']);
            $conflicts = Process::path($path)->timeout(10)->run(['git', 'diff', '--name-only', '--diff-filter=U']);
            $head = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $tree = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $common = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
            $selectedFlow = Process::path($repository)->timeout(10)->run([$flow, 'status', '--worktree='.$path]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit planning candidate could not be inspected.', 0, $exception);
        }

        if ($inventory->failed() || ! $this->hasExactIssueWorktree($inventory->output(), $path, Str::lower($snapshot->issueKey))) {
            throw new OrbitRepositoryFailed('The registered Orbit issue worktree changed before planning preparation.');
        }

        if ($status->failed() || trim($status->output()) !== '') {
            throw new OrbitRepositoryFailed('The Orbit issue worktree is dirty before planning preparation.');
        }

        if ($conflicts->failed() || trim($conflicts->output()) !== '') {
            throw new OrbitRepositoryFailed('The Orbit issue worktree has unresolved conflicts before planning preparation.');
        }

        if ($head->failed() || trim($head->output()) !== $candidate->candidateSha
            || $tree->failed() || trim($tree->output()) !== $candidate->treeSha) {
            throw new OrbitRepositoryFailed('The Orbit planning candidate no longer matches its recorded Git state.');
        }

        $currentCommon = $common->failed() ? false : realpath(trim($common->output()));

        if ($currentCommon === false || $currentCommon !== $configuredCommon) {
            throw new OrbitRepositoryFailed('The Orbit issue worktree no longer belongs to the configured repository.');
        }

        if ($selectedFlow->failed() || trim($selectedFlow->output()) !== 'discovery') {
            throw new OrbitRepositoryFailed('Orbit planning preparation requires the discovery flow.');
        }

        $receiptPath = $this->validatedCandidateReceiptPath(
            $candidate->receiptPath,
            $worktree,
            $candidate->treeSha,
            $path,
            $configuredCommon,
        );
        $this->verifyIssueSnapshot($config, $worktree, $snapshot);

        return new VerifiedOrbitPlanningRepository(
            worktreePath: $path,
            branch: Str::lower($snapshot->issueKey),
            candidateSha: $candidate->candidateSha,
            treeSha: $candidate->treeSha,
            flow: 'discovery',
            qualityReceiptPath: $receiptPath,
            snapshotPath: $snapshot->path,
            snapshotContentsHash: $snapshot->contentsHash,
        );
    }

    public function verifyPlanningArtifact(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        string $issueKey,
        string $artifactSha,
        string $expectedVerdict,
    ): VerifiedOrbitPlanningArtifact {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree->path);
        $validator = $repository === false ? false : realpath($repository.'/bin/plan-lint');

        if ($repository === false || $root === false || $path === false || $path !== $worktree->path
            || $validator === false || ! is_executable($validator)
            || ! str_starts_with($path, $root.'/')
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $worktree->headSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1
            || ! in_array($expectedVerdict, ['PENDING', 'PASS', 'FIX'], true)) {
            throw new OrbitRepositoryFailed('The Orbit planning artifact metadata is invalid.');
        }

        try {
            $verification = Process::path($path)->timeout(60)->run([
                $validator,
                'verify',
                $issueKey,
                '--worktree='.$path,
                '--artifact='.$artifactSha,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit planning artifact validator could not run.', 0, $exception);
        }

        if ($verification->failed()) {
            $details = trim($verification->errorOutput()) ?: trim($verification->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit planning artifact verification failed: '.$details);
        }

        try {
            $candidateType = Process::path($path)->timeout(10)->run(['git', 'cat-file', '-t', $worktree->headSha]);
            $artifactType = Process::path($path)->timeout(10)->run(['git', 'cat-file', '-t', $artifactSha]);
            $parents = Process::path($path)->timeout(10)->run(['git', 'rev-list', '--parents', '-n', '1', $artifactSha]);
            $candidateLoop = Process::path($path)->timeout(10)->run([
                'git', 'ls-tree', '-r', '--name-only', $worktree->headSha, '--', '.loop',
            ]);
            $productDiff = Process::path($path)->timeout(10)->run([
                'git', 'diff', '--name-only', $worktree->headSha, $artifactSha, '--', '.', ':(exclude).loop',
            ]);
            $artifactLoop = Process::path($path)->timeout(10)->run([
                'git', 'ls-tree', '-r', $artifactSha, '--', '.loop',
            ]);
            $plan = Process::path($path)->timeout(10)->run([
                'git',
                'show',
                $artifactSha.':.loop/plan.md',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The saved Orbit planning artifact could not be read.', 0, $exception);
        }

        $parentFields = preg_split('/\s+/', trim($parents->output())) ?: [];
        $loopEntries = preg_split('/\R/', trim($artifactLoop->output())) ?: [];
        $validLoopEntries = $loopEntries !== [] && collect($loopEntries)->every(
            static fn (string $entry): bool => preg_match('/^100(?:644|755) blob [a-f0-9]{40}\t\.loop\/.+$/', $entry) === 1,
        );

        if ($candidateType->failed() || trim($candidateType->output()) !== 'commit'
            || $artifactType->failed() || trim($artifactType->output()) !== 'commit'
            || $parents->failed() || $parentFields !== [$artifactSha, $worktree->headSha]
            || $candidateLoop->failed() || trim($candidateLoop->output()) !== ''
            || $productDiff->failed() || trim($productDiff->output()) !== ''
            || $artifactLoop->failed() || ! $validLoopEntries) {
            throw new OrbitRepositoryFailed('The saved Orbit planning artifact is not bound to the exact candidate.');
        }

        $contents = $plan->output();
        $lines = preg_split('/\R/', $contents) ?: [];

        if ($plan->failed() || ! in_array("Review verdict: {$expectedVerdict}", $lines, true)) {
            throw new OrbitRepositoryFailed("The saved Orbit planning artifact must have a {$expectedVerdict} review verdict.");
        }

        return new VerifiedOrbitPlanningArtifact($artifactSha, hash('sha256', $contents));
    }

    public function verifyPlanningOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $candidateSha,
        ?string $artifactSha,
    ): VerifiedOrbitPlanningOutcome {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($startupWorktree->path);
        $configuredCommon = $repository === false ? false : realpath($repository.'/.git');
        $flow = $repository === false ? false : realpath($repository.'/bin/loop-flow');

        if ($repository === false || $root === false || $path === false || $path !== $startupWorktree->path
            || $configuredCommon === false || ! is_dir($configuredCommon) || is_link($repository.'/.git')
            || $flow === false || ! is_executable($flow)
            || ! str_starts_with($path, $root.'/')
            || preg_match('/^ORB-[0-9]+$/', $snapshot->issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $startupWorktree->headSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || ($artifactSha !== null && preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1)) {
            throw new OrbitRepositoryFailed('The Orbit planning outcome metadata is invalid.');
        }

        try {
            $inventory = Process::path($repository)->timeout(10)->run(['git', 'worktree', 'list', '--porcelain']);
            $status = Process::path($path)->timeout(10)->run(['git', 'status', '--porcelain']);
            $conflicts = Process::path($path)->timeout(10)->run(['git', 'diff', '--name-only', '--diff-filter=U']);
            $head = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $tree = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $common = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
            $ancestor = Process::path($path)->timeout(10)->run([
                'git', 'merge-base', '--is-ancestor', $startupWorktree->headSha, $candidateSha,
            ]);
            $changes = Process::path($path)->timeout(10)->run([
                'git', 'diff', '--name-only', $startupWorktree->headSha.'..'.$candidateSha,
            ]);
            $subjects = Process::path($path)->timeout(10)->run([
                'git', 'log', '--format=%s', $startupWorktree->headSha.'..'.$candidateSha,
            ]);
            $candidateLoop = Process::path($path)->timeout(10)->run([
                'git', 'ls-tree', '-r', '--name-only', $candidateSha, '--', '.loop',
            ]);
            $selectedFlow = Process::path($repository)->timeout(10)->run([$flow, 'status', '--worktree='.$path]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit planning outcome could not be inspected.', 0, $exception);
        }

        if ($inventory->failed() || ! $this->hasExactIssueWorktree($inventory->output(), $path, Str::lower($snapshot->issueKey))
            || $status->failed() || trim($status->output()) !== ''
            || $conflicts->failed() || trim($conflicts->output()) !== ''
            || $head->failed() || trim($head->output()) !== $candidateSha
            || $tree->failed() || preg_match('/^[a-f0-9]{40}$/', trim($tree->output())) !== 1
            || $ancestor->failed()
            || $candidateLoop->failed() || trim($candidateLoop->output()) !== ''
            || $selectedFlow->failed() || trim($selectedFlow->output()) !== 'discovery') {
            throw new OrbitRepositoryFailed('The Orbit planning outcome no longer matches its issue worktree.');
        }

        $currentCommon = $common->failed() ? false : realpath(trim($common->output()));
        $changedPaths = array_values(array_filter(preg_split('/\R/', trim($changes->output())) ?: []));
        $commitSubjects = array_values(array_filter(preg_split('/\R/', trim($subjects->output())) ?: []));

        if ($currentCommon === false || $currentCommon !== $configuredCommon
            || $changes->failed() || collect($changedPaths)->contains(
                static fn (string $changed): bool => ! str_starts_with($changed, 'docs/'),
            )
            || $subjects->failed()
            || ($candidateSha !== $startupWorktree->headSha && $commitSubjects === [])
            || collect($commitSubjects)->contains(
                static fn (string $subject): bool => ! str_starts_with($subject, 'docs:'),
            )) {
            throw new OrbitRepositoryFailed('Orbit planning may advance only through docs-only planning commits.');
        }

        $this->verifyIssueSnapshot($config, $startupWorktree, $snapshot);
        $artifact = $artifactSha === null ? null : $this->verifyPlanningArtifact(
            $config,
            new PreparedWorktree($path, $candidateSha),
            $snapshot->issueKey,
            $artifactSha,
            'PENDING',
        );

        return new VerifiedOrbitPlanningOutcome(
            candidateSha: $candidateSha,
            treeSha: trim($tree->output()),
            artifactSha: $artifact?->artifactSha,
            planContentsHash: $artifact?->planContentsHash,
        );
    }

    public function verifyImplementationOutcome(
        OrbitProjectConfig $config,
        PreparedWorktree $startupWorktree,
        PreparedIssueSnapshot $snapshot,
        string $reviewedCandidateSha,
        string $candidateSha,
        string $artifactSha,
        string $gateReceiptPath,
        string $pullRequestBody,
    ): VerifiedOrbitImplementationOutcome {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($startupWorktree->path);
        $common = $repository === false ? false : realpath($repository.'/.git');
        $flow = $repository === false ? false : realpath($repository.'/bin/loop-flow');
        $artifacts = $repository === false ? false : realpath($repository.'/bin/loop-artifacts');

        if ($repository === false || $root === false || $path === false || $path !== $startupWorktree->path
            || $common === false || ! is_dir($common) || is_link($repository.'/.git')
            || $flow === false || ! is_executable($flow)
            || $artifacts === false || ! is_executable($artifacts)
            || ! str_starts_with($path, $root.'/')
            || preg_match('/^ORB-[0-9]+$/', $snapshot->issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $startupWorktree->headSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $reviewedCandidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1
            || trim($gateReceiptPath) === '' || trim($pullRequestBody) === '') {
            throw new OrbitRepositoryFailed('The Orbit implementation outcome metadata is invalid.');
        }

        $branch = Str::lower($snapshot->issueKey);

        try {
            $inventory = Process::path($repository)->timeout(10)->run(['git', 'worktree', 'list', '--porcelain']);
            $status = Process::path($path)->timeout(10)->run(['git', 'status', '--porcelain']);
            $conflicts = Process::path($path)->timeout(10)->run(['git', 'diff', '--name-only', '--diff-filter=U']);
            $head = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $tree = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $actualCommon = Process::path($path)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
            $startupAncestor = Process::path($path)->timeout(10)->run([
                'git', 'merge-base', '--is-ancestor', $startupWorktree->headSha, $candidateSha,
            ]);
            $reviewedAncestor = Process::path($path)->timeout(10)->run([
                'git', 'merge-base', '--is-ancestor', $reviewedCandidateSha, $candidateSha,
            ]);
            $candidateLoop = Process::path($path)->timeout(10)->run([
                'git', 'ls-tree', '-r', '--name-only', $candidateSha, '--', '.loop',
            ]);
            $selectedFlow = Process::path($repository)->timeout(10)->run([$flow, 'status', '--worktree='.$path]);
            $remote = Process::path($path)->timeout(30)->run([
                'git', 'ls-remote', 'origin', 'refs/heads/'.$branch,
            ]);
            $publishedArtifact = Process::path($path)->timeout(120)->run([
                $artifacts,
                'fetch',
                $snapshot->issueKey,
                '--candidate='.$candidateSha,
                '--expected-artifact='.$artifactSha,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The Orbit implementation outcome could not be inspected.', 0, $exception);
        }

        if ($inventory->failed() || ! $this->hasExactIssueWorktree($inventory->output(), $path, $branch)
            || $status->failed() || trim($status->output()) !== ''
            || $conflicts->failed() || trim($conflicts->output()) !== ''
            || $head->failed() || trim($head->output()) !== $candidateSha
            || $tree->failed() || preg_match('/^[a-f0-9]{40}$/', trim($tree->output())) !== 1
            || $startupAncestor->failed() || $reviewedAncestor->failed()
            || $candidateLoop->failed() || trim($candidateLoop->output()) !== ''
            || $selectedFlow->failed() || trim($selectedFlow->output()) !== 'discovery') {
            throw new OrbitRepositoryFailed('The Orbit implementation outcome no longer matches its issue worktree.');
        }

        $resolvedCommon = $actualCommon->failed() ? false : realpath(trim($actualCommon->output()));
        $remoteFields = preg_split('/\s+/', trim($remote->output())) ?: [];

        if ($resolvedCommon === false || $resolvedCommon !== $common
            || $remote->failed() || $remoteFields !== [$candidateSha, 'refs/heads/'.$branch]) {
            throw new OrbitRepositoryFailed('The pushed Orbit implementation candidate does not match its branch.');
        }

        if ($publishedArtifact->failed()) {
            $details = trim($publishedArtifact->errorOutput()) ?: trim($publishedArtifact->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit implementation artifact verification failed: '.$details);
        }

        try {
            $artifact = json_decode($publishedArtifact->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The Orbit implementation artifact result is invalid.', 0, $exception);
        }

        $expectedRef = 'refs/tags/loop/'.$branch.'/'.$candidateSha;

        if (! is_array($artifact) || array_is_list($artifact)
            || $artifact !== [
                'candidate' => $candidateSha,
                'ref' => $expectedRef,
                'artifacts' => $artifactSha,
            ]) {
            throw new OrbitRepositoryFailed('The published Orbit implementation artifact does not match its candidate.');
        }

        $candidate = new PreparedWorktree($path, $candidateSha);
        $treeSha = trim($tree->output());
        $gate = $this->validatedCandidateReceiptPath($gateReceiptPath, $candidate, $treeSha, $path, $common);
        $requiredBodyBindings = [
            'Issue: '.$snapshot->issueKey,
            $candidateSha,
            $artifactSha,
            'discovery',
            'Builder gate: passed ('.$gate.')',
        ];

        if (collect($requiredBodyBindings)->contains(
            static fn (string $binding): bool => ! str_contains($pullRequestBody, $binding),
        )) {
            throw new OrbitRepositoryFailed('The Orbit pull request body is missing an implementation binding.');
        }

        $this->verifyIssueSnapshot($config, $startupWorktree, $snapshot);

        return new VerifiedOrbitImplementationOutcome(
            candidateSha: $candidateSha,
            treeSha: $treeSha,
            artifactSha: $artifactSha,
            gateReceiptPath: $gate,
            pullRequestBodyHash: hash('sha256', $pullRequestBody),
            flow: 'discovery',
        );
    }

    public function writeIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        OrbitIssueSnapshot $snapshot,
    ): PreparedIssueSnapshot {
        if (($snapshot->payload['id'] ?? null) !== $snapshot->issueId
            || ($snapshot->payload['identifier'] ?? null) !== $snapshot->issueKey
            || preg_match('/^[a-f0-9]{64}$/', $snapshot->contractHash) !== 1) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot identity is invalid.');
        }

        $target = $this->issueSnapshotTarget($config, $worktree);
        $loop = dirname($target);

        try {
            $contents = json_encode(
                $snapshot->payload,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )."\n";
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be encoded.', 0, $exception);
        }

        $retained = $this->retainedIssueSnapshot($target, $snapshot->payload);

        if ($retained === null) {
            $this->publishIssueSnapshot($loop, $target, $contents);
            $retained = $this->retainedIssueSnapshot($target, $snapshot->payload);
        }

        if ($retained === null) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be retained.');
        }

        return new PreparedIssueSnapshot(
            schema: OrbitIssueSnapshot::SCHEMA,
            provider: OrbitIssueSnapshot::PROVIDER,
            path: $target,
            contentsHash: hash('sha256', $retained),
            contractSchema: OrbitIssueSnapshot::CONTRACT_SCHEMA,
            contractHash: $snapshot->contractHash,
            issueId: $snapshot->issueId,
            issueKey: $snapshot->issueKey,
        );
    }

    public function verifyIssueSnapshot(
        OrbitProjectConfig $config,
        PreparedWorktree $worktree,
        PreparedIssueSnapshot $snapshot,
    ): void {
        $target = $this->issueSnapshotTarget($config, $worktree);

        if ($snapshot->schema !== OrbitIssueSnapshot::SCHEMA
            || $snapshot->provider !== OrbitIssueSnapshot::PROVIDER
            || $snapshot->contractSchema !== OrbitIssueSnapshot::CONTRACT_SCHEMA
            || $snapshot->path !== $target
            || preg_match('/^[a-f0-9]{64}$/', $snapshot->contentsHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $snapshot->contractHash) !== 1) {
            throw new OrbitRepositoryFailed('The prepared Orbit issue snapshot metadata is invalid.');
        }

        $contents = $this->privateIssueSnapshotContents($target);

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The retained Orbit issue snapshot is invalid.', 0, $exception);
        }

        if (! hash_equals($snapshot->contentsHash, hash('sha256', $contents))
            || ! is_array($payload) || array_is_list($payload)
            || ($payload['id'] ?? null) !== $snapshot->issueId
            || ($payload['identifier'] ?? null) !== $snapshot->issueKey) {
            throw new OrbitRepositoryFailed('The retained Orbit issue snapshot no longer matches its ledger record.');
        }
    }

    private function validatedCandidateReceiptPath(
        string $reportedReceipt,
        PreparedWorktree $worktree,
        string $treeSha,
        string $path,
        string $common,
    ): string {
        $receiptPath = realpath($reportedReceipt);
        $expectedDirectory = $common.'/orbit-checks/'.$worktree->headSha;

        if ($receiptPath === false || $receiptPath !== $reportedReceipt
            || is_link($reportedReceipt) || ! is_file($receiptPath)
            || basename($receiptPath) !== 'result.json'
            || dirname(dirname($receiptPath)) !== $expectedDirectory) {
            throw new OrbitRepositoryFailed('Orbit candidate check returned an invalid receipt path.');
        }

        $contents = file_get_contents($receiptPath);

        try {
            $receipt = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $receipt = null;
        }

        if (! is_array($receipt) || ! $this->validCandidateReceipt($receipt, $worktree, $treeSha, $path)) {
            throw new OrbitRepositoryFailed('Orbit candidate check returned an invalid receipt.');
        }

        return $receiptPath;
    }

    private function hasExactIssueWorktree(string $output, string $path, string $branch): bool
    {
        $records = preg_split('/\R\R+/', trim($output)) ?: [];
        $matches = [];

        foreach ($records as $record) {
            $values = [];

            foreach (preg_split('/\R/', $record) ?: [] as $line) {
                [$key, $value] = array_pad(explode(' ', $line, 2), 2, '');
                $values[$key] = $value;
            }

            if (($values['worktree'] ?? null) === $path || ($values['branch'] ?? null) === 'refs/heads/'.$branch) {
                $matches[] = $values;
            }
        }

        return count($matches) === 1
            && ($matches[0]['worktree'] ?? null) === $path
            && ($matches[0]['branch'] ?? null) === 'refs/heads/'.$branch;
    }

    /** @param array<mixed, mixed> $receipt */
    private function validCandidateReceipt(array $receipt, PreparedWorktree $worktree, string $treeSha, string $path): bool
    {
        $checks = $receipt['checks'] ?? null;

        if (($receipt['schema'] ?? null) !== 1 || ($receipt['role'] ?? null) !== 'builder'
            || ($receipt['candidate'] ?? null) !== $worktree->headSha
            || ($receipt['tree'] ?? null) !== $treeSha
            || realpath(is_string($receipt['worktree'] ?? null) ? $receipt['worktree'] : '') !== $path
            || ($receipt['passed'] ?? null) !== true || ($receipt['unchanged'] ?? null) !== true
            || ! is_array($checks) || count($checks) !== count(self::PROJECTS) * count(self::COMMANDS)) {
            return false;
        }

        $actual = [];

        foreach ($checks as $check) {
            if (! is_array($check) || ! is_string($check['project'] ?? null)
                || ! is_array($check['command'] ?? null) || ($check['exit_code'] ?? null) !== 0) {
                return false;
            }

            $command = [];

            foreach ($check['command'] as $part) {
                if (! is_string($part)) {
                    return false;
                }

                $command[] = $part;
            }

            $actual[] = $check['project'].'|'.implode("\0", $command);
        }

        $expected = [];

        foreach (self::PROJECTS as $project) {
            foreach (self::COMMANDS as $command) {
                $expected[] = $project.'|'.implode("\0", $command);
            }
        }

        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /** @param array<string, mixed> $payload */
    private function retainedIssueSnapshot(string $target, array $payload): ?string
    {
        if (is_link($target)) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot path is unsafe.');
        }

        if (! file_exists($target)) {
            return null;
        }

        $permissions = fileperms($target);

        if (! is_file($target) || $permissions === false || ($permissions & 0777) !== 0600) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot path is unsafe.');
        }

        $contents = file_get_contents($target);

        try {
            $existing = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $existing = null;
        }

        if ($contents === false || ! is_array($existing) || array_is_list($existing)
            || $this->canonicalize($existing) !== $this->canonicalize($payload)) {
            throw new OrbitRepositoryFailed('The existing Orbit issue snapshot conflicts with the fetched issue.');
        }

        return $contents;
    }

    private function issueSnapshotTarget(OrbitProjectConfig $config, PreparedWorktree $worktree): string
    {
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree->path);

        if ($root === false || $path === false || $path !== $worktree->path
            || ! str_starts_with($path, $root.'/')) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot worktree is invalid.');
        }

        $loop = $path.'/.loop';

        if (is_link($loop) || ! is_dir($loop) || realpath($loop) !== $loop) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot requires a regular .loop directory.');
        }

        return $loop.'/issue.json';
    }

    private function privateIssueSnapshotContents(string $target): string
    {
        if (is_link($target) || ! file_exists($target)) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot path is unsafe.');
        }

        $permissions = fileperms($target);

        if (! is_file($target) || $permissions === false || ($permissions & 0777) !== 0600) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot path is unsafe.');
        }

        $contents = file_get_contents($target);

        if ($contents === false) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be read.');
        }

        return $contents;
    }

    private function publishIssueSnapshot(string $loop, string $target, string $contents): void
    {
        $temporary = tempnam($loop, '.issue-');

        if ($temporary === false) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be staged.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)) {
                throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be staged.');
            }

            if (is_link($loop) || realpath($loop) !== $loop) {
                throw new OrbitRepositoryFailed('The Orbit issue snapshot directory changed during publication.');
            }

            @link($temporary, $target);
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
            usort($normalized, fn (mixed $left, mixed $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

            return $normalized;
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function canonicalJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The Orbit issue snapshot could not be compared.', 0, $exception);
        }
    }
}
