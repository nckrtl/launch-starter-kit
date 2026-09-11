<?php

declare(strict_types=1);

namespace App\Delivery\Data;

final readonly class HerdrAgentLaunch
{
    /** @param list<string> $arguments */
    public function __construct(
        public string $kind,
        public array $arguments,
        public int $timeoutMilliseconds,
    ) {}

    public static function default(): self
    {
        return new self('codex', [], 15_000);
    }
}
