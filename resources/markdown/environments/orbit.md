# Setting up on Orbit

Addendum to [the main setup guide](https://launch.nckrtl.com/create.md). Read that first.
This page replaces its environment and run steps; everything else there still applies.

Orbit serves the application through a FrankenPHP process managed by the gateway. **Do not run
`php artisan serve`, and do not run `composer dev`** — the app is already being served.

> There is no `orbit link` command. Running it prints the help listing and exits `0`, so it
> looks like it worked. Registering an existing directory is `orbit instance:register`.

## 1. Register the project directory

From the project root:

```bash
orbit instance:register my-app --path="$(pwd)" --root=public --php-version=8.5
```

The command is idempotent and does not clone — it adopts the path you are already in. The kit
requires PHP 8.4 or newer, so pass 8.4 or 8.5.

If register reports that `--node` is required, this machine has no `orbit node:default`. Pass
the local app-dev node explicitly — for example `--node=NMBP` on a node named `NMBP`. Do not
guess a different node, and do not change `node:default` just to make the command shorter.

To create the app and clone it in one step instead of adopting an existing checkout, use
`orbit app:new` — but for the `composer create-project` flow in the main guide, the directory
already exists, so `instance:register` is the right command.

## 2. Read back the URL Orbit assigned

```bash
orbit app:show my-app
```

Look at the `Routes` line and the `URL` column. It will be something like
`https://my-app.<node>` — for example `https://launch.nmbp` on a node named `NMBP`. Some apps
route as `<app>.test` instead. Use exactly what the command prints; do not guess it.

## 3. Point .env at that URL

Both values must be the URL from step 2, matching exactly:

```dotenv
APP_NAME="My App"
APP_URL=https://my-app.nmbp
VITE_APP_URL=https://my-app.nmbp
```

A mismatch makes Vite serve assets from the wrong origin and the page loads unstyled. This is
the single most common Orbit setup failure with this kit.

## 4. Register the long-running processes with Orbit

Orbit owns this project's processes. Register Vite and the queue worker as Orbit processes
rather than starting them by hand, in Solo, or through `composer dev`:

```bash
orbit process:add vite 'vp dev --host' \
  --instance=my-app.development --restart-policy=on_failure

orbit process:add queue 'php artisan queue:work --tries=3' \
  --instance=my-app.development --restart-policy=always
```

`--host` with no value is Vite's externally reachable bind: it listens on every interface.
That flag is the listen address only. Do not pass the Orbit hostname as the bind value — on
the host that name often resolves to loopback, so Vite binds `127.0.0.1` and phones or other
VPN clients cannot reach CSS or JS.

Orbit's runtime units inject `APP_URL`, `VITE_APP_URL`, `VITE_DEV_SERVER_ORIGIN` when
applicable, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`. Those
supply the HTTPS hostname and certificate. The Laravel Vite plugin writes `public/hot`
using that hostname plus the actual bound port, so a fallback Vite port is recorded
automatically. `public/hot` must still advertise `https://<app-domain>:<vite-port>` —
for example `https://my-app.nmbp:5173`.

`vp dev` or `bun run dev` without `--host` binds loopback, which phones and the FrankenPHP
container cannot reach. The same `vp dev` started outside Orbit also lacks the injected
certificate, so assets fail to load and the page renders unstyled.

Do not register a separate SSR process: in development the `vite` process above serves
Inertia SSR too. If Vite's default port is taken by another project, it picks the next one
and records it in `public/hot` — that is normal and needs no configuration.

If the agent also has the Solo MCP server, **do not** define these commands in `solo.yml`.
They would run a second time without Orbit's environment. Solo remains useful alongside Orbit
as an agent and terminal surface; it just does not own the app's services here.

Inspect and manage them with:

```bash
orbit process:list --instance=my-app.development
orbit process:update vite --command='vp dev --host' --restart
orbit process:remove vite --instance=my-app.development
```

## 5. Verify

```bash
orbit doctor
```

That checks Orbit's health and reports drift. Then:

```bash
curl -sI https://my-app.nmbp | head -1
```

Expect `HTTP/2 200`. A freshly registered instance can answer `503` for a minute or two
while Orbit activates it — poll until it turns `200` before diagnosing anything. Open the
URL — you should see the Launch homepage, styled. A local browser can look fine while a
phone or VPN client stays unstyled: that is the listen-address failure above, not an
`APP_URL` mismatch. Confirm `public/hot` still advertises `https://<app-domain>:<vite-port>`,
then `orbit process:update vite --command='vp dev --host' --restart`.

Useful when something looks wrong:

- `orbit app:show my-app` — current routes, PHP version, and processes
- `orbit app:log my-app` — tail the Laravel log for the resolved app
- `orbit php:use` — change the PHP runtime for the instance
- `orbit instance:register …` again — idempotent, re-converges runtime and route intent
