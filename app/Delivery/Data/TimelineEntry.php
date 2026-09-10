<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use Carbon\CarbonInterface;

final readonly class TimelineEntry
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public CarbonInterface $occurredAt,
        public string $type,
        public string $source,
        public int $sourceId,
        public array $details,
        public int $sourcePriority,
        public int $milestonePriority,
    ) {}

    /** @return array{occurred_at: string, type: string, source: string, source_id: int, details: array<string, scalar|null>} */
    public function toArray(): array
    {
        return [
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'type' => $this->type,
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'details' => $this->details,
        ];
    }
}
