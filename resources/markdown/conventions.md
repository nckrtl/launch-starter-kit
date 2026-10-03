# Conventions for building on the Launch starter kit

Read this once the project runs. If you are still setting it up, start with
[the setup guide](https://launch.nckrtl.com/create.md).

These rules are what keep a Launch project consistent. Most of them exist because the kit picks
one option where the ecosystem offers several — following them is cheap, and ignoring them
tends to produce code that works but fights the toolchain.

The same rules are written to `AGENTS.md` in the project root when it is generated. That file
is the authoritative copy for a given project, since a project can amend it. This page is the
canonical version of the defaults.

## Routing: `routes/web.php`

Declare every web route in `routes/web.php` and give it a dotted name:

```php
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
```

Use the name in PHP redirects, for example `to_route('projects.show', $project)`. Check the result
with `php artisan route:list`.

## URLs in the frontend: Wayfinder, not strings

Wayfinder generates typed helpers at build time. Import them; never hardcode a URL.

```tsx
import { show } from "@/actions/App/Http/Controllers/ProjectController";

<Link href={show(project.id)}>Open</Link>;
```

## Components: shadcn base-nova on Base UI

```bash
bunx shadcn add button dialog               # shadcn components on Base UI primitives
bunx shadcn add @launch/app-sidebar-layout  # layouts from the @launch registry
```

Never install `@radix-ui/*` packages. This kit is configured for Base UI, and mixing the two
gives you two incompatible primitive layers.

## Styling

Design tokens live in `resources/css/theme.css` as CSS custom properties. Put new tokens
there rather than scattering literal colors through components.

## Pages

Page components go in `resources/js/pages/` and are resolved by Inertia automatically. SSR is
on, so keep server and browser renders deterministic and keep browser-only APIs
(`window`, `localStorage`, `matchMedia`) out of render paths — read them in effects.

## Internationalisation

Off by default. Enable in `vite.config.ts`:

```ts
export default await defineLaunchConfig({ i18n: true });
```

Then add `lang/en.json`, and use the `__()` helper in components:

```tsx
import { __ } from "@nckrtl/launch-ui/i18n";

<p>{__("Hello :name", { name: "Nick" })}</p>;
```

## Commands

| Command                 | Does                                                                                                                 |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `composer dev`          | Server, queue, logs, and Vite together                                                                               |
| `composer test`         | Pest tests — full suite before the repo's first commit, test-impact analysis (`--tia`) once a baseline commit exists |
| `composer test:browser` | Builds assets, then runs Pest browser tests (`tests/Browser`)                                                        |
| `composer analyse`      | PHPStan at level 9                                                                                                   |
| `composer lint`         | Pint (PHP formatting)                                                                                                |
| `composer check`        | test + analyse + frontend lint + browser tests                                                                       |
| `composer fix`          | Rector + Pint + frontend autofix                                                                                     |
| `bun run dev`           | Vite dev server only                                                                                                 |
| `bun run build`         | Production build (client + SSR bundles)                                                                              |

Browser tests drive a real Chromium through Playwright. The `playwright` npm package ships
as a devDependency, but the browser binary is a separate one-time install:
`bunx playwright install chromium`. Without it, `composer test:browser` — and therefore
`composer check` — fails.

`composer dev` does not exit. Never run it as a blocking foreground step in an automated flow.

## Quality gates

The kit is configured for PHPStan level 9 and Pest 5. Before reporting a feature as done:

- Backend behaviour needs a Pest feature or unit test.
- Inertia responses need assertions on the component name and props.
- User-visible workflows need a Pest Browser test in `tests/Browser/`.
- `composer check` must pass.

State plainly which of these you ran. If a change has no user-visible surface, say so rather
than silently skipping the browser test.
