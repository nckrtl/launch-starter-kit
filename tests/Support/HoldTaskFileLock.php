<?php

declare(strict_types=1);

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $directory, $key] = $argv;
if (dirname($directory) !== sys_get_temp_dir()
    || preg_match('/^task-lock-test-[a-f0-9]{32}$/D', basename($directory)) !== 1
    || ! is_dir($directory) || is_link($directory) || realpath($directory) !== $directory) {
    throw new LogicException('Only an existing private Tasks lock fixture is permitted.');
}
$lock = (new FileStore(new Filesystem, $directory.'/locks'))->lock($key, 60);
if (! $lock->get()) {
    throw new RuntimeException('Could not acquire child fixture lock.');
}
fwrite(STDOUT, "held\n");
fflush(STDOUT);
try {
    stream_set_timeout(STDIN, 10);
    if (trim((string) fgets(STDIN)) !== 'release') {
        throw new RuntimeException('Expected explicit parent release.');
    }
} finally {
    $lock->release();
}
