<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class OrbitDeliveryPreparation
{
    public function __construct(
        public PreparedWorktree $worktree,
        public PreparedIssueSnapshot $snapshot,
        public CandidateCheck $candidate,
        public string $flow,
    ) {}
}
