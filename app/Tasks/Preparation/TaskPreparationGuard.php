<?php

declare(strict_types=1);

namespace App\Tasks\Preparation;

use App\Delivery\Data\OrbitDeliveryReservation;
use App\Delivery\Repositories\OrbitReservationFiles;
use App\Models\Task;
use App\Tasks\Runtime\GitTaskWorktree;
use Closure;
use LogicException;

final class TaskPreparationGuard
{
    use OrbitReservationFiles;

    public function __construct(private readonly GitTaskWorktree $git, private readonly TaskPreparationConfiguration $configuration) {}

    public function common(string $repository): string
    {
        if ($repository === '/' || realpath($repository) !== $repository || ! is_dir($repository.'/.git')
            || is_link($repository.'/.git') || realpath($repository.'/.git') !== $repository.'/.git') {
            throw new LogicException('Preparation requires the canonical primary Git checkout.');
        }

        return $repository.'/.git';
    }

    public function directory(string $common, string $source): string
    {
        if (preg_match('/\AORB-[1-9][0-9]*\z/', $source) !== 1) {
            throw new LogicException('Preparation requires an exact ORB-N source key.');
        }

        return $common.'/orbit-delivery/v1/'.strtolower($source).'/task-preparation';
    }

    public function issue(string $common, string $source, bool $fresh): OrbitDeliveryReservation
    {
        $directory = dirname($this->directory($common, $source));
        $this->ensureReservationDirectory($common.'/orbit-delivery');
        $this->ensureReservationDirectory($common.'/orbit-delivery/v1');
        $this->ensureReservationDirectory($directory);
        $lock = $this->lock($directory.'/controller.lock');
        if ($fresh) {
            foreach (['state.json', 'worker.json'] as $journal) {
                if (file_exists($directory.'/'.$journal) || is_link($directory.'/'.$journal)) {
                    $lock->release();
                    throw new LogicException('A legacy Orbit controller journal blocks fresh preparation.');
                }
            }
        }

        return $lock;
    }

    public function checkout(string $common): OrbitDeliveryReservation
    {
        return $this->lock($common.'/orbit-delivery/v1/checkout.lock');
    }

    /** @template T
     * @param  Closure():T  $callback
     * @return T
     */
    public function admission(Task $root, string $repository, string $worktree, string $source, string $manifest, Closure $callback): mixed
    {
        $common = $this->common($repository);
        $issue = preg_match('/\AORB-[1-9][0-9]*\z/', $source) === 1 ? $source : null;
        if (preg_match('/\Aorb-[1-9][0-9]*\z/', basename($worktree)) === 1) {
            $pathIssue = strtoupper(basename($worktree));
            if ($issue !== null && $issue !== $pathIssue) {
                throw new LogicException('The preparation path and source key differ.');
            }
            $issue = $pathIssue;
        }
        if ($issue === null) {
            return $callback();
        }
        $lock = $this->issue($common, $issue, false);
        try {
            $directory = $this->directory($common, $issue);
            if (file_exists($directory) || is_link($directory)) {
                $intent = $this->read($directory.'/intent.json');
                $receipt = $this->read($directory.'/success.json');
                $pins = $this->pins($root, $repository, $common, $worktree, $source, $manifest);
                if (($intent['schema'] ?? null) !== 1 || ($receipt['schema'] ?? null) !== 1
                    || ($intent['pins'] ?? null) !== $pins || ($receipt['pins'] ?? null) !== $pins
                    || ($receipt['intent_sha256'] ?? null) !== hash_file('sha256', $directory.'/intent.json')
                    || ($receipt['exit_code'] ?? null) !== 0 || ($receipt['verified'] ?? null) !== true
                    || ! is_array($receipt['observed'] ?? null)
                    || ($receipt['observed']['feature_head'] ?? null) !== $this->git->inspect($repository, $worktree)) {
                    throw new LogicException('Unresolved or mismatched preparation blocks admission; coordinator reconciliation is required.');
                }
                $observation = $receipt['observed'];
                if (! is_array($observation['configuration'] ?? null)
                    || ($observation['configuration']['files'] ?? null) !== $this->configuration->snapshot($worktree)
                    || realpath($worktree.'/.loop/flow.json') !== $worktree.'/.loop/flow.json'
                    || realpath($worktree.'/.loop/plan.md') !== $worktree.'/.loop/plan.md'
                    || ($observation['flow_sha256'] ?? null) !== hash_file('sha256', $worktree.'/.loop/flow.json')
                    || ($observation['plan_sha256'] ?? null) !== hash_file('sha256', $worktree.'/.loop/plan.md')) {
                    throw new LogicException('Verified preparation configuration or discovery scaffold changed before admission.');
                }
            }

            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int|string> */
    public function pins(Task $root, string $repository, string $common, string $worktree, string $source, string $manifest): array
    {
        return ['instance' => base_path(), 'project' => $root->project_id, 'root' => $root->id,
            'repository' => $repository, 'common' => $common, 'worktree' => $worktree,
            'source' => $source, 'manifest' => $manifest];
    }

    /** @param array<string, mixed> $data */
    public function write(string $path, array $data): void
    {
        $directory = dirname($path);
        $this->privateDirectory($directory);
        if (file_exists($path) || is_link($path)) {
            throw new LogicException('Preparation evidence already exists. No retry or replay is supported.');
        }
        $temporary = tempnam($directory, '.intent-');
        if ($temporary === false) {
            throw new LogicException('Cannot allocate preparation evidence.');
        }
        try {
            $handle = fopen($temporary, 'wb');
            $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            if ($handle === false) {
                throw new LogicException('Cannot write preparation evidence.');
            }
            try {
                if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                    throw new LogicException('Cannot persist preparation evidence.');
                }
            } finally {
                fclose($handle);
            }
            if (! link($temporary, $path)) {
                throw new LogicException('Cannot publish preparation evidence.');
            }
            $directoryHandle = fopen($directory, 'r');
            if ($directoryHandle === false) {
                throw new LogicException('Cannot sync preparation evidence directory.');
            }
            try {
                if (! fsync($directoryHandle)) {
                    throw new LogicException('Cannot sync preparation evidence directory.');
                }
            } finally {
                fclose($directoryHandle);
            }
        } finally {
            unlink($temporary);
        }
    }

    public function privateDirectory(string $directory): void
    {
        $this->ensureReservationDirectory($directory);
        if ((fileperms($directory) & 0777) !== 0700) {
            throw new LogicException('Preparation evidence directories must be private.');
        }
    }

    /** @return array<string, mixed> */
    public function read(string $path): array
    {
        if (realpath(dirname($path)) !== dirname($path) || is_link($path) || ! is_file($path)
            || (fileperms($path) & 0777) !== 0600) {
            throw new LogicException('Unresolved preparation: private evidence is missing or unsafe; coordinator reconciliation is required.');
        }
        $contents = file_get_contents($path);
        $data = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new LogicException('Invalid preparation evidence.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function lock(string $path): OrbitDeliveryReservation
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new LogicException('Unsafe preparation lock.');
        }
        [$handle, $created] = $this->openReservationLock(dirname($path), $path);
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new LogicException('Another controller owns this issue or primary checkout.');
        }
        if (! $this->isOpenedReservationLock($handle, $path) || ($created && ! $this->isPrivateOpenedReservationLock($handle))) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new LogicException('Unsafe preparation lock identity.');
        }

        return new OrbitDeliveryReservation($handle, $path);
    }
}
