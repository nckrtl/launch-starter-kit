<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use Closure;
use LogicException;

final class TaskCloseoutLock
{
    /** @template T
     * @param  Closure():T  $callback
     * @return T
     */
    public function handle(Closure $callback): mixed
    {
        $directory = storage_path('app/private/task-closeout-locks');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new LogicException('The exclusive Tasks closeout lock directory is unavailable.');
        }
        if (realpath($directory) !== $directory || is_link($directory)) {
            throw new LogicException('The Tasks closeout lock directory must be canonical.');
        }
        $path = $directory.'/orbit.lock';
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new LogicException('The Tasks closeout lock is unsafe.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new LogicException('The Tasks closeout lock cannot be opened.');
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new LogicException('Another coordinator owns Tasks closeout or main hold reconciliation.');
            }
            $opened = fstat($handle);
            $current = lstat($path);
            if ($opened === false || $current === false || $opened['ino'] !== $current['ino']
                || $opened['dev'] !== $current['dev']) {
                throw new LogicException('The Tasks closeout lock identity changed.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
