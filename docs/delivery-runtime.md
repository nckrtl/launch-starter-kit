# Commander delivery runtime

Commander delivery jobs run in a dedicated systemd user service on Beast. The
service consumes the application's database-backed `default` queue and restarts
the Laravel worker every hour so deployed code is loaded without an unbounded
daemon lifetime.

The worker timeout is 540 seconds. It stays below the database queue's
600-second `retry_after`, while matching the longest enabled delivery job. A
systemd stop waits up to 600 seconds so an in-flight delivery job can finish.

Install the committed unit as a link so changes remain reviewable in this
repository:

```bash
systemctl --user link /fast/apps/commander/ops/systemd/commander-delivery-worker.service
systemctl --user link /fast/apps/commander/ops/systemd/commander-scheduler.service
systemctl --user link /fast/apps/commander/ops/systemd/commander-scheduler.timer
systemctl --user daemon-reload
systemctl --user enable --now commander-delivery-worker.service
systemctl --user enable --now commander-scheduler.timer
```

Operate and inspect it with:

```bash
systemctl --user status commander-delivery-worker.service
systemctl --user reload commander-delivery-worker.service
systemctl --user status commander-scheduler.timer
journalctl --user -u commander-delivery-worker.service -n 50 --no-pager
journalctl --user -u commander-scheduler.service -n 50 --no-pager
```

`reload` asks Laravel to restart after its current job, then systemd starts a
fresh worker. The unit does not use `--force`, so maintenance mode stops it from
claiming new jobs.

The scheduler timer runs Laravel's scheduler once per minute. It queues one
short, unique reconciliation job. That job finds recoverable deliveries for
enabled projects and queues the existing `AdvanceDelivery` path. It skips
paused projects and terminal, paused, or blocked deliveries. It does not contain
a second workflow state machine.

For a prepared live Orbit delivery, `AdvanceDelivery` queues the bounded initial
planning-dispatch job. Duplicate jobs serialize on a delivery-specific cache
lock, and a stale exhausted job cannot overwrite a later planning attempt.

Start a deliberate live canary without changing the normal Orbit entry point:

```bash
php artisan delivery:start-orbit orbit ORB-234
```

The command resolves the stable Linear UUID from the issue key, uses the same
controller reservation as the legacy loop, runs the trusted worktree and
candidate checks, writes the live ledger, and queues `AdvanceDelivery`.

This worker does not cut over Orbit's feature loop. Until shadow parity and the
reconciliation runtime are proven, `/home/nckrtl/orbit/bin/loop` continues to
invoke the legacy controller.

Run the read-only listener parity gate with:

```bash
php artisan delivery:shadow-parity
php artisan delivery:shadow-parity --since=2026-09-11T00:00:00Z
```

The report fails closed when no compatibility notifications exist in the
window, when a legacy debounced notification has no matching raw Commander
event, or when the legacy Tom notification is still pending. Correlated and
unmatched raw terminal events are reported separately; unmatched legacy-loop
agents are expected before cutover and do not fail capture parity.

Without `--since`, the gate observes the later of raw capture startup and the
past 24 hours. This keeps the live report bounded while still covering the
lookback needed to match notifications at the start of the window.
