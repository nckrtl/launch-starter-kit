<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrSessionSnapshot
{
    /**
     * @param  list<HerdrSnapshotWorkspace>  $workspaces
     * @param  list<HerdrSnapshotPane>  $panes
     * @param  list<HerdrSnapshotAgent>  $agents
     */
    public function __construct(
        public string $version,
        public int $protocol,
        public array $workspaces,
        public array $panes,
        public array $agents,
    ) {}
}
