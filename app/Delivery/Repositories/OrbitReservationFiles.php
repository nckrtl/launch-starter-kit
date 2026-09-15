<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Exceptions\OrbitRepositoryFailed;

trait OrbitReservationFiles
{
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
}
