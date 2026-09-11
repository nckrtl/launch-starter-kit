<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use InvalidArgumentException;

final readonly class RequestedOrbitMainCacheRefresh
{
    public function __construct(
        public string $repository,
        public string $disposition,
        public string $message,
    ) {
        if (! in_array($disposition, ['queued', 'coalesced', 'already_current'], true)) {
            throw new InvalidArgumentException('The Orbit main cache refresh disposition is invalid.');
        }
    }
}
