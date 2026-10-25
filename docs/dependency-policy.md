# Dependency policy

This starter kit uses Composer (`composer.lock`) and Bun (`bun.lock`). `package.json` declares `packageManager` as `bun@1.3.14`. Vite+ is the JavaScript command surface: call the project-local `node_modules/.bin/vp`, never a global `vp`. Commit every lockfile you change.

This repository has no release-age policy. There is no `vet.json`, no Bun `minimumReleaseAge`, and no `.npmrc` `min-release-age`. Do not add one in a dependency pull request. Security fixes do not need an age exception, because nothing waits.

## Routine updates

For a targeted update, update only the packages you need (`composer update vendor/package --with-dependencies`, `vp update <package>`). Review the lockfile diff and run the Project task check: `composer test && composer analyse && vp check`. `composer check` also runs the browser tests.

## Security fixes

A fix for a known advisory ships as its own change.

1. Link the advisory in the pull request.
2. Update only that one direct package.
3. Run the task check and get a review.

Do not silence an advisory that has no fix. Record it in the pull request with the advisory link and the reason the risk is acceptable. Do not run `audit fix` or `audit fix --force`.

## Routine task script

Run `scripts/dependencies routine` from an isolated task checkout. It requires the installed project-local `node_modules/.bin/vp` and fails clearly before updating if that executable is missing. It runs `composer update --no-interaction`, then `./node_modules/.bin/vp update`, from the repository root. Vite+ delegates to the Bun version declared by `packageManager`. Existing constraints, overrides, and lifecycle scripts apply. It stops at the first failed command and returns that exit code. It does not change ranges, run audits, commit, or publish.

The orchestrator runs the task check separately after the update. If checks fail, it permits one agent repair turn, then checks again. Publish only after checks pass; stop for assistance if the repair fails. If no files change, finish without a pull request.

The local script regression uses stub package managers and makes no network requests: `vendor/bin/pest tests/Unit/DependencyScriptTest.php tests/Unit/DependencyPackageScriptTest.php`.

## Detect and update one package

`scripts/dependencies detect` reads Composer major-only outdated JSON from the lockfile (`composer outdated --locked --major-only --format=json`) and `composer audit --locked --format=json`. Neither needs `vendor` in a fresh VM. For JavaScript it runs `./node_modules/.bin/vp outdated` and `./node_modules/.bin/vp pm audit --json`. Bun does not support `outdated --format json`, so detection reads Bun's table. It compares `current` with `latest`: a different major version is a major, and so is a different minor version while the major is `0`. A newer version inside the same major (or the same `0.x` minor) is not a major.

Bun's audit JSON is a map of package name to advisory objects (`id`, `url`, `title`, `severity`, `vulnerable_versions`). It includes transitive packages. Detection reads `bun.lock` and attaches each advisory to the direct dependency that reaches it. A vulnerable package that is itself direct stays on that package, even when another direct dependency peers it. Peer dependencies count, so a Storybook advisory reached through `@storybook/addon-mcp` belongs to `@nckrtl/launch-ui`. An advisory that no direct dependency reaches fails detection. The advisory id is the advisory's own id, never the package name.

`vite` is an npm alias of `@voidzero-dev/vite-plus-core`. Detection reports that major on the direct name `vite`. Package mode refuses the alias and asks for assistance instead of guessing a rewrite.

Detection prints one JSON object with package candidates and does not change files or choose a version. Findings are normal audit results. Transport errors or malformed results fail detection.

`scripts/dependencies package <composer|js> <name> <x.y.z> <major|security> [advisory-id ...]` updates one existing direct dependency. The version is explicit, stable, and literal. Major mode raises only that package's range to `^x.y.z`. Security mode pins the selected fixed version. Composer uses `require --no-update`, then a targeted `update --with-dependencies`. JavaScript uses `vp add` in the dependency's existing section. Other direct ranges and overrides stay as they are. Alias, workspace, and git dependencies, and packages present in several sections, require assistance rather than guessing.

Security mode requires advisory ids reported for that exact package before the update. This repository has no Composer age exclusion and no Bun age bypass, so the script does not create `vet.json` and does not pass `--min-release-age=0`. The script audits again and fails if a selected advisory remains. It does not ignore advisories. An unrelated audit finding stays visible without expanding the package scope.

Run each package in its own new task VM and group from current main. Check and publish that package alone. One repair turn may finish the exact package command and fix breakage. Further failures need assistance. Detection and package modes do not create groups, commit, publish, or merge.
