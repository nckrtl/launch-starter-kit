<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class PreparedWorktree
{
    public function __construct(
        public string $path,
        public string $headSha,
    ) {}
}
