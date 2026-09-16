# Create a new project with the Launch starter kit

You are most likely an AI coding agent that was told to start a new project based on
`launch.nckrtl.com`. This document contains everything you need. Follow it top to bottom.

Do not run `composer setup` — that script is interactive and expects a human at a TTY.
The steps below are the equivalent non-interactive path.

## What you are building on

| Layer            | Choice                                                         |
| ---------------- | -------------------------------------------------------------- |
| Backend          | Laravel 13, PHP 8.4+, `nckrtl/launch-laravel`                  |
| Frontend         | React 19, TypeScript                                           |
| SPA bridge       | Inertia.js v3, SSR enabled                                     |
| Styling          | Tailwind CSS v4 with `tw-animate-css`                          |
| Components       | shadcn `base-nova` style, backed by Base UI (`@base-ui/react`) |
| Icons            | Lucide React                                                   |
| Toolchain        | VitePlus (Vite 8 + Oxc lint/format), Bun as package manager    |
| PHP routing      | Waymaker — PHP attributes, no route files                      |
| Frontend routing | Wayfinder — generated TypeScript route helpers                 |
| Database         | SQLite by default (`journal_mode` WAL, `synchronous` NORMAL)   |

## Prerequisites

Verify these before starting. If one is missing, stop and tell the user rather than
guessing at a workaround.

- PHP 8.4 or newer (`php -v`)
- Composer 2 (`composer -V`)
- Bun 1.3 or newer (`bun -v`)
- Optionally Orbit or Herd for local HTTPS domains

## If you can spawn subagents, read this first

Setup fans out well. The critical path is short:

```
composer create-project  →  .env  →  bun run build  →  verify
```

Everything else hangs off it. If you cannot spawn subagents, skip this section and follow the
numbered steps in order — same result, just slower.

### Before creating anything

These have no dependencies. Run them concurrently instead of in sequence:

- `command -v orbit` and `command -v herd` (step 2a)
- checking your own tool list for `mcp__solo__*` (step 2b)
- fetching whichever addendum that implies

### Immediately after `composer create-project`

Fan out. Nothing here depends on anything else here:

| Task                                              | Owner           | Produces               |
| ------------------------------------------------- | --------------- | ---------------------- |
| `bun install && bunx playwright install chromium` | subagent        | —                      |
| register the site (Herd/Orbit addendum)           | subagent        | the app URL for `.env` |
| git hooks (step 5)                                | subagent        | —                      |
| ask the user for the project name                 | **main thread** | `APP_NAME` for `.env`  |

Ask your questions while those run. That is the point — the main thread should never sit idle
waiting on `bun install`.

### Join at .env

`.env` needs both answers: the URL from the registration subagent and the name from the user.
Write it once, when both are in. Do not write a placeholder and rewrite it later.

`bun run build` depends on this join, not merely on `bun install`. `VITE_APP_NAME` is read from
`.env` and baked into the JS bundle as the document title suffix, so a build started before the
name is set produces a bundle you have to discard.

### Fan out again after the build

- `composer test`
- boot the app and check for the built CSS (step 6)

### What must stay serial

- `composer create-project` — everything depends on it.
- `.env` before the site is registered — under Herd or Orbit the URL is not `localhost`, and you
  do not know it until registration returns.
- `bun run build` before `.env` — the bundle would carry the wrong app name.

## 1. Create the project

```bash
composer create-project nckrtl/launch-starter-kit my-app
cd my-app
```

Composer's lifecycle scripts have already done the following, so do not repeat them:

- created `.env` from `.env.example`
- installed the PHP dependencies
- generated `APP_KEY`
- created `database/database.sqlite` and run the migrations

In particular, do **not** run `cp .env.example .env` now — it would overwrite the `.env` that
already holds your generated `APP_KEY`.

## 2. Detect the environment

Two questions, in order — the second depends on the first. Answer both now, before writing
`.env`.

### 2a. What serves the app?

```bash
command -v orbit >/dev/null 2>&1 && echo "orbit"
command -v herd  >/dev/null 2>&1 && echo "herd"
```

If both print, prefer **orbit**.

| Detected | Do this                                                                            |
| -------- | ---------------------------------------------------------------------------------- |
| `orbit`  | Read <https://launch.nckrtl.com/orbit.md> now and follow it for steps 3 and 6.     |
| `herd`   | Read <https://launch.nckrtl.com/herd.md> now and follow it for steps 3 and 6.      |
| neither  | Stay on this page. The app runs on `php artisan serve` at `http://localhost:8000`. |

Fetch the addendum before continuing — it changes the URLs you are about to write and the
command you use to run the app.

### 2b. What runs the long-running processes?

Vite, the queue worker, and the log tailer have to run somewhere. **Whatever serves the app
owns them**, so 2a decides this first.

Check **your own tool list** for tools named `mcp__solo__*`. This is not a shell check; it is
about what you as an agent can call. The `solo` binary being on `PATH` does not count if you
have no Solo tools.

| 2a result | Solo MCP tools | Who owns the processes                                           |
| --------- | -------------- | ---------------------------------------------------------------- |
| `orbit`   | either         | **Orbit** — register them with `orbit process:add`. Not in Solo. |
| `herd`    | yes            | **Solo** — see <https://launch.nckrtl.com/solo.md>.              |
| `herd`    | no             | `composer dev`, or run Vite yourself.                            |
| neither   | yes            | **Solo** — see <https://launch.nckrtl.com/solo.md>.              |
| neither   | no             | The backgrounded server in step 6, then `composer dev`.          |

Orbit wins whenever it is present. Its runtime units inject `VITE_DEV_SERVER_KEY` and
`VITE_DEV_SERVER_CERT`, which Vite needs to serve assets over the HTTPS Orbit domain; the same
command started by Solo gets neither, and defining it in both places runs it twice.

The kit therefore ships **no** `solo.yml` at all. Creating one is part of setup, and only when
Orbit is not managing the project — `/solo.md` has the file to write.

## 3. Configure the environment

> Using Orbit or Herd? Take these values from that addendum instead — the URL is not
> `localhost`.

Edit the existing `.env` and set these three values. `VITE_APP_URL` must match `APP_URL`
exactly, or Vite serves assets from the wrong origin and the page loads unstyled.

```dotenv
APP_NAME="My App"
APP_URL=http://localhost:8000
VITE_APP_URL=http://localhost:8000
```

## 4. Install frontend dependencies and build

```bash
bun install
bunx playwright install chromium
bun run build
```

The `playwright` npm package comes in with `bun install`, but the Chromium binary it drives
is a separate one-time download. Pest browser tests (`composer test:browser`, also part of
`composer check`) fail without it.

## 5. Git hooks

`bun install` in step 4 runs `vp config` via the package.json `prepare` script, which points
`core.hooksPath` at VitePlus's dispatcher; that dispatcher runs the committed
`.vite-hooks/pre-commit` and `.vite-hooks/pre-push` scripts.

`composer create-project` from a dist or local path artifact does not create a git repository,
and `vp config` cannot install hooks until one exists. If `.git` is missing after step 1:

```bash
git init -b main
vp config
```

Pre-commit runs the `staged` tasks from `vite.config.ts` against staged files and re-stages
what they fix. Pre-push runs `composer test && composer analyse`. Prefix a command with
`VP_GIT_HOOKS=0` to skip them once.

Do not configure `hook.*` git config keys. Nothing reads them.

## 6. Verify the install

> Using Orbit or Herd? The app is already being served — skip the `php artisan serve` part
> below and verify against your real URL as described in that addendum.
>
> Have Solo MCP tools? Use <https://launch.nckrtl.com/solo.md> instead of the backgrounding
> below — Solo tracks process lifetime, output, and readiness for you.

First the test suite:

```bash
composer test
```

Then confirm the app actually boots and serves its built assets. Start the server in the
background so this does not block, and check for the built CSS:

```bash
php artisan serve --port=8000 >/dev/null 2>&1 &
until curl -s -o /dev/null http://localhost:8000/up; do sleep 1; done
curl -s http://localhost:8000 | grep -o 'build/assets/app-[A-Za-z0-9_-]*\.css'
```

A filename means the page is rendering with its compiled stylesheet. **No output means the
page is unstyled** — almost always because `VITE_APP_URL` does not match `APP_URL`. Stop the
server when you are done:

```bash
pkill -f "artisan serve --port=8000"
```

### Day-to-day development

```bash
composer dev
```

This runs the PHP server, queue worker, log tailer, and Vite together. **It does not exit.**
Never run it as a blocking foreground step in an automated flow — start it in the background,
or tell the user to run it themselves.

## Local environment addenda

Each addendum below is required reading when it applies, not optional. Read the serving one
first — it decides whether the process one applies at all.

**How the app is served** — replaces steps 3 and 6:

- [Laravel Herd](https://launch.nckrtl.com/herd.md) — Herd serves the app itself; do not run
  `php artisan serve` or `composer dev`.
- [Orbit](https://launch.nckrtl.com/orbit.md) — Orbit serves the app through a managed
  FrankenPHP process; do not run `php artisan serve` or `composer dev`.

**How long-running processes are run** — replaces step 6:

- [Solo](https://launch.nckrtl.com/solo.md) — for agents with the Solo MCP server, **and only
  when Orbit is not managing the project**. Under Orbit, processes belong in Orbit. Creating
  `solo.yml` is part of setup; the kit does not ship one.

If none apply, this page is complete on its own.

## Alternative: starting from a git clone

`composer create-project` is the supported path. If you clone the repository instead, none of
the lifecycle scripts run, so do their work by hand before continuing at step 2:

```bash
git clone https://github.com/nckrtl/launch-starter-kit.git my-app
cd my-app
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
```

---

## Next: the conventions

Setup is done. Before writing any code in this project, read
<https://launch.nckrtl.com/conventions.md>.

It covers the rules that keep a Launch project consistent — attribute routing with Waymaker,
typed URLs with Wayfinder, Base UI rather than Radix, where design tokens live, SSR
constraints, the command list, and the quality gates a feature has to clear before you can call
it done. Skipping it produces code that runs but fights the toolchain.
