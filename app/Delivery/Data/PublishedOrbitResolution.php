<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class PublishedOrbitResolution
{
    public function __construct(
        public string $commentId,
        public string $marker,
        public string $bodyHash,
    ) {}
}
