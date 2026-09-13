<?php

namespace App\Herdr;

final readonly class Transition
{
    public function __construct(
        public string $paneId,
        public string $from,
        public string $to,
    ) {}

    public function describe(): string
    {
        return "{$this->paneId}: {$this->from} -> {$this->to}";
    }
}
