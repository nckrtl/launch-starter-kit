<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrPaneProcessInfo
{
    /** @param list<HerdrForegroundProcess> $foregroundProcesses */
    public function __construct(
        public string $paneId,
        public ?int $shellProcessId,
        public ?int $foregroundProcessGroupId,
        public array $foregroundProcesses,
    ) {}
}
