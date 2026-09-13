<?php

namespace App\Herdr;

/**
 * Holds one pending transition per pane until its status has held for the window.
 */
final class Debouncer
{
    /**
     * @var array<string, array{transition: Transition, due: float}>
     */
    private array $pending = [];

    public function __construct(private readonly float $seconds) {}

    public function push(Transition $transition, float $now): void
    {
        $this->pending[$transition->paneId] = ['transition' => $transition, 'due' => $now + $this->seconds];
    }

    public function cancel(string $paneId): void
    {
        unset($this->pending[$paneId]);
    }

    /**
     * @return list<Transition>
     */
    public function due(float $now): array
    {
        $ready = [];

        foreach ($this->pending as $paneId => $entry) {
            if ($entry['due'] <= $now) {
                $ready[] = $entry['transition'];
                unset($this->pending[$paneId]);
            }
        }

        return $ready;
    }
}
