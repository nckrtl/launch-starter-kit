<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final readonly class ProcessOrbitRepository implements OrbitRepository
{
    private const array PROJECTS = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array COMMANDS = [
        ['composer', 'validate', '--strict'],
        ['composer', 'check'],
        ['composer', 'test:affected'],
    ];

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

        $receiptPath = realpath($reportedReceipt);
        $expectedDirectory = $common.'/orbit-checks/'.$worktree->headSha;

        if ($receiptPath === false || is_link($reportedReceipt) || ! is_file($receiptPath)
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

        return new CandidateCheck($receiptPath, $worktree->headSha, $treeSha);
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
