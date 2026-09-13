<?php

namespace App\Herdr;

use App\Notifications\HerdrAgentStatusChanged;
use Closure;
use Throwable;

/**
 * Follows the Herdr session over its socket and sends agent-status transitions to Tom.
 */
final class Listener
{
    private const array LIFECYCLE_EVENTS = ['pane_created', 'pane_agent_detected', 'pane_exited'];

    private const float BACKOFF_MIN = 1.0;

    private const float BACKOFF_MAX = 30.0;

    private const float READ_TIMEOUT = 1.0;

    private const float SETTLE_TIMEOUT = 0.1;

    /**
     * @param  Closure(): SocketClient  $client
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private readonly Closure $client,
        private readonly StatusTracker $tracker,
        private readonly TransitionRecorder $recorder,
        private readonly Closure $log,
        private readonly bool $dryRun = false,
    ) {}

    /**
     * Run until the timeout elapses; without a timeout, run forever and reconnect with backoff.
     */
    public function run(?float $timeout = null): void
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $backoff = self::BACKOFF_MIN;

        while (! $this->expired($deadline)) {
            $client = ($this->client)();

            try {
                $this->serve($client, $deadline);
                $backoff = self::BACKOFF_MIN;
            } catch (ConnectionFailed|ConnectionClosed|RequestFailed $exception) {
                $this->log(sprintf('%s; retrying in %.0fs', $exception->getMessage(), $backoff));
                $this->pause($backoff, $deadline);
                $backoff = min($backoff * 2, self::BACKOFF_MAX);
            } finally {
                $client->close();
            }
        }
    }

    /**
     * Serve one subscription; returns when a new pane needs a subscription, which takes a fresh one.
     */
    private function serve(SocketClient $client, ?float $deadline): void
    {
        $roster = $this->refresh($client);
        $subscribed = $roster->paneIds();

        $client->subscribe([
            ['type' => 'pane.created'],
            ['type' => 'pane.agent_detected'],
            ['type' => 'pane.exited'],
            ...array_map(fn (string $paneId): array => ['type' => 'pane.agent_status_changed', 'pane_id' => $paneId], $subscribed),
        ]);

        $this->log(sprintf(
            'subscribed to %d panes: %s',
            count($subscribed),
            implode('; ', array_map(fn (string $paneId): string => $roster->describe($paneId).' '.$roster->statuses()[$paneId], $subscribed)),
        ));

        $rosterStale = false;

        while (! $this->expired($deadline)) {
            $this->flush($roster);

            $line = $client->readLine($rosterStale ? self::SETTLE_TIMEOUT : $this->waitFor($deadline));

            if ($line === null) {
                if ($rosterStale) {
                    $rosterStale = false;
                    $roster = $this->refresh($client);
                    $added = array_diff($roster->paneIds(), $subscribed);

                    if ($added !== []) {
                        $this->log('new panes '.implode(', ', $added).'; re-subscribing');

                        return;
                    }
                }

                continue;
            }

            $event = str_replace('.', '_', Payload::string($line['event'] ?? null) ?? '');
            $data = Payload::assoc($line['data'] ?? null);

            if ($event === 'pane_agent_status_changed') {
                $paneId = Payload::string($data['pane_id'] ?? null);
                $status = Payload::string($data['agent_status'] ?? null);

                if ($paneId !== null && $status !== null) {
                    $this->observe($roster, $paneId, $status);
                }
            } elseif (in_array($event, self::LIFECYCLE_EVENTS, true)) {
                $rosterStale = true;
            }
        }
    }

    private function refresh(SocketClient $client): Roster
    {
        $roster = Roster::fetch($client);

        foreach ($roster->statuses() as $paneId => $status) {
            $this->observe($roster, $paneId, $status);
        }

        foreach (array_diff($this->tracker->knownPanes(), $roster->paneIds()) as $paneId) {
            $this->tracker->forget($paneId);
        }

        return $roster;
    }

    private function observe(Roster $roster, string $paneId, string $status): void
    {
        $transition = $this->tracker->observe($paneId, $status, microtime(true));

        if ($transition !== null) {
            $this->log(sprintf('%s: %s -> %s', $roster->describe($paneId), $transition->from, $transition->to));
        }
    }

    private function flush(Roster $roster): void
    {
        foreach ($this->tracker->due(microtime(true)) as $transition) {
            $message = HerdrAgentStatusChanged::text(
                $roster->agentName($transition->paneId) ?? $transition->paneId,
                $transition->to,
            );

            if ($this->dryRun) {
                $this->log('dry run: would send '.$message);

                continue;
            }

            try {
                $this->recorder->record($transition, $roster);
                $this->log('sent '.$message);
            } catch (Throwable $exception) {
                $this->log(sprintf('sending %s failed: %s', $transition->describe(), $exception->getMessage()));
            }
        }
    }

    private function expired(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    private function waitFor(?float $deadline): float
    {
        if ($deadline === null) {
            return self::READ_TIMEOUT;
        }

        return max(0.0, min(self::READ_TIMEOUT, $deadline - microtime(true)));
    }

    private function pause(float $seconds, ?float $deadline): void
    {
        if ($deadline !== null) {
            $seconds = min($seconds, max(0.0, $deadline - microtime(true)));
        }

        usleep((int) ($seconds * 1_000_000));
    }

    private function log(string $message): void
    {
        ($this->log)($message);
    }
}
