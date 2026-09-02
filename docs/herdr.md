# Herdr agent status in Slack

Commander follows the Herdr `orbit` session over its Unix socket and posts to Slack whenever an agent pane settles into `idle`, `done`, or `blocked`. Each posted transition is stored in the `herdr_events` table (`App\Models\HerdrEvent`) with the pane, workspace, agent, previous and new status, and the time it was posted.

## How the listener works

`php artisan herdr:listen` connects to the socket in `config('herdr.socket')` (`HERDR_SOCKET_PATH`), reads the current agents and workspaces with `agent.list` and `workspace.list`, and subscribes to `pane.created`, `pane.agent_detected`, `pane.exited`, and `pane.agent_status_changed` for every agent pane. The statuses from `agent.list` seed the tracker silently; only later changes count. A pane entering one of `config('herdr.notify_statuses')` is held for `config('herdr.debounce_seconds')` seconds and posted when it is still in that status; `working` and `unknown` are never posted and cancel a pending post for that pane. Herdr answers each request on its own connection and keeps only the subscription connection open, so a lifecycle event refreshes the roster over fresh request connections, and a pane that is new to the roster makes the listener open a new subscription with the pane included. When Herdr closes the subscription or is unreachable, the listener retries with a backoff that doubles from 1 s to 30 s.

The notification is `App\Notifications\HerdrAgentStatusChanged`, delivered through the existing Slack channel to `config('herdr.slack_channel')` (`HERDR_SLACK_CHANNEL`, falling back to `SLACK_BOT_USER_DEFAULT_CHANNEL`) as `Herdr: <agent name or pane id> (<workspace label or id>, <pane id>) is now <status>`.

In Herdr, `done` means idle and not yet viewed by a human in the Herdr UI, so in an unattended session a finished agent reports `done` rather than `idle`. Commander posts a `done` transition as `is now idle` through `config('herdr.status_labels')`, while the stored `to_status` keeps Herdr's raw value. `HERDR_NOTIFY_STATUSES` (default `idle,done,blocked`) is the comma-separated list of statuses that are posted, so `HERDR_NOTIFY_STATUSES=idle,done` drops blocked.

## Running it

```bash
php artisan herdr:listen                         # long-running
php artisan herdr:listen --dry-run --timeout=60  # log subscriptions and transitions for 60 s, post nothing
php artisan herdr:test-post                      # post "Commander: Herdr bridge online" and print the Slack ok result
```

Every subscription and transition is written to the console and to the Laravel log at info level.

## The unit on Beast

`~/.config/systemd/user/commander-herdr-listen.service` keeps the listener running next to the Herdr server:

```ini
[Unit]
Description=Commander Herdr listener
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
systemctl --user daemon-reload && systemctl --user enable --now commander-herdr-listen
systemctl --user status commander-herdr-listen
journalctl --user -u commander-herdr-listen -n 30 --no-pager
```
