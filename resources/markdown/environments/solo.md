# Running the project with Solo

Addendum to [the main setup guide](https://launch.nckrtl.com/create.md). Read that first.

Use this page if you are an agent with the Solo MCP server available.

## 1. Confirm Solo is available

This is not a shell check. Look at your own tool list for tools named `mcp__solo__*` — for
example `mcp__solo__list_projects` or `mcp__solo__start_all_commands`. If they are there, Solo
is available to you.

If you have no Solo tools, this page does not apply, even when the `solo` binary exists on
`PATH`. A CLI you cannot drive does not help you. Go back to the main guide.

## 2. Check who owns the processes first

**Whatever serves the app owns its long-running processes.** Before defining anything in Solo:

```bash
command -v orbit >/dev/null 2>&1 && echo "orbit"
```

| Result  | Who owns the processes                                                             |
| ------- | ---------------------------------------------------------------------------------- |
| `orbit` | **Orbit.** Stop here for process setup — use <https://launch.nckrtl.com/orbit.md>. |
| nothing | **Solo.** Continue with this page.                                                 |

This is not a style preference. Orbit's runtime units inject `APP_URL`, `VITE_APP_URL`, and
the `VITE_DEV_SERVER_KEY` / `VITE_DEV_SERVER_CERT` pair that Vite needs to serve assets over
the Orbit domain. The same `bun run dev` started by Solo gets none of those, so HTTPS asset
loading breaks and the page renders unstyled. Defining these processes in both places also
runs each of them twice.

Solo is still useful alongside Orbit as an agent and terminal surface — spawning agents,
reading output, scratchpads, todos. Just do not let it define the app's services.

Herd is different: it serves PHP but does not manage arbitrary processes, so Solo owning Vite,
the queue, and logs under Herd is correct.

## 3. Create solo.yml

**The kit does not ship a `solo.yml`.** It cannot know whether Orbit is managing the project,
and a file that declares these processes would be wrong for every Orbit user. Creating it is
part of setup, and you only do it if step 2 sent you here.

Write `solo.yml` in the project root:

```yaml
name: My App
icon: null
processes:
    Vite:
        command: bun run dev
        auto_start: true
        restart_when_changed:
            - vite.config.ts
            - package.json

    Queue:
        command: php artisan queue:listen --tries=1 --timeout=0
        auto_start: true

    Logs:
        command: php artisan pail --timeout=0
        auto_start: true

    Server:
        command: php artisan serve
        auto_start: false
```

Set `name` to the project's actual name. Leave `Server` on `auto_start: false` and only start
it when nothing else serves the app — under Herd, Herd is already serving it, and starting this
would bind a second PHP server on port 8000.

`solo.yml` is repo-controlled config and the source of truth for YAML-backed commands, so
commit it. Changing a command later means editing this file, not Solo's local state.

## 4. Register the project

```
mcp__solo__list_projects
```

If the project is listed, select it with `mcp__solo__select_project`. If not, create it with
`mcp__solo__create_project` pointed at the project root. Solo parses `solo.yml` and syncs the
processes into its local state.

> **Trust gate:** YAML-defined commands cannot start until they are trusted in the Solo UI, and
> changing `command`, `working_dir`, `auto_start`, `auto_restart`, `restart_when_changed`, or
> `env` can require re-review. If a process refuses to start, this is almost always why — tell
> the user to approve it rather than working around it.

## 5. Start the processes

```
mcp__solo__start_all_commands
```

That starts everything with `auto_start: true`. Use `mcp__solo__start_process` for one, and
start `Server` too only if nothing else serves the app.

## 6. Wait for readiness instead of scraping logs

```
mcp__solo__wait_for_bound_port
```

This blocks until a process exposes a listening port and returns the URL. It returns
`ready=false` with `timed_out=true` if nothing came up, which is a real signal — do not treat a
timeout as success.

`mcp__solo__services_list` shows every detected service with readiness state and URL;
`mcp__solo__get_process_ports` narrows that to one process.

## 7. Verify and debug

```
mcp__solo__get_process_status     — is it running, did it exit
mcp__solo__get_process_output     — recent stdout/stderr
mcp__solo__search_output          — find an error without pulling the whole log
```

Read `Vite` output if the page loads unstyled; the usual cause is `VITE_APP_URL` not matching
`APP_URL`, and Vite says so on startup.

## What not to do

- Do not define the app's processes in `solo.yml` when Orbit manages the project. See step 2.
- Do not run `composer dev`. It runs the same processes under `concurrently` and never exits,
  so it blocks your turn and leaves you no way to inspect them.
- Do not background processes with `&` and later `pkill` them. Solo already tracks lifetime,
  output, and ports; shell backgrounding throws that away.
