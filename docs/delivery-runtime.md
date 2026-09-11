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
systemctl --user daemon-reload
systemctl --user enable --now commander-delivery-worker.service
```

Operate and inspect it with:

```bash
systemctl --user status commander-delivery-worker.service
systemctl --user reload commander-delivery-worker.service
journalctl --user -u commander-delivery-worker.service -n 50 --no-pager
```

`reload` asks Laravel to restart after its current job, then systemd starts a
fresh worker. The unit does not use `--force`, so maintenance mode stops it from
claiming new jobs.

This worker does not cut over Orbit's feature loop. Until shadow parity and the
reconciliation runtime are proven, `/home/nckrtl/orbit/bin/loop` continues to
invoke the legacy controller.
