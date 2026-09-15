<?php

declare(strict_types=1);

namespace App\Tasks;

use InvalidArgumentException;

final class GitObjectId
{
    public static function validate(string $sha): void
    {
        if (preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/', $sha) !== 1) {
            throw new InvalidArgumentException('A Git object requires a full lowercase SHA-1 or SHA-256 identity.');
        }
    }
}
