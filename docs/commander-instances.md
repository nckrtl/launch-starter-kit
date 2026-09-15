# Commander instances on Beast

| Instance | Checkout | Branch | URL | SSR port |
| --- | --- | --- | --- | --- |
| main (Orbit ID 5) | `/home/nckrtl/apps/commander/main` | `main` | `https://commander.test` | 13719 |
| tasks (Orbit ID 6) | `/home/nckrtl/apps/commander/tasks` | `codex/project-tasks` | `https://tasks.commander.test` | 13729 |

Each instance has its own `.env`, SQLite database, storage, and build. Set
`INERTIA_SSR_PORT` before building; Laravel and the generated SSR bundle use it.
Orbit owns each instance's SSR process. Do not start a second development server.

The original `/fast/apps/commander` checkout is retained as a rollback source and
the Git common directory for the linked main worktree. It is no longer the live
Commander instance. Do not delete or move it while linked worktrees depend on it.

The existing main instance and `commander.test` route retain IDs 5 and 4. The
route keeps its existing domain and provenance; it is not regenerated or replaced.
The Tasks instance and its database are not merged into the main database.

Issue processing remains paused: auto-admission is disabled, the legacy worker
and scheduler are stopped and disabled, and the Tasks worker's desired state is stopped.
Do not resume them as part of a checkout update or deployment.

## Verification on 2026-09-15

The merged code passed 330 tests (5,838 assertions) in these Feature files:

- `OrbitIssueProviderTest.php`
- `TaskOrbitIssueReaderTest.php`
- `OrbitResolutionPromptTest.php`
- `OrbitPullRequestReviewReceiptCommandTest.php`
- `TaskTest.php`
- `TaskReviewTest.php`
- `TaskInterfaceTest.php`
- `HerdrRuntimeTest.php`
- `ProjectDetailsTest.php`

The deployment adjustments passed 5 tests (28 assertions) in
`tests/Feature/InertiaSsrConfigurationTest.php` and
`tests/Feature/CommanderDeliveryWorkerServiceTest.php`.
`tests/Browser/TasksTest.php` passed 5 tests (89 assertions). All tests used
isolated, in-memory test databases, not the live application databases.

`vp run build` completed both client and SSR builds. The main SSR bundle embeds
port 13719. The Inertia build plugin emitted a non-fatal sourcemap warning.
Both live SSR health endpoints returned OK, and both HTTPS URLs returned 200
using the Orbit CA. A real browser loaded both dashboards and navigated main
to Projects without JavaScript or console errors. The full suite was not run.

The gateway database backup is on Gateway at
`/tmp/commander-main-cutover.rRdw62/gateway-before.sqlite`. The original
Commander database and storage are retained under `/fast/apps/commander`.
