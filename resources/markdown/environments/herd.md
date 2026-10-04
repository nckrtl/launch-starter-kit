# Setting up on Laravel Herd

Addendum to [the main setup guide](https://launch.nckrtl.com/create.md). Read that first.
This page replaces its environment and run steps; everything else there still applies.

Herd serves the application itself through its own nginx and PHP. **Do not run
`php artisan serve`, and do not run `composer dev`** — both start a second PHP server that
competes with the one Herd already gave you.

## 1. Check the PHP version

The kit requires PHP 8.4 or newer.

```bash
herd use 8.4
```

That sets the global version for every site without an isolated version. Confirm with
`php -v`.

## 2. Give the site a domain

If the project sits inside a directory you have already parked, Herd is serving it at
`<folder-name>.test` and there is nothing to do. Otherwise, link it from the project root:

```bash
cd my-app
herd link my-app
```

That serves the current directory at `my-app.test`.

## 3. Enable HTTPS

```bash
herd secure
```

This issues a trusted certificate for the current directory's site, so it becomes
`https://my-app.test`. Do this before writing the URLs into `.env` — the scheme has to match.

## 4. Point .env at the Herd domain

Both values must be the secured domain, and they must match exactly:

```dotenv
APP_NAME="My App"
APP_URL=https://my-app.test
VITE_APP_URL=https://my-app.test
```

If you skipped `herd secure`, use `http://` in both instead. A mismatch between the two, or
between the scheme here and the site's real scheme, makes Vite serve assets from the wrong
origin and the page loads unstyled.

## 5. Run only Vite

Herd already runs PHP, so the dev loop is just the asset server:

```bash
bun run dev
```

If you also want the queue worker and log tailer, start them separately:

```bash
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
```

## 6. Verify

```bash
curl -sI https://my-app.test | head -1
```

Expect `HTTP/2 200`. Then open `https://my-app.test` — you should see the Launch homepage,
styled. If it renders unstyled, `VITE_APP_URL` does not match `APP_URL`.

Useful when something looks wrong:

- `herd open` — open the current site in a browser
- `herd unsecure` — drop back to plain HTTP
- `herd unlink my-app` — remove the site registration
