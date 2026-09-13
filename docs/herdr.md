# Herdr agent status delivery

Commander follows the Herdr `orbit` session over its Unix socket and sends a compact authenticated webhook directly to Tom whenever an agent pane settles into `idle` or `done`. Each transition is stored in the `herdr_events` table (`App\Models\HerdrEvent`) with the pane, workspace, agent, previous and new status, and successful delivery time.

Slack is not part of this machine-to-machine path. Tom may later post a human-visible message to the Orbit channel when a transition requires Nick, but routine reconciliation stays silent.

## How the listener works

`php artisan herdr:listen` connects to the socket in `config('herdr.socket')` (`HERDR_SOCKET_PATH`), reads the current agents and workspaces with `agent.list` and `workspace.list`, and subscribes to `pane.created`, `pane.agent_detected`, `pane.exited`, and `pane.agent_status_changed` for every agent pane. The statuses from `agent.list` seed the tracker silently; only later changes count.

A pane entering one of `config('herdr.notify_statuses')` is held for `config('herdr.debounce_seconds')` seconds and sent when it is still in that status. `working` and `unknown` cancel a pending terminal-state event. Lifecycle events refresh the roster over fresh request connections, and a new pane causes the listener to reopen its subscription with that pane included. When Herdr closes the subscription or is unreachable, the listener reconnects with backoff from 1 to 30 seconds.

`App\Herdr\TomWebhookClient` sends JSON directly to `HERDR_TOM_WEBHOOK_URL`. Each request includes:

- `X-Webhook-Timestamp`;
- `X-Webhook-Signature-V2`, an HMAC-SHA256 over `<timestamp>.<raw-body>` using `HERDR_TOM_WEBHOOK_SECRET`;
- `X-Request-ID` set to `commander-herdr-<event-id>` for Hermes retry deduplication.

The body contains identifiers and state only: event ID/time, workspace ID/label, pane ID, agent kind/name, and previous/new status. Tom's `orbit-herdr` route validates the signature and replay window, filters the payload again, and treats it only as a wake signal before rereading authoritative state.

In Herdr, `done` means idle and not yet viewed by a human in the Herdr UI. The stored `to_status` keeps the raw value. `HERDR_NOTIFY_STATUSES` defaults to `idle,done`; add `blocked` only when that state should also wake Tom.

## Configuration

```dotenv
HERDR_SOCKET_PATH=/home/nckrtl/.config/herdr/sessions/orbit/herdr.sock
HERDR_TOM_WEBHOOK_URL=https://agents.nckrtl.com/webhooks/orbit-herdr
HERDR_TOM_WEBHOOK_SECRET=<shared secret>
HERDR_NOTIFY_STATUSES=idle,done
```

Keep the shared secret only in the uncommitted `.env` and Tom's protected webhook subscription state.

## Running it

```bash
php artisan herdr:listen                         # long-running
php artisan herdr:listen --dry-run --timeout=60  # log transitions and send nothing
php artisan herdr:test-post                      # resend the latest recorded event to verify the direct route
```

Every subscription and transition is written to the console and Laravel log at info level.

## The unit on Beast

`~/.config/systemd/user/commander-herdr-listen.service` keeps the listener running next to Herdr:

```ini
[Unit]
Description=Commander Herdr listener (agent status directly to Tom)
After=herdr-orbit.service

[Service]
WorkingDirectory=/fast/apps/commander
ExecStart=/usr/local/bin/php /fast/apps/commander/artisan herdr:listen
Restart=on-failure
RestartSec=5

[Install]
WantedBy=default.target
```

```bash
systemctl --user daemon-reload
systemctl --user restart commander-herdr-listen
systemctl --user status commander-herdr-listen
journalctl --user -u commander-herdr-listen -n 30 --no-pager
```
