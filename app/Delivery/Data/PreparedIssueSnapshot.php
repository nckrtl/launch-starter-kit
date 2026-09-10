<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class PreparedIssueSnapshot
{
    public function __construct(
        public int $schema,
        public string $provider,
        public string $path,
        public string $contentsHash,
        public int $contractSchema,
        public string $contractHash,
        public string $issueId,
        public string $issueKey,
    ) {}
}
