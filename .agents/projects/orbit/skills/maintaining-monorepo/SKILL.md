---
name: maintaining-monorepo
description: Use in Commander for the registered Orbit project when main checks fail or monorepo cache maintenance needs diagnosis or repair across Orbit's Composer projects.
---

# Maintaining the Monorepo

Read the [Orbit project guide](../../AGENTS.md) and use its selected checkout and issue board.

Diagnose a failed main check or cache refresh across Orbit's five Composer projects. Routine refreshes use repository scripts.

## Diagnose

Read the `docs/reference/implementation-loop.md#maintenance-recovery` in the Orbit checkout. Inspect the failed command, its logs, and the commit it checked. For cache incidents, also inspect `bin/tia-cache status --json --remote`. Compare that commit with current main to see whether a later change fixed the problem.

Coordinate with the active maintenance worker before changing its checkout or caches. Keep each project's dependencies and writable caches separate.

Distinguish the cause:

- A download, environment, or cache-publication failure needs infrastructure recovery. Keep the last compatible successful caches available; checks can also run with cold caches.
- A failed test, formatter, or static analysis on main needs a repair or revert. Report it so unrelated merges wait for a verified fix.
- An unresolved cause needs a clear account of the evidence and next diagnostic step.

## Recover

Start with the failed command. Correct the cause before retrying. Preserve useful logs and run affected project checks sequentially with bounded test workers.

Make source repairs in a separate worktree through `developing-features` in the Orbit checkout. Keep the repair focused. Use `reviewing-pull-requests` in the Orbit checkout for independent review, then obtain maintainer approval before merge.

Use the repository cache commands and their compatibility checks. Keep successful publications and other workers' files intact. Publish main baselines only from checked main source.

After a repair merges, repeat the failed check on main containing the fix. Inspect the test and quality-cache results for each affected project. Confirm the published commit and check results; a queued refresh only confirms that work was requested.

## Report

Return the cause, affected projects, failing and checked commits, actions taken, check results, logs, and cache state. State whether recovery is verified, a repair is ready for review, or work remains. The maintainer or coordinator lifts the merge hold after verification on main.
