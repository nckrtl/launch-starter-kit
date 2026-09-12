<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Contracts\OrbitAbandonedWorktreeCleaner;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Contracts\OrbitMainCorrectnessInspector;
use App\Delivery\Contracts\OrbitMergeLineageVerifier;
use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Delivery\Contracts\OrbitProofTopologyCloser;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Contracts\OrbitStaleWorktreeRetirer;
use App\Delivery\Contracts\OrbitWorktreeCleaner;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\CleanedOrbitAbandonedWorktree;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitMainCorrectness;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\OrbitProofCloseout;
use App\Delivery\Data\OrbitStaleWorktree;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedOrbitAbandonedWorktreeCleanup;
use App\Delivery\Data\PreparedOrbitWorktreeRemoval;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Data\ReconciledOrbitPrimaryCheckout;
use App\Delivery\Data\RemovedOrbitWorktree;
use App\Delivery\Data\RequestedOrbitMainCacheRefresh;
use App\Delivery\Data\RetiredOrbitStaleWorktree;
use App\Delivery\Data\VerifiedOrbitImplementationOutcome;
use App\Delivery\Data\VerifiedOrbitMergeLineage;
use App\Delivery\Data\VerifiedOrbitPlanningArtifact;
use App\Delivery\Data\VerifiedOrbitPlanningOutcome;
use App\Delivery\Data\VerifiedOrbitPlanningRepository;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use FilesystemIterator;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * @phpstan-type StaleProtectedWorktree array{worktree: string, branch: ?string, prunable: bool}
 * @phpstan-type StaleWorktreeJournal array{
 *     schema: 1,
 *     state: 'prepared'|'retired',
 *     repository: string,
 *     worktree: string,
 *     issue_key: string,
 *     branch: string,
 *     head_sha: string,
 *     tree_sha: string,
 *     retained_ref: string,
 *     archive: string,
 *     archive_digest: string,
 *     protected_worktrees: list<StaleProtectedWorktree>,
 *     protected_branches: list<string>,
 *     prepared_at: string,
 *     retired_at: ?string
 * }
 */
final readonly class ProcessOrbitRepository implements OrbitAbandonedWorktreeCleaner, OrbitImplementationRepository, OrbitMainCacheRefreshRequester, OrbitMainCorrectnessInspector, OrbitMergeLineageVerifier, OrbitPrimaryCheckoutReconciler, OrbitProofTopologyCloser, OrbitRepository, OrbitStaleWorktreeRetirer, OrbitWorktreeCleaner
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
        } catch (InvalidArgumentException $exception) {
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

    public function prepareWorktreeRemoval(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
    ): PreparedOrbitWorktreeRemoval {
        $context = $this->worktreeCleanupContext(
            $config,
            $issueKey,
            $worktree,
            $branch,
            $candidateSha,
            $artifactSha,
            $proofCloseout,
            $cleanupAttemptId,
        );
        $this->refreshWorktreeCleanupState($context['repository']);
        $state = $this->inspectCleanupGitState(
            $context['repository'],
            $worktree,
            $branch,
            $candidateSha,
            $context['artifact_ref'],
            $artifactSha,
            'initial',
        );
        $archives = $this->proofArchiveHashes($context['repository'], $issueKey, $proofCloseout);

        return new PreparedOrbitWorktreeRemoval(
            repository: $context['repository'],
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            artifactRef: $context['artifact_ref'],
            artifactSha: $artifactSha,
            cleanupAttemptId: $cleanupAttemptId,
            proofAttemptId: $proofCloseout?->attemptId,
            protectedWorktrees: $this->unrelatedWorktrees($state['worktrees'], $worktree, $branch),
            protectedBranches: $this->unrelatedBranches($state['branches'], $branch),
            evidenceArchives: $archives,
            authorizedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function removeWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
        PreparedOrbitWorktreeRemoval $authorization,
        bool $resume,
    ): RemovedOrbitWorktree {
        $context = $this->worktreeCleanupContext(
            $config,
            $issueKey,
            $worktree,
            $branch,
            $candidateSha,
            $artifactSha,
            $proofCloseout,
            $cleanupAttemptId,
        );
        $repository = $context['repository'];
        $script = $context['script'];
        $artifactRef = $context['artifact_ref'];

        if ($authorization->repository !== $repository
            || $authorization->worktree !== $worktree
            || $authorization->issueKey !== $issueKey
            || $authorization->branch !== $branch
            || $authorization->candidateSha !== $candidateSha
            || $authorization->artifactRef !== $artifactRef
            || $authorization->artifactSha !== $artifactSha
            || $authorization->cleanupAttemptId !== $cleanupAttemptId
            || $authorization->proofAttemptId !== $proofCloseout?->attemptId) {
            throw new OrbitRepositoryFailed(
                'The retained Orbit worktree cleanup authorization is inconsistent.',
            );
        }

        $this->refreshWorktreeCleanupState($repository);

        $before = $this->inspectCleanupGitState(
            $repository,
            $worktree,
            $branch,
            $candidateSha,
            $artifactRef,
            $artifactSha,
            $resume ? 'resume' : 'initial',
        );
        $archives = $this->proofArchiveHashes($repository, $issueKey, $proofCloseout);

        if ($this->unrelatedWorktrees($before['worktrees'], $worktree, $branch)
                !== $authorization->protectedWorktrees
            || $this->unrelatedBranches($before['branches'], $branch)
                !== $authorization->protectedBranches
            || $archives !== $authorization->evidenceArchives) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup state changed after authorization.',
            );
        }

        try {
            $result = Process::path($repository)->timeout(300)->run([$script, $issueKey]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup adapter could not run.',
                previous: $exception,
            );
        }

        if ($result->failed()
            || preg_match('/(?:^|\R)Removed '.preg_quote($branch, '/').'\s*$/', $result->output()) !== 1) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit worktree cleanup failed: '.$details);
        }

        if (file_exists($worktree) || is_link($worktree)) {
            throw new OrbitRepositoryFailed('The Orbit worktree path remains after cleanup.');
        }

        $after = $this->inspectCleanupGitState(
            $repository,
            $worktree,
            $branch,
            $candidateSha,
            $artifactRef,
            $artifactSha,
            'complete',
        );

        if ($this->unrelatedWorktrees($after['worktrees'], $worktree, $branch)
                !== $authorization->protectedWorktrees) {
            throw new OrbitRepositoryFailed('An unrelated Orbit worktree changed during cleanup.');
        }

        if ($this->unrelatedBranches($after['branches'], $branch)
                !== $authorization->protectedBranches) {
            throw new OrbitRepositoryFailed('An unrelated Orbit branch changed during cleanup.');
        }

        if ($authorization->evidenceArchives
                !== $this->proofArchiveHashes($repository, $issueKey, $proofCloseout)) {
            throw new OrbitRepositoryFailed('The Orbit proof archives changed during worktree cleanup.');
        }

        return new RemovedOrbitWorktree(
            repository: $repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            artifactRef: $artifactRef,
            artifactSha: $artifactSha,
            cleanupAttemptId: $cleanupAttemptId,
            proofAttemptId: $proofCloseout?->attemptId,
            evidenceArchives: $archives,
            removedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function prepareAbandonedWorktreeCleanup(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
    ): PreparedOrbitAbandonedWorktreeCleanup {
        $context = $this->abandonedWorktreeCleanupContext(
            $config,
            $issueKey,
            $worktree,
            $branch,
            $candidateSha,
            $cleanupAttemptId,
        );
        $this->refreshWorktreeCleanupState($context['repository']);
        $state = $this->inspectAbandonedWorktreeState(
            $context['repository'],
            $worktree,
            $branch,
            $candidateSha,
            'prepare',
        );

        return new PreparedOrbitAbandonedWorktreeCleanup(
            repository: $context['repository'],
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            cleanupAttemptId: $cleanupAttemptId,
            disposition: $state['target_present'] ? 'remove' : 'already_absent',
            protectedWorktrees: $this->unrelatedWorktrees($state['worktrees'], $worktree, $branch),
            protectedBranches: $this->unrelatedBranches($state['branches'], $branch),
            authorizedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    public function cleanupAbandonedWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
        bool $resume,
    ): CleanedOrbitAbandonedWorktree {
        $context = $this->abandonedWorktreeCleanupContext(
            $config,
            $issueKey,
            $worktree,
            $branch,
            $candidateSha,
            $cleanupAttemptId,
        );
        $repository = $context['repository'];

        if ($authorization->repository !== $repository
            || $authorization->worktree !== $worktree
            || $authorization->issueKey !== $issueKey
            || $authorization->branch !== $branch
            || $authorization->candidateSha !== $candidateSha
            || $authorization->cleanupAttemptId !== $cleanupAttemptId) {
            throw new OrbitRepositoryFailed(
                'The retained abandoned Orbit worktree cleanup authorization is inconsistent.',
            );
        }

        $this->refreshWorktreeCleanupState($repository);
        $expectedState = $authorization->disposition === 'already_absent'
            ? 'absent'
            : ($resume ? 'resume' : 'initial');
        $before = $this->inspectAbandonedWorktreeState(
            $repository,
            $worktree,
            $branch,
            $candidateSha,
            $expectedState,
        );

        if ($before['target_prunable']) {
            $this->removeAuthorizedAbandonedWorktreeRegistration($repository, $worktree);
            $before = $this->inspectAbandonedWorktreeState(
                $repository,
                $worktree,
                $branch,
                $candidateSha,
                'resume',
            );

            if ($before['target_prunable']) {
                throw new OrbitRepositoryFailed(
                    'The authorized abandoned Orbit worktree registration remains prunable.',
                );
            }
        }

        if ($this->unrelatedWorktrees($before['worktrees'], $worktree, $branch)
                !== $authorization->protectedWorktrees
            || $this->unrelatedBranches($before['branches'], $branch)
                !== $authorization->protectedBranches) {
            throw new OrbitRepositoryFailed(
                'The abandoned Orbit worktree cleanup state changed after authorization.',
            );
        }

        if (! $before['target_present'] && ! $before['branch_present']) {
            return new CleanedOrbitAbandonedWorktree(
                repository: $repository,
                worktree: $worktree,
                issueKey: $issueKey,
                branch: $branch,
                candidateSha: $candidateSha,
                cleanupAttemptId: $cleanupAttemptId,
                disposition: 'already_absent',
                recordedAt: gmdate('Y-m-d\TH:i:s\Z'),
            );
        }

        try {
            $result = Process::path($repository)->timeout(300)->run([
                $context['script'],
                $issueKey,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The abandoned Orbit worktree cleanup adapter could not run.',
                previous: $exception,
            );
        }

        if ($result->failed()
            || preg_match('/(?:^|\R)Removed '.preg_quote($branch, '/').'\s*$/', $result->output()) !== 1) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Abandoned Orbit worktree cleanup failed: '.$details);
        }

        if (file_exists($worktree) || is_link($worktree)) {
            throw new OrbitRepositoryFailed('The abandoned Orbit worktree path remains after cleanup.');
        }

        $after = $this->inspectAbandonedWorktreeState(
            $repository,
            $worktree,
            $branch,
            $candidateSha,
            'complete',
        );

        if ($this->unrelatedWorktrees($after['worktrees'], $worktree, $branch)
                !== $authorization->protectedWorktrees
            || $this->unrelatedBranches($after['branches'], $branch)
                !== $authorization->protectedBranches) {
            throw new OrbitRepositoryFailed(
                'An unrelated Orbit worktree or branch changed during abandoned cleanup.',
            );
        }

        return new CleanedOrbitAbandonedWorktree(
            repository: $repository,
            worktree: $worktree,
            issueKey: $issueKey,
            branch: $branch,
            candidateSha: $candidateSha,
            cleanupAttemptId: $cleanupAttemptId,
            disposition: 'removed',
            recordedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /** @return array{repository: string, script: string} */
    private function abandonedWorktreeCleanupContext(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $cleanupAttemptId,
    ): array {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree);
        $commonPath = $repository === false ? '' : $repository.'/.git';
        $common = $repository === false ? false : realpath($commonPath);
        $scriptPath = $repository === false ? '' : $repository.'/bin/worktree-remove';
        $script = $repository === false ? false : realpath($scriptPath);
        $expectedBranch = Str::lower($issueKey);

        if ($repository === false || $repository !== $config->repository
            || $root === false || $root !== $config->worktreeRoot
            || $worktree !== $root.'/'.$expectedBranch
            || ($path !== false && $path !== $worktree)
            || ($path === false && (file_exists($worktree) || is_link($worktree)))
            || $common === false || $common !== $commonPath
            || ! is_dir($common) || is_link($commonPath)
            || ($path !== false
                && (! is_file($path.'/.git') || is_link($path.'/.git')))
            || $branch !== $expectedBranch
            || $script === false || $script !== $scriptPath
            || is_link($scriptPath) || ! is_executable($script)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $cleanupAttemptId) !== 1) {
            throw new OrbitRepositoryFailed(
                'The configured abandoned Orbit worktree cleanup adapter is unavailable.',
            );
        }

        if ($path !== false) {
            try {
                $worktreeCommon = Process::path($path)->timeout(10)->run([
                    'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
                ]);
            } catch (RuntimeException $exception) {
                throw new OrbitRepositoryFailed(
                    'The abandoned Orbit worktree common directory could not be inspected.',
                    previous: $exception,
                );
            }

            $resolvedWorktreeCommon = $worktreeCommon->failed()
                ? false
                : realpath(trim($worktreeCommon->output()));

            if ($resolvedWorktreeCommon === false || $resolvedWorktreeCommon !== $common) {
                throw new OrbitRepositoryFailed(
                    'The abandoned Orbit worktree no longer belongs to the configured repository.',
                );
            }
        }

        return ['repository' => $repository, 'script' => $script];
    }

    /**
     * @param  'prepare'|'initial'|'resume'|'absent'|'complete'  $expectedState
     * @return array{
     *     worktrees: list<array{worktree: string, head: string, branch: ?string, prunable: bool}>,
     *     branches: array<string, string>,
     *     target_present: bool,
     *     target_prunable: bool,
     *     branch_present: bool
     * }
     */
    private function inspectAbandonedWorktreeState(
        string $repository,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $expectedState,
    ): array {
        try {
            $inventory = Process::path($repository)->timeout(10)->run([
                'git', 'worktree', 'list', '--porcelain', '-z',
            ]);
            $branchInventory = Process::path($repository)->timeout(10)->run([
                'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The abandoned Orbit worktree cleanup state could not be inspected.',
                previous: $exception,
            );
        }

        if ($inventory->failed() || $branchInventory->failed()) {
            throw new OrbitRepositoryFailed(
                'The abandoned Orbit worktree cleanup state could not be inspected.',
            );
        }

        $worktrees = $this->parseWorktreeInventory($inventory->output());
        $branches = $this->parseBranchInventory($branchInventory->output());
        $branchRef = 'refs/heads/'.$branch;
        $matchingPaths = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['worktree'] === $worktree,
        ));
        $matchingBranches = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['branch'] === $branchRef,
        ));
        $exactWorktrees = array_values(array_filter(
            $matchingPaths,
            static fn (array $item): bool => $item['branch'] === $branchRef,
        ));
        $matchingIssueBranches = array_values(array_filter(
            array_keys($branches),
            static fn (string $ref): bool => $ref === $branchRef
                || str_starts_with($ref, $branchRef.'-'),
        ));
        $targetRegistered = count($exactWorktrees) === 1;
        $targetPrunable = $targetRegistered && $exactWorktrees[0]['prunable'];
        $targetPresent = $targetRegistered && ! $targetPrunable;
        $branchPresent = array_key_exists($branchRef, $branches);
        $targetAbsent = ! $targetRegistered && ! $branchPresent;
        $validTargetState = match ($expectedState) {
            'prepare' => ($targetPresent && $branchPresent) || $targetAbsent,
            'initial' => $targetPresent && $branchPresent,
            'resume' => ($targetPresent && $branchPresent)
                || ($targetPrunable && $branchPresent
                    && ! file_exists($worktree) && ! is_link($worktree))
                || (! $targetRegistered && ($branchPresent || $targetAbsent)),
            'absent', 'complete' => $targetAbsent,
        };
        $primary = $worktrees[0]['worktree'] ?? null;

        if ($primary !== $repository
            || count($matchingPaths) !== ($targetRegistered ? 1 : 0)
            || count($matchingBranches) !== ($targetRegistered ? 1 : 0)
            || count($exactWorktrees) > 1
            || ($targetPrunable && $exactWorktrees[0]['head'] !== $candidateSha)
            || ! $validTargetState
            || count($matchingIssueBranches) !== ($branchPresent ? 1 : 0)
            || (! $targetRegistered && (file_exists($worktree) || is_link($worktree)))) {
            throw new OrbitRepositoryFailed('The abandoned Orbit worktree cleanup state is inconsistent.');
        }

        if ($branchPresent) {
            $this->assertCleanupBranchSafety(
                $repository,
                $targetPresent ? $worktree : null,
                $branch,
                $candidateSha,
            );
        }

        foreach ($this->unrelatedWorktrees($worktrees, $worktree, $branch) as $unrelated) {
            if ($unrelated['prunable']) {
                throw new OrbitRepositoryFailed(
                    'An unrelated prunable Orbit worktree makes abandoned cleanup unsafe.',
                );
            }
        }

        return [
            'worktrees' => $worktrees,
            'branches' => $branches,
            'target_present' => $targetPresent,
            'target_prunable' => $targetPrunable,
            'branch_present' => $branchPresent,
        ];
    }

    private function removeAuthorizedAbandonedWorktreeRegistration(
        string $repository,
        string $worktree,
    ): void {
        try {
            $result = Process::path($repository)->timeout(30)->run([
                'git', 'worktree', 'remove', $worktree,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The authorized abandoned Orbit worktree registration could not be removed.',
                previous: $exception,
            );
        }

        if ($result->failed()) {
            throw new OrbitRepositoryFailed(
                'The authorized abandoned Orbit worktree registration could not be removed.',
            );
        }
    }

    /** @return array{repository: string, script: string, artifact_ref: string} */
    private function worktreeCleanupContext(
        OrbitProjectConfig $config,
        string $issueKey,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactSha,
        ?OrbitProofCloseout $proofCloseout,
        string $cleanupAttemptId,
    ): array {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $path = realpath($worktree);
        $scriptPath = $repository === false ? '' : $repository.'/bin/worktree-remove';
        $script = $repository === false ? false : realpath($scriptPath);
        $expectedBranch = Str::lower($issueKey);
        $artifactRef = "refs/tags/loop/{$expectedBranch}/{$candidateSha}";

        if ($repository === false || $repository !== $config->repository
            || $root === false || $root !== $config->worktreeRoot
            || $worktree !== $root.'/'.$expectedBranch
            || ($path !== false && $path !== $worktree)
            || ($path === false && (file_exists($worktree) || is_link($worktree)))
            || $branch !== $expectedBranch
            || $script === false || $script !== $scriptPath
            || is_link($scriptPath) || ! is_executable($script)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1
            || preg_match('/^[a-f0-9]{32}$/', $cleanupAttemptId) !== 1
            || ($proofCloseout !== null
                && (! $proofCloseout->complete()
                    || $proofCloseout->issueKey !== $issueKey
                    || $proofCloseout->candidateSha !== $candidateSha
                    || $proofCloseout->artifactSha !== $artifactSha))) {
            throw new OrbitRepositoryFailed('The configured Orbit worktree cleanup adapter is unavailable.');
        }

        return [
            'repository' => $repository,
            'script' => $script,
            'artifact_ref' => $artifactRef,
        ];
    }

    private function refreshWorktreeCleanupState(string $repository): void
    {
        try {
            $fetch = Process::path($repository)->timeout(120)->run([
                'git', 'fetch', '--prune', 'origin',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup state could not be refreshed.',
                previous: $exception,
            );
        }

        if ($fetch->failed()) {
            $details = trim($fetch->errorOutput()) ?: trim($fetch->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Orbit worktree cleanup refresh failed: '.$details);
        }
    }

    /** @return array<string, string> */
    private function proofArchiveHashes(
        string $repository,
        string $issueKey,
        ?OrbitProofCloseout $proofCloseout,
    ): array {
        if ($proofCloseout === null) {
            return [];
        }

        $proofAttemptId = $proofCloseout->attemptId;

        $relativePaths = [
            ".e2e/proof-evidence/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-review-evaluation/{$issueKey}/{$proofAttemptId}.json",
            ".e2e/proof-closeout/{$issueKey}/{$proofAttemptId}.json",
        ];
        $hashes = [];

        foreach ($relativePaths as $relativePath) {
            $path = $repository.'/'.$relativePath;
            $resolved = realpath($path);

            if ($resolved === false || $resolved !== $path || ! is_file($resolved) || is_link($path)) {
                throw new OrbitRepositoryFailed('The Orbit proof archive is unavailable before worktree cleanup.');
            }

            $contents = file_get_contents($resolved);

            if ($contents === false) {
                throw new OrbitRepositoryFailed('The Orbit proof archive could not be read before worktree cleanup.');
            }

            $hashes[$relativePath] = hash('sha256', $contents);
        }

        $closeoutPath = ".e2e/proof-closeout/{$issueKey}/{$proofAttemptId}.json";

        try {
            $closeout = json_decode(
                (string) file_get_contents($repository.'/'.$closeoutPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof closeout archive is invalid before worktree cleanup.',
                previous: $exception,
            );
        }

        if ($closeout !== $proofCloseout->toArray()) {
            throw new OrbitRepositoryFailed(
                'The Orbit proof closeout archive does not match the retained landing evidence.',
            );
        }

        return $hashes;
    }

    /**
     * @param  'initial'|'resume'|'complete'  $expectedState
     * @return array{
     *     worktrees: list<array{worktree: string, head: string, branch: ?string, prunable: bool}>,
     *     branches: array<string, string>
     * }
     */
    private function inspectCleanupGitState(
        string $repository,
        string $worktree,
        string $branch,
        string $candidateSha,
        string $artifactRef,
        string $artifactSha,
        string $expectedState,
    ): array {
        try {
            $inventory = Process::path($repository)->timeout(10)->run([
                'git', 'worktree', 'list', '--porcelain', '-z',
            ]);
            $branchInventory = Process::path($repository)->timeout(10)->run([
                'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
            ]);
            $localArtifact = Process::path($repository)->timeout(10)->run([
                'git', 'show-ref', '--verify', '--hash', $artifactRef,
            ]);
            $remoteArtifact = Process::path($repository)->timeout(30)->run([
                'git', 'ls-remote', '--refs', 'origin', $artifactRef,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup state could not be inspected.',
                previous: $exception,
            );
        }

        $worktrees = $inventory->successful() ? $this->parseWorktreeInventory($inventory->output()) : [];
        $matchingPaths = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['worktree'] === $worktree,
        ));
        $matchingBranches = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['branch'] === 'refs/heads/'.$branch,
        ));
        $exactWorktrees = array_values(array_filter(
            $matchingPaths,
            static fn (array $item): bool => $item['branch'] === 'refs/heads/'.$branch,
        ));
        $branches = $branchInventory->successful()
            ? $this->parseBranchInventory($branchInventory->output())
            : [];
        $matchingIssueBranches = array_values(array_filter(
            array_keys($branches),
            static fn (string $ref): bool => $ref === 'refs/heads/'.$branch
                || str_starts_with($ref, 'refs/heads/'.$branch.'-'),
        ));
        $localBranchExists = in_array('refs/heads/'.$branch, $matchingIssueBranches, true);
        $targetPresent = count($exactWorktrees) === 1;
        $remoteFields = preg_split('/\s+/', trim($remoteArtifact->output())) ?: [];
        $primary = $worktrees[0]['worktree'] ?? null;
        $validTargetState = match ($expectedState) {
            'initial' => $targetPresent && $localBranchExists,
            'resume' => ($targetPresent && $localBranchExists) || ! $targetPresent,
            'complete' => ! $targetPresent && ! $localBranchExists,
        };

        if ($inventory->failed()
            || $branchInventory->failed()
            || $primary !== $repository
            || count($matchingPaths) !== ($targetPresent ? 1 : 0)
            || count($matchingBranches) !== ($targetPresent ? 1 : 0)
            || count($exactWorktrees) > 1
            || ! $validTargetState
            || count($matchingIssueBranches) !== ($localBranchExists ? 1 : 0)
            || $localArtifact->failed() || trim($localArtifact->output()) !== $artifactSha
            || $remoteArtifact->failed() || $remoteFields !== [$artifactSha, $artifactRef]) {
            throw new OrbitRepositoryFailed('The Orbit worktree cleanup state is inconsistent.');
        }

        if ($expectedState !== 'complete' && $localBranchExists) {
            $this->assertCleanupBranchSafety(
                $repository,
                $targetPresent ? $worktree : null,
                $branch,
                $candidateSha,
            );
        }

        foreach ($this->unrelatedWorktrees($worktrees, $worktree, $branch) as $unrelated) {
            if ($unrelated['prunable']) {
                throw new OrbitRepositoryFailed(
                    'An unrelated prunable Orbit worktree makes cleanup unsafe.',
                );
            }
        }

        return ['worktrees' => $worktrees, 'branches' => $branches];
    }

    private function assertCleanupBranchSafety(
        string $repository,
        ?string $worktree,
        string $branch,
        string $candidateSha,
    ): void {
        try {
            $branchHead = Process::path($repository)->timeout(10)->run([
                'git', 'rev-parse', 'refs/heads/'.$branch,
            ]);
            $ancestor = Process::path($repository)->timeout(10)->run([
                'git', 'merge-base', '--is-ancestor', 'refs/heads/'.$branch, 'origin/main',
            ]);
            $status = $worktree === null ? null : Process::path($worktree)->timeout(10)->run([
                'git', 'status', '--porcelain',
            ]);
            $worktreeHead = $worktree === null ? null : Process::path($worktree)->timeout(10)->run([
                'git', 'rev-parse', 'HEAD',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup candidate could not be inspected.',
                previous: $exception,
            );
        }

        if ($branchHead->failed() || trim($branchHead->output()) !== $candidateSha
            || $ancestor->failed()
            || ($status !== null && ($status->failed() || trim($status->output()) !== ''))
            || ($worktreeHead !== null
                && ($worktreeHead->failed() || trim($worktreeHead->output()) !== $candidateSha))) {
            throw new OrbitRepositoryFailed(
                'The Orbit worktree cleanup candidate is dirty, changed, or not merged.',
            );
        }
    }

    /**
     * @param  list<array{worktree: string, head: string, branch: ?string, prunable: bool}>  $worktrees
     * @return list<array{worktree: string, head: string, branch: ?string, prunable: bool}>
     */
    private function unrelatedWorktrees(array $worktrees, string $worktree, string $branch): array
    {
        return array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['worktree'] !== $worktree
                && $item['branch'] !== 'refs/heads/'.$branch,
        ));
    }

    /**
     * @param  array<string, string>  $branches
     * @return array<string, string>
     */
    private function unrelatedBranches(array $branches, string $branch): array
    {
        unset($branches['refs/heads/'.$branch]);

        return $branches;
    }

    /**
     * @param  list<array{worktree: string, head: string, branch: ?string, prunable: bool}>  $worktrees
     * @return list<array{worktree: string, branch: ?string, prunable: bool}>
     */
    private function unrelatedStaleWorktreeTopology(
        array $worktrees,
        string $worktree,
        string $branch,
    ): array {
        return array_map(
            static fn (array $item): array => [
                'worktree' => $item['worktree'],
                'branch' => $item['branch'],
                'prunable' => $item['prunable'],
            ],
            $this->unrelatedWorktrees($worktrees, $worktree, $branch),
        );
    }

    /**
     * @param  array<string, string>  $branches
     * @return list<string>
     */
    private function unrelatedStaleBranchTopology(array $branches, string $branch): array
    {
        $refs = array_keys($this->unrelatedBranches($branches, $branch));
        sort($refs);

        return $refs;
    }

    /** @return array<string, string> */
    private function parseBranchInventory(string $output): array
    {
        $lines = trim($output) === '' ? [] : preg_split('/\R/', trim($output));
        $branches = [];

        foreach ($lines ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{40}) (refs\/heads\/[^\s]+)$/', $line, $matches) !== 1
                || array_key_exists($matches[2], $branches)) {
                throw new OrbitRepositoryFailed('The Orbit branch inventory is malformed.');
            }

            $branches[$matches[2]] = $matches[1];
        }

        return $branches;
    }

    /** @return list<array{worktree: string, head: string, branch: ?string, prunable: bool}> */
    private function parseWorktreeInventory(string $output): array
    {
        $records = trim($output, "\0\r\n") === ''
            ? []
            : explode("\0\0", trim($output, "\0\r\n"));
        $worktrees = [];

        foreach ($records as $record) {
            $fields = explode("\0", $record);
            $paths = array_values(array_filter(
                $fields,
                static fn (string $field): bool => str_starts_with($field, 'worktree '),
            ));
            $branches = array_values(array_filter(
                $fields,
                static fn (string $field): bool => str_starts_with($field, 'branch '),
            ));
            $heads = array_values(array_filter(
                $fields,
                static fn (string $field): bool => str_starts_with($field, 'HEAD '),
            ));
            $prunable = array_values(array_filter(
                $fields,
                static fn (string $field): bool => str_starts_with($field, 'prunable'),
            ));

            if (count($paths) !== 1 || count($heads) !== 1 || count($branches) > 1
                || count($prunable) > 1 || $paths[0] === 'worktree '
                || preg_match('/^HEAD [a-f0-9]{40}$/', $heads[0]) !== 1) {
                throw new OrbitRepositoryFailed('The Orbit worktree cleanup inventory is malformed.');
            }

            $worktrees[] = [
                'worktree' => substr($paths[0], strlen('worktree ')),
                'head' => substr($heads[0], strlen('HEAD ')),
                'branch' => $branches === [] ? null : substr($branches[0], strlen('branch ')),
                'prunable' => $prunable !== [],
            ];
        }

        return $worktrees;
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

    public function inspectStaleWorktree(
        OrbitProjectConfig $config,
        string $issueKey,
    ): ?OrbitStaleWorktree {
        $context = $this->staleWorktreeContext($config, $issueKey);
        $journal = $this->readStaleWorktreeJournal($context, $issueKey);

        if ($journal !== null) {
            $this->verifyStaleWorktreeArchive($journal);

            return $this->staleWorktreeFromJournal($journal);
        }

        $state = $this->inspectStaleWorktreeGitState(
            $context['repository'],
            $issueKey,
        );

        if ($state['target'] === null) {
            return null;
        }

        $target = $state['target'];
        $this->assertStaleWorktreePath($context['common'], $target['worktree']);
        $treeSha = $this->staleWorktreeTree($target['worktree']);

        return new OrbitStaleWorktree(
            repository: $context['repository'],
            worktree: $target['worktree'],
            issueKey: $issueKey,
            branch: Str::after($target['branch'], 'refs/heads/'),
            headSha: $target['head'],
            treeSha: $treeSha,
        );
    }

    public function retireStaleWorktree(
        OrbitProjectConfig $config,
        OrbitStaleWorktree $worktree,
    ): RetiredOrbitStaleWorktree {
        $context = $this->staleWorktreeContext($config, $worktree->issueKey);
        $journal = $this->readStaleWorktreeJournal($context, $worktree->issueKey);

        if ($journal === null) {
            $current = $this->inspectStaleWorktree($config, $worktree->issueKey);

            if ($current === null || $current != $worktree) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree changed before retirement.');
            }

            $state = $this->inspectStaleWorktreeGitState(
                $context['repository'],
                $worktree->issueKey,
            );
            $capture = $this->captureStaleWorktree($worktree);
            $archiveDigest = hash('sha256', $this->canonicalJson($capture['metadata']));
            $archive = $context['directory'].'/retired-worktrees/'.$worktree->headSha.'/'.$archiveDigest;
            $retainedRef = 'refs/orbit-delivery/retired-worktrees/'
                .Str::lower($worktree->issueKey).'/'.$worktree->headSha;

            $this->retainStaleWorktreeHead(
                $context['repository'],
                $retainedRef,
                $worktree->headSha,
            );
            $this->publishStaleWorktreeArchive($archive, $capture);

            $journal = [
                'schema' => 1,
                'state' => 'prepared',
                'repository' => $context['repository'],
                'worktree' => $worktree->worktree,
                'issue_key' => $worktree->issueKey,
                'branch' => $worktree->branch,
                'head_sha' => $worktree->headSha,
                'tree_sha' => $worktree->treeSha,
                'retained_ref' => $retainedRef,
                'archive' => $archive,
                'archive_digest' => $archiveDigest,
                'protected_worktrees' => $this->unrelatedStaleWorktreeTopology(
                    $state['worktrees'],
                    $worktree->worktree,
                    $worktree->branch,
                ),
                'protected_branches' => $this->unrelatedStaleBranchTopology(
                    $state['branches'],
                    $worktree->branch,
                ),
                'prepared_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'retired_at' => null,
            ];
            $this->writeStaleWorktreeJournal($context['journal'], $journal);
        } else {
            $retained = $this->staleWorktreeFromJournal($journal);

            if ($retained != $worktree) {
                throw new OrbitRepositoryFailed('The retained stale Orbit worktree retirement is inconsistent.');
            }
        }

        $this->verifyStaleWorktreeArchive($journal);
        $this->verifyRetainedStaleWorktreeHead($journal);
        $mutated = false;
        $state = $this->inspectStaleRetirementState($journal);

        if ($state['target_prunable']) {
            $this->removeStaleWorktreeRegistration($journal);
            $mutated = true;
            $state = $this->inspectStaleRetirementState($journal);
        }

        if ($state['target_present']) {
            $this->assertStaleWorktreePath($context['common'], $worktree->worktree);
            $capture = $this->captureStaleWorktree($worktree);

            if (! hash_equals(
                $journal['archive_digest'],
                hash('sha256', $this->canonicalJson($capture['metadata'])),
            )) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree changed after it was archived.');
            }

            $this->removeStaleWorktree($journal);
            $mutated = true;
            $state = $this->inspectStaleRetirementState($journal);
        }

        if ($state['branch_present']) {
            $this->removeStaleWorktreeBranch($journal);
            $mutated = true;
            $state = $this->inspectStaleRetirementState($journal);
        }

        if ($state['target_present'] || $state['target_prunable'] || $state['branch_present']
            || file_exists($worktree->worktree) || is_link($worktree->worktree)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree remains after retirement.');
        }

        $this->verifyStaleWorktreeArchive($journal);
        $this->verifyRetainedStaleWorktreeHead($journal);

        $wasRetired = $journal['state'] === 'retired';
        $retiredAt = $journal['retired_at'];

        if (! $wasRetired) {
            $journal['state'] = 'retired';
            $retiredAt = gmdate('Y-m-d\TH:i:s\Z');
            $journal['retired_at'] = $retiredAt;
            $this->writeStaleWorktreeJournal($context['journal'], $journal);
        }

        if ($retiredAt === null) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is inconsistent.');
        }

        return new RetiredOrbitStaleWorktree(
            repository: $journal['repository'],
            worktree: $journal['worktree'],
            issueKey: $journal['issue_key'],
            branch: $journal['branch'],
            headSha: $journal['head_sha'],
            treeSha: $journal['tree_sha'],
            retainedRef: $journal['retained_ref'],
            archive: $journal['archive'],
            archiveDigest: $journal['archive_digest'],
            disposition: $wasRetired && ! $mutated ? 'already_retired' : 'retired',
            retiredAt: $retiredAt,
        );
    }

    /** @return array{repository: string, common: string, directory: string, journal: string} */
    private function staleWorktreeContext(OrbitProjectConfig $config, string $issueKey): array
    {
        $repository = realpath($config->repository);
        $root = realpath($config->worktreeRoot);
        $commonPath = $repository === false ? '' : $repository.'/.git';
        $common = $repository === false ? false : realpath($commonPath);
        $directory = $common === false ? '' : $common.'/orbit-delivery/v1/'.Str::lower($issueKey);

        if ($repository === false || $repository !== $config->repository
            || $root === false || $root !== $config->worktreeRoot
            || $common === false || $common !== $commonPath || ! is_dir($common)
            || is_link($commonPath)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || ! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
            throw new OrbitRepositoryFailed('The configured stale Orbit worktree retirement is unavailable.');
        }

        return [
            'repository' => $repository,
            'common' => $common,
            'directory' => $directory,
            'journal' => $directory.'/stale-worktree-retirement.json',
        ];
    }

    /**
     * @return array{
     *     worktrees: list<array{worktree: string, head: string, branch: ?string, prunable: bool}>,
     *     branches: array<string, string>,
     *     target: array{worktree: string, head: string, branch: string, prunable: bool}|null
     * }
     */
    private function inspectStaleWorktreeGitState(string $repository, string $issueKey): array
    {
        [$worktrees, $branches] = $this->staleWorktreeInventories($repository);
        $canonical = 'refs/heads/'.Str::lower($issueKey);
        $prefix = $canonical.'-';
        $matchingWorktrees = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => is_string($item['branch'])
                && ($item['branch'] === $canonical || str_starts_with($item['branch'], $prefix)),
        ));
        $matchingBranches = array_filter(
            $branches,
            static fn (string $sha, string $ref): bool => $ref === $canonical
                || str_starts_with($ref, $prefix),
            ARRAY_FILTER_USE_BOTH,
        );
        $primary = $worktrees[0]['worktree'] ?? null;

        foreach ($worktrees as $item) {
            if ($item['prunable']) {
                throw new OrbitRepositoryFailed('A prunable Orbit worktree makes stale retirement unsafe.');
            }
        }

        if ($primary !== $repository
            || count($matchingWorktrees) > 1
            || count($matchingBranches) > 1
            || count($matchingWorktrees) !== count($matchingBranches)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree inventory is ambiguous.');
        }

        if ($matchingWorktrees === []) {
            return ['worktrees' => $worktrees, 'branches' => $branches, 'target' => null];
        }

        $target = $matchingWorktrees[0];
        $branch = $target['branch'];

        if ($branch === $canonical
            || ! array_key_exists($branch, $matchingBranches)
            || $matchingBranches[$branch] !== $target['head']) {
            return ['worktrees' => $worktrees, 'branches' => $branches, 'target' => null];
        }

        /** @var array{worktree: string, head: string, branch: string, prunable: bool} $target */
        return ['worktrees' => $worktrees, 'branches' => $branches, 'target' => $target];
    }

    /**
     * @return array{
     *     0: list<array{worktree: string, head: string, branch: ?string, prunable: bool}>,
     *     1: array<string, string>
     * }
     */
    private function staleWorktreeInventories(string $repository): array
    {
        try {
            $inventory = Process::path($repository)->timeout(10)->run([
                'git', 'worktree', 'list', '--porcelain', '-z',
            ]);
            $branches = Process::path($repository)->timeout(10)->run([
                'git', 'for-each-ref', '--format=%(objectname) %(refname)', 'refs/heads/',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree state could not be inspected.',
                previous: $exception,
            );
        }

        if ($inventory->failed() || $branches->failed()) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree state could not be inspected.');
        }

        return [
            $this->parseWorktreeInventory($inventory->output()),
            $this->parseBranchInventory($branches->output()),
        ];
    }

    private function assertStaleWorktreePath(string $common, string $worktree): void
    {
        $resolved = realpath($worktree);

        if ($resolved === false || $resolved !== $worktree || ! is_dir($resolved)
            || is_link($worktree) || ! is_file($worktree.'/.git') || is_link($worktree.'/.git')) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree path is unsafe.');
        }

        try {
            $result = Process::path($worktree)->timeout(10)->run([
                'git', 'rev-parse', '--path-format=absolute', '--git-common-dir',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree common directory could not be inspected.',
                previous: $exception,
            );
        }

        $resolvedCommon = $result->failed() ? false : realpath(trim($result->output()));

        if ($resolvedCommon === false || $resolvedCommon !== $common) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree does not belong to the configured repository.',
            );
        }
    }

    private function staleWorktreeTree(string $worktree): string
    {
        try {
            $result = Process::path($worktree)->timeout(10)->run([
                'git', 'rev-parse', 'HEAD^{tree}',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree tree could not be inspected.',
                previous: $exception,
            );
        }

        $tree = trim($result->output());

        if ($result->failed() || preg_match('/^[a-f0-9]{40}$/', $tree) !== 1) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree tree is invalid.');
        }

        return $tree;
    }

    /**
     * @return array{
     *     metadata: array<string, mixed>,
     *     patch: string,
     *     untracked: array<string, string>,
     *     loop: array<string, string>
     * }
     */
    private function captureStaleWorktree(OrbitStaleWorktree $worktree): array
    {
        try {
            $head = Process::path($worktree->worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $tree = Process::path($worktree->worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD^{tree}']);
            $status = Process::path($worktree->worktree)->timeout(30)->run([
                'git', 'status', '--porcelain=v1', '-z', '--untracked-files=all', '--ignored=no',
            ]);
            $patch = Process::path($worktree->worktree)->timeout(60)->run([
                'git', 'diff', '--binary', '--no-ext-diff', 'HEAD', '--', '.',
            ]);
            $untracked = Process::path($worktree->worktree)->timeout(30)->run([
                'git', 'ls-files', '--others', '--exclude-standard', '-z',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree could not be captured.',
                previous: $exception,
            );
        }

        if ($head->failed() || trim($head->output()) !== $worktree->headSha
            || $tree->failed() || trim($tree->output()) !== $worktree->treeSha
            || $status->failed() || $patch->failed() || $untracked->failed()) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree could not be captured.');
        }

        $untrackedFiles = [];
        $untrackedManifest = [];
        $untrackedOutput = $untracked->output();

        if (str_ends_with($untrackedOutput, "\0\n")) {
            $untrackedOutput = substr($untrackedOutput, 0, -1);
        }

        $paths = trim($untrackedOutput, "\0") === ''
            ? []
            : explode("\0", trim($untrackedOutput, "\0"));

        foreach ($paths as $relative) {
            $entry = $this->captureStaleFile($worktree->worktree, $relative);

            if (array_key_exists($relative, $untrackedFiles)) {
                throw new OrbitRepositoryFailed('The stale Orbit untracked-file inventory is ambiguous.');
            }

            $untrackedFiles[$relative] = $entry['contents'];
            $untrackedManifest[] = $entry['manifest'];
        }

        [$loopPresent, $loopManifest, $loopFiles] = $this->captureStaleLoop($worktree->worktree.'/.loop');
        $metadata = [
            'schema' => 1,
            'issue_key' => $worktree->issueKey,
            'worktree' => $worktree->worktree,
            'branch' => $worktree->branch,
            'head_sha' => $worktree->headSha,
            'tree_sha' => $worktree->treeSha,
            'status_sha256' => hash('sha256', $status->output()),
            'patch_sha256' => hash('sha256', $patch->output()),
            'untracked_manifest' => $untrackedManifest,
            'loop_present' => $loopPresent,
            'loop_manifest' => $loopManifest,
        ];

        return [
            'metadata' => $metadata,
            'patch' => $patch->output(),
            'untracked' => $untrackedFiles,
            'loop' => $loopFiles,
        ];
    }

    /** @return array{manifest: array{path: string, mode: int, size: int, sha256: string}, contents: string} */
    private function captureStaleFile(string $root, string $relative): array
    {
        if (! $this->safeStaleRelativePath($relative)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree contains an unsafe file path.');
        }

        $path = $root.'/'.$relative;
        $resolved = realpath($path);

        if ($resolved === false || $resolved !== $path || is_link($path) || ! is_file($path)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree contains an unsafe file.');
        }

        try {
            $permissions = fileperms($path);
            $contents = file_get_contents($path);
        } catch (Throwable $exception) {
            throw new OrbitRepositoryFailed(
                'The stale Orbit worktree contains an unsafe file.',
                previous: $exception,
            );
        }

        if ($permissions === false || $contents === false) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree contains an unsafe file.');
        }

        return [
            'manifest' => [
                'path' => $relative,
                'mode' => $permissions & 07777,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
            ],
            'contents' => $contents,
        ];
    }

    /**
     * @return array{
     *     0: bool,
     *     1: list<array{path: string, type: string, mode: int, size?: int, sha256?: string}>,
     *     2: array<string, string>
     * }
     */
    private function captureStaleLoop(string $loop): array
    {
        if (! file_exists($loop) && ! is_link($loop)) {
            return [false, [], []];
        }

        if (is_link($loop) || ! is_dir($loop) || realpath($loop) !== $loop) {
            throw new OrbitRepositoryFailed('The stale Orbit .loop archive is unsafe.');
        }

        $manifest = [];
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($loop, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                if (! $item instanceof SplFileInfo) {
                    throw new OrbitRepositoryFailed('The stale Orbit .loop archive is unsafe.');
                }

                $path = $item->getPathname();
                $relative = Str::after($path, $loop.'/');

                if (! $this->safeStaleRelativePath($relative) || $item->isLink()) {
                    throw new OrbitRepositoryFailed('The stale Orbit .loop archive is unsafe.');
                }

                $permissions = $item->getPerms() & 07777;

                if ($item->isDir()) {
                    $manifest[] = ['path' => $relative, 'type' => 'directory', 'mode' => $permissions];

                    continue;
                }

                if (! $item->isFile() || realpath($path) !== $path) {
                    throw new OrbitRepositoryFailed('The stale Orbit .loop archive is unsafe.');
                }

                $contents = file_get_contents($path);

                if ($contents === false) {
                    throw new OrbitRepositoryFailed('The stale Orbit .loop archive could not be read.');
                }

                $files[$relative] = $contents;
                $manifest[] = [
                    'path' => $relative,
                    'type' => 'file',
                    'mode' => $permissions,
                    'size' => strlen($contents),
                    'sha256' => hash('sha256', $contents),
                ];
            }
        } catch (OrbitRepositoryFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit .loop archive could not be inspected.', previous: $exception);
        }

        return [true, $manifest, $files];
    }

    private function safeStaleRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && preg_match('#(?:^|/)\.\.(?:/|$)#', $path) !== 1;
    }

    /**
     * @param array{
     *     metadata: array<string, mixed>,
     *     patch: string,
     *     untracked: array<string, string>,
     *     loop: array<string, string>
     * } $capture
     */
    private function publishStaleWorktreeArchive(string $archive, array $capture): void
    {
        if (is_dir($archive)) {
            $this->verifyStaleWorktreeArchiveDirectory(
                $archive,
                hash('sha256', $this->canonicalJson($capture['metadata'])),
            );

            return;
        }

        if (file_exists($archive) || is_link($archive)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive path is unsafe.');
        }

        $parent = dirname($archive);
        $headDirectory = dirname($parent);
        $retiredDirectory = dirname($headDirectory);
        $this->ensurePrivateDirectory($retiredDirectory);
        $this->ensurePrivateDirectory($headDirectory);
        $this->ensurePrivateDirectory($parent);
        $staging = $parent.'/.archive-'.bin2hex(random_bytes(8));

        if (! mkdir($staging, 0700)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive could not be staged.');
        }

        try {
            $metadata = json_encode(
                $capture['metadata'],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )."\n";
            $this->writePrivateArchiveFile($staging.'/metadata.json', $metadata);
            $this->writePrivateArchiveFile($staging.'/changes.patch', $capture['patch']);

            foreach ($capture['untracked'] as $relative => $contents) {
                $this->writePrivateArchiveFile($staging.'/untracked/'.$relative, $contents);
            }

            foreach ($capture['loop'] as $relative => $contents) {
                $this->writePrivateArchiveFile($staging.'/loop/'.$relative, $contents);
            }

            if (! rename($staging, $archive)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree archive could not be published.');
            }
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive metadata is invalid.', previous: $exception);
        } finally {
            if (is_dir($staging)) {
                $this->deleteStagedArchive($staging);
            }
        }

        $this->verifyStaleWorktreeArchiveDirectory(
            $archive,
            hash('sha256', $this->canonicalJson($capture['metadata'])),
        );
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (! file_exists($directory) && ! is_link($directory) && ! mkdir($directory, 0700)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive directory could not be created.');
        }

        if (is_link($directory) || ! is_dir($directory) || realpath($directory) !== $directory) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive directory is unsafe.');
        }
    }

    private function writePrivateArchiveFile(string $path, string $contents): void
    {
        $parent = dirname($path);

        if (! is_dir($parent) && ! mkdir($parent, 0700, true)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive file could not be staged.');
        }

        if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents) || ! chmod($path, 0600)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive file could not be staged.');
        }
    }

    private function deleteStagedArchive(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                throw new OrbitRepositoryFailed('The staged stale Orbit worktree archive is invalid.');
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }

    /** @param StaleWorktreeJournal $journal */
    private function verifyStaleWorktreeArchive(array $journal): void
    {
        $this->verifyStaleWorktreeArchiveDirectory(
            $journal['archive'],
            $journal['archive_digest'],
        );
    }

    private function verifyStaleWorktreeArchiveDirectory(string $archive, string $digest): void
    {
        $metadataPath = $archive.'/metadata.json';

        if (is_link($archive) || realpath($archive) !== $archive
            || ! is_file($metadataPath) || is_link($metadataPath)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive is unavailable.');
        }

        try {
            $metadata = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive metadata is invalid.', previous: $exception);
        }

        if (! is_array($metadata) || array_is_list($metadata)
            || ! hash_equals($digest, hash('sha256', $this->canonicalJson($metadata)))) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive metadata changed.');
        }

        $patchDigest = $metadata['patch_sha256'] ?? null;
        $patch = file_get_contents($archive.'/changes.patch');

        if (! is_string($patchDigest) || preg_match('/^[a-f0-9]{64}$/', $patchDigest) !== 1
            || $patch === false || ! hash_equals($patchDigest, hash('sha256', $patch))) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive patch changed.');
        }

        $this->verifyStaleArchiveFiles($archive.'/untracked', $metadata['untracked_manifest'] ?? null, false);
        $this->verifyStaleArchiveFiles($archive.'/loop', $metadata['loop_manifest'] ?? null, true);
    }

    private function verifyStaleArchiveFiles(string $root, mixed $manifest, bool $directories): void
    {
        if (! is_array($manifest) || ! array_is_list($manifest)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree archive manifest is invalid.');
        }

        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)
                || ! $this->safeStaleRelativePath($entry['path'])) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree archive manifest is invalid.');
            }

            $type = $directories ? ($entry['type'] ?? null) : 'file';
            $path = $root.'/'.$entry['path'];

            if ($type === 'directory') {
                if (! is_dir($path) || is_link($path) || realpath($path) !== $path) {
                    throw new OrbitRepositoryFailed('The stale Orbit worktree archive directory changed.');
                }

                continue;
            }

            $contents = file_get_contents($path);

            if ($type !== 'file' || $contents === false || is_link($path) || ! is_file($path)
                || ($entry['size'] ?? null) !== strlen($contents)
                || ! is_string($entry['sha256'] ?? null)
                || ! hash_equals($entry['sha256'], hash('sha256', $contents))) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree archive file changed.');
            }
        }
    }

    private function retainStaleWorktreeHead(string $repository, string $ref, string $head): void
    {
        try {
            $existing = Process::path($repository)->timeout(10)->run([
                'git', 'show-ref', '--verify', '--hash', $ref,
            ]);

            if ($existing->successful()) {
                if (trim($existing->output()) !== $head) {
                    throw new OrbitRepositoryFailed('The stale Orbit retained head ref changed.');
                }

                return;
            }

            $created = Process::path($repository)->timeout(10)->run([
                'git', 'update-ref', $ref, $head, str_repeat('0', 40),
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit head could not be retained.', previous: $exception);
        }

        if ($created->failed()) {
            throw new OrbitRepositoryFailed('The stale Orbit head could not be retained.');
        }
    }

    /** @param StaleWorktreeJournal $journal */
    private function verifyRetainedStaleWorktreeHead(array $journal): void
    {
        try {
            $result = Process::path($journal['repository'])->timeout(10)->run([
                'git', 'show-ref', '--verify', '--hash', $journal['retained_ref'],
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit retained head could not be verified.', previous: $exception);
        }

        if ($result->failed() || trim($result->output()) !== $journal['head_sha']) {
            throw new OrbitRepositoryFailed('The stale Orbit retained head changed.');
        }
    }

    /**
     * @param  array{repository: string, common: string, directory: string, journal: string}  $context
     * @return StaleWorktreeJournal|null
     */
    private function readStaleWorktreeJournal(array $context, string $issueKey): ?array
    {
        $path = $context['journal'];

        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }

        $permissions = fileperms($path);

        if (is_link($path) || ! is_file($path) || realpath($path) !== $path
            || $permissions === false || ($permissions & 0777) !== 0600) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is unsafe.');
        }

        try {
            $journal = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.', previous: $exception);
        }

        $expectedKeys = [
            'schema', 'state', 'repository', 'worktree', 'issue_key', 'branch', 'head_sha',
            'tree_sha', 'retained_ref', 'archive', 'archive_digest', 'protected_worktrees',
            'protected_branches', 'prepared_at', 'retired_at',
        ];

        if (! is_array($journal) || array_is_list($journal) || array_keys($journal) !== $expectedKeys) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
        }

        $schema = $journal['schema'];
        $state = $journal['state'];
        $repository = $journal['repository'];
        $worktree = $journal['worktree'];
        $journalIssueKey = $journal['issue_key'];
        $branch = $journal['branch'];
        $headSha = $journal['head_sha'];
        $treeSha = $journal['tree_sha'];
        $retainedRef = $journal['retained_ref'];
        $archive = $journal['archive'];
        $archiveDigest = $journal['archive_digest'];
        $preparedAt = $journal['prepared_at'];
        $retiredAt = $journal['retired_at'];

        if ($schema !== 1
            || ! is_string($state) || ! in_array($state, ['prepared', 'retired'], true)
            || ! is_string($repository) || $repository !== $context['repository']
            || ! is_string($journalIssueKey) || $journalIssueKey !== $issueKey
            || ! is_string($worktree)
            || ! is_string($branch)
            || ! is_string($headSha)
            || ! is_string($treeSha)
            || ! is_string($retainedRef)
            || ! is_string($archive)
            || ! is_string($archiveDigest)
            || ! is_string($preparedAt)
            || ($retiredAt !== null && ! is_string($retiredAt))
            || ($state === 'prepared' && $retiredAt !== null)
            || ($state === 'retired' && $retiredAt === null)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
        }

        $protectedWorktrees = $this->parseStaleProtectedWorktrees($journal['protected_worktrees']);
        $protectedBranches = $this->parseStaleProtectedBranches($journal['protected_branches']);
        $journal = [
            'schema' => 1,
            'state' => $state,
            'repository' => $repository,
            'worktree' => $worktree,
            'issue_key' => $journalIssueKey,
            'branch' => $branch,
            'head_sha' => $headSha,
            'tree_sha' => $treeSha,
            'retained_ref' => $retainedRef,
            'archive' => $archive,
            'archive_digest' => $archiveDigest,
            'protected_worktrees' => $protectedWorktrees,
            'protected_branches' => $protectedBranches,
            'prepared_at' => $preparedAt,
            'retired_at' => $retiredAt,
        ];

        try {
            $stale = $this->staleWorktreeFromJournal($journal);
        } catch (InvalidArgumentException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.', previous: $exception);
        }

        $expectedRef = 'refs/orbit-delivery/retired-worktrees/'
            .Str::lower($issueKey).'/'.$stale->headSha;
        $expectedArchive = $context['directory'].'/retired-worktrees/'
            .$stale->headSha.'/'.$journal['archive_digest'];

        if ($journal['retained_ref'] !== $expectedRef
            || $journal['archive'] !== $expectedArchive
            || preg_match('/^[a-f0-9]{64}$/', $journal['archive_digest']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $journal['prepared_at']) !== 1
            || (is_string($journal['retired_at'])
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $journal['retired_at']) !== 1)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is inconsistent.');
        }

        return $journal;
    }

    /** @return list<StaleProtectedWorktree> */
    private function parseStaleProtectedWorktrees(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
        }

        $worktrees = [];

        foreach ($value as $item) {
            if (! is_array($item) || array_keys($item) !== ['worktree', 'branch', 'prunable']) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
            }

            $worktree = $item['worktree'];
            $branch = $item['branch'];
            $prunable = $item['prunable'];

            if (! is_string($worktree)
                || ($branch !== null && ! is_string($branch))
                || ! is_bool($prunable)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
            }

            $worktrees[] = [
                'worktree' => $worktree,
                'branch' => $branch,
                'prunable' => $prunable,
            ];
        }

        return $worktrees;
    }

    /** @return list<string> */
    private function parseStaleProtectedBranches(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
        }

        $branches = [];

        foreach ($value as $branch) {
            if (! is_string($branch) || preg_match('#^refs/heads/[^\s]+$#', $branch) !== 1) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal is invalid.');
            }

            $branches[] = $branch;
        }

        return $branches;
    }

    /** @param StaleWorktreeJournal $journal */
    private function staleWorktreeFromJournal(array $journal): OrbitStaleWorktree
    {
        return new OrbitStaleWorktree(
            repository: $journal['repository'],
            worktree: $journal['worktree'],
            issueKey: $journal['issue_key'],
            branch: $journal['branch'],
            headSha: $journal['head_sha'],
            treeSha: $journal['tree_sha'],
        );
    }

    /** @param StaleWorktreeJournal $journal */
    private function writeStaleWorktreeJournal(string $path, array $journal): void
    {
        try {
            $contents = json_encode(
                $journal,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            )."\n";
        } catch (JsonException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal could not be encoded.', previous: $exception);
        }

        $directory = dirname($path);
        $temporary = tempnam($directory, '.stale-retirement-');

        if ($temporary === false) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal could not be staged.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0600)
                || is_link($directory) || realpath($directory) !== $directory
                || ! rename($temporary, $path)) {
                throw new OrbitRepositoryFailed('The stale Orbit worktree retirement journal could not be published.');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @param  StaleWorktreeJournal  $journal
     * @return array{target_present: bool, target_prunable: bool, branch_present: bool}
     */
    private function inspectStaleRetirementState(array $journal): array
    {
        [$worktrees, $branches] = $this->staleWorktreeInventories($journal['repository']);
        $branchRef = 'refs/heads/'.$journal['branch'];
        $targets = array_values(array_filter(
            $worktrees,
            static fn (array $item): bool => $item['worktree'] === $journal['worktree']
                || $item['branch'] === $branchRef,
        ));
        $exact = array_values(array_filter(
            $targets,
            static fn (array $item): bool => $item['worktree'] === $journal['worktree']
                && $item['branch'] === $branchRef
                && $item['head'] === $journal['head_sha'],
        ));
        $targetPresent = count($exact) === 1 && ! $exact[0]['prunable'];
        $targetPrunable = count($exact) === 1 && $exact[0]['prunable'];
        $branchPresent = ($branches[$branchRef] ?? null) === $journal['head_sha'];
        $protectedWorktrees = $this->unrelatedStaleWorktreeTopology(
            $worktrees,
            $journal['worktree'],
            $journal['branch'],
        );
        $protectedBranches = $this->unrelatedStaleBranchTopology($branches, $journal['branch']);

        if (count($targets) !== (count($exact) === 1 ? 1 : 0)
            || array_key_exists($branchRef, $branches) !== $branchPresent
            || $protectedWorktrees !== $journal['protected_worktrees']
            || $protectedBranches !== $journal['protected_branches']
            || (! $targetPresent && ! $targetPrunable
                && (file_exists($journal['worktree']) || is_link($journal['worktree'])))) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree retirement state changed.');
        }

        return [
            'target_present' => $targetPresent,
            'target_prunable' => $targetPrunable,
            'branch_present' => $branchPresent,
        ];
    }

    /** @param StaleWorktreeJournal $journal */
    private function removeStaleWorktreeRegistration(array $journal): void
    {
        try {
            $result = Process::path($journal['repository'])->timeout(30)->run([
                'git', 'worktree', 'remove', '--force', $journal['worktree'],
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree registration could not be removed.', previous: $exception);
        }

        if ($result->failed()) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree registration could not be removed.');
        }
    }

    /** @param StaleWorktreeJournal $journal */
    private function removeStaleWorktree(array $journal): void
    {
        try {
            $result = Process::path($journal['repository'])->timeout(300)->run([
                'git', 'worktree', 'remove', '--force', $journal['worktree'],
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit worktree could not be removed.', previous: $exception);
        }

        if ($result->failed()) {
            $details = trim($result->errorOutput()) ?: trim($result->output());
            $details = $details === '' ? 'unknown repository error' : Str::limit($details, 500);

            throw new OrbitRepositoryFailed('Stale Orbit worktree removal failed: '.$details);
        }
    }

    /** @param StaleWorktreeJournal $journal */
    private function removeStaleWorktreeBranch(array $journal): void
    {
        try {
            $result = Process::path($journal['repository'])->timeout(10)->run([
                'git', 'update-ref', '-d', 'refs/heads/'.$journal['branch'], $journal['head_sha'],
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitRepositoryFailed('The stale Orbit branch could not be removed.', previous: $exception);
        }

        if ($result->failed()) {
            throw new OrbitRepositoryFailed('The stale Orbit branch could not be removed.');
        }
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
