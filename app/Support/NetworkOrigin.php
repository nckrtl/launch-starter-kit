<?php

declare(strict_types=1);

namespace App\Support;

use ValueError;

final class NetworkOrigin
{
    public static function canonical(mixed $origin, string $scheme): ?string
    {
        if (! is_string($origin) || $origin === '' || strlen($origin) > 255) {
            return null;
        }

        try {
            $parts = parse_url($origin);
        } catch (ValueError) {
            return null;
        }

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== $scheme
            || ! is_string($parts['host'] ?? null)
            || ! self::validHost($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port === 0) {
            return null;
        }

        $canonical = $scheme.'://'.$parts['host'].($port === null ? '' : ':'.$port);

        return in_array($origin, [$canonical, $canonical.'/'], true) ? $canonical : null;
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/\A[\x21-\x7e]+\z/D', $host) !== 1) {
            return false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
