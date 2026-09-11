<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrSnapshotWorkspace
{
    public function __construct(
        public string $workspaceId,
        public ?string $repositoryRoot,
        public ?string $checkoutPath,
        public ?bool $linkedWorktree,
    ) {}
}
