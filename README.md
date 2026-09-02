# Launch Starter Kit

Laravel 13 + Launch Laravel + React 19 + Inertia v3 + VitePlus + Tailwind CSS v4 + shadcn

This repository contains the reusable application starter only. The Launch marketing and documentation website lives in [`nckrtl/launch`](https://github.com/nckrtl/launch) and is not shipped in newly created applications.

## Getting started

```bash
composer create-project nckrtl/launch-starter-kit my-app
cd my-app
composer setup
```

`composer setup` is an interactive guided setup. AI agents should follow
[`/create.md`](resources/markdown/create.md) instead, which is the same flow without prompts.

To do it manually:

```bash
cp .env.example .env
# Edit .env: set APP_NAME, APP_URL, VITE_APP_URL (e.g. https://my-app.test)
composer install
bun install                 # also installs the git hooks
bunx playwright install chromium   # browser binary for Pest browser tests
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
bun run build
```

Git hooks need no setup. `bun install` runs `vp config` via the package.json `prepare`
script, which points `core.hooksPath` at VitePlus's dispatcher; that dispatcher runs the
committed [`.vite-hooks/pre-commit`](.vite-hooks/pre-commit) and
[`.vite-hooks/pre-push`](.vite-hooks/pre-push) scripts. Pre-commit runs the `staged` tasks
declared in `vite.config.ts` against staged files only and re-stages what they fix; pre-push
runs `composer test && composer analyse`. Prefix a command with `VP_GIT_HOOKS=0` to skip them
once.

Then set the site up for your local environment — Herd and Orbit each need different steps,
and both serve the app themselves, so neither uses `php artisan serve` or `composer dev`:

- **Herd**: `herd link my-app && herd secure` → see [herd.md](resources/markdown/environments/herd.md)
- **Orbit**: `orbit instance:register my-app --path="$(pwd)" --root=public` → see [orbit.md](resources/markdown/environments/orbit.md)

Set `APP_URL` and `VITE_APP_URL` to whichever domain that gives you. They must match exactly.

## Working on the kit itself

The starter kit depends on `nckrtl/launch-laravel` from Packagist. To develop against a local
checkout, link it rather than adding a path repository to `composer.json` — path repositories
resolve only on your machine and break `composer create-project` for everyone else:

```bash
composer link ../../packages/launch-laravel
php artisan package:discover
```

`composer link` keeps `composer.json` and `composer.lock` untouched. Run
`composer unlink <path>` to go back to the published package.

## Agent-facing docs

`/llms.txt` and `/create.md` are served from `resources/markdown/` by `AgentDocsController`.
Edit the Markdown files; no rebuild needed.

## Development

```bash
composer dev    # Server, queue, logs, and vite — only when nothing else serves the app
```

Under Herd or Orbit the app is already served — skip `composer dev` and follow the
environment addendum linked above instead.

## Stack

- **Backend**: Laravel 13, Launch Laravel, PHP 8.4+
- **Frontend**: React 19, TypeScript 7
- **SPA bridge**: Inertia.js v3 with SSR
- **Styling**: Tailwind CSS v4 with shadcn base-nova style
- **Components**: shadcn base-nova components backed by Base UI (`@base-ui/react`)
- **Icons**: Lucide React
- **Toolchain**: VitePlus (Vite 8 + Oxc linting/formatting)
- **Routing**: Waymaker (PHP attributes) + Wayfinder (TS route helpers)
- **Component registry**: `@launch` shadcn registry from [launch-ui](https://github.com/nckrtl/launch-ui)

## AI-Assisted Development

This project uses `AGENTS.md` for AI coding assistants. It includes feature completion gates requiring Pest/Pest Browser coverage and `agent-browser` validation for UI-affecting work.

## License

[MIT](https://opensource.org/licenses/MIT)
