<?php

namespace App\Herdr;

/**
 * Remembers the last status of every pane and decides which transitions are announced.
 */
final class StatusTracker
{
    /**
     * @var array<string, string>
     */
    private array $known = [];

    /**
     * @param  list<string>  $notifyStatuses
     */
    public function __construct(
        private readonly array $notifyStatuses,
        private readonly Debouncer $debouncer,
    ) {}

    /**
     * Record a status; returns the transition when the pane changed status, null when it was seeded or unchanged.
     */
    public function observe(string $paneId, string $status, float $now): ?Transition
    {
        $previous = $this->known[$paneId] ?? null;
        $this->known[$paneId] = $status;

        if ($previous === null || $previous === $status) {
            return null;
        }

        $transition = new Transition($paneId, $previous, $status);

        if (in_array($status, $this->notifyStatuses, true)) {
            $this->debouncer->push($transition, $now);
        } else {
            $this->debouncer->cancel($paneId);
        }

        return $transition;
    }

    /**
     * @return list<Transition>
     */
    public function due(float $now): array
    {
        return $this->debouncer->due($now);
    }

    public function forget(string $paneId): void
    {
        unset($this->known[$paneId]);
        $this->debouncer->cancel($paneId);
    }

    /**
     * @return list<string>
     */
    public function knownPanes(): array
    {
        return array_keys($this->known);
    }
}
