<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

final class TaskProcessEnvironment
{
    private const array INHERITED = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR', 'TMP', 'TEMP',
        'LANG', 'LANGUAGE', 'LC_ALL', 'LC_ADDRESS', 'LC_COLLATE', 'LC_CTYPE',
        'LC_IDENTIFICATION', 'LC_MEASUREMENT', 'LC_MESSAGES', 'LC_MONETARY',
        'LC_NAME', 'LC_NUMERIC', 'LC_PAPER', 'LC_TELEPHONE', 'LC_TIME',
        'TERM', 'COLORTERM', 'XDG_RUNTIME_DIR', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME',
        'XDG_DATA_HOME', 'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT',
    ];

    /** @return array<string, false> */
    public static function isolated(): array
    {
        $environment = [];

        // Symfony inherits omitted values, including Laravel's loaded dotenv values.
        foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $key) {
            if (is_string($key) && ! in_array($key, self::INHERITED, true)) {
                $environment[$key] = false;
            }
        }

        return $environment;
    }
}
