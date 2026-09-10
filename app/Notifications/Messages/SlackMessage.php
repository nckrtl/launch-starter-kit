<?php

namespace App\Notifications\Messages;

final readonly class SlackMessage
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    public function __construct(
        public string $text,
        public array $blocks = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'text' => $this->text,
            'blocks' => $this->blocks,
        ], fn (mixed $value): bool => $value !== []);
    }
}
