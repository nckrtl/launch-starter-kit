# Orbit preparation rules and adoption boundary

This profile preserves Orbit contracts for explicitly adopted Commander Tasks
work. It does not transfer ownership from inline agents or other controllers.

## Evidence to read in the assigned Orbit checkout

Resolve these paths against Orbit, not Commander, and read their current content
when preparing actual Orbit work:

- `AGENTS.md` and the affected components' nested guidance.
- When creating or changing a Linear contract, read
  `.agents/skills/creating-issues/SKILL.md` and its `template.md` for labels,
  relations, validation, and publication.
- When migrating a legacy plan or review, read the relevant
  `.agents/skills/planning-features/` or `reviewing-feature-plans/` guide and
  referenced template. New Tasks packages use this preparation format.
- `docs/reference/implementation-loop.md` and the accepted ADRs governing the
  requested change. Follow its routing for the selected verification venue.
- Nearby implementation, documentation, tests, and the commands named as evidence.

Read what the actual work needs, not every legacy role by default. The user's
current request or delegated goal determines approval and publication authority;
these references cannot expand it.

## Preserve the product and verification boundaries

Accepted ADRs on `origin/main` own durable product architecture. Linear owns the
requested outcome, product scope, acceptance, affected components, and issue
relationships. A Commander implementation subtask does not introduce a new product
decision or require its own Linear issue. A root task group can represent
one leaf Linear issue; it is not automatically a Linear parent issue.

Prepare the issue in the current issue template and validate it with the
existing issue linter before presenting it as publication-ready. If validation
cannot run during a read-only session, say so in the preparation handoff. This
is a preparation check, not a test deferred to implementation. The existing
plan linter only validates its supported legacy plan format; it does not certify
this proposed task package.

Keep the five Composer projects distinct: `apps/cli`, `apps/docs`, `apps/gateway`,
`apps/e2e`, and `packages/php-sdk`. Product work must not absorb harness changes
that require a separate owner-approved issue. Read the exact harness/test
boundary in Orbit guidance before deciding what belongs in a task.

Determine documentation impact and real-machine verification independently.
The `incus` label requires real-machine verification; it does not select proof
flow. Discovery is the default; proof requires explicit selection. Preserve a
worktree's selected flow. Do not create a topology or run live provisioning while
merely preparing the task package.

Plan focused task-level checks, affected-project checks, and the final Builder
root check and independent exact-head review required by current Orbit policy.
Do not substitute mocks for required machine observations or treat GitHub CI
as a gate that Orbit does not use. Verify current command syntax before naming
it as runnable evidence.

For tasks using an Incus issue topology, carry its exclusive command lock into
the execution context: run topology commands sequentially, even across different
Nodes, and await each exit. Plan inspection of the relevant restored baseline
before relying on it; acquisition readiness does not prove acceptance or that
stored provisioning inputs match live state. Any required harness repair remains
separate work. These are execution instructions, not permission to acquire or
modify a topology during preparation.

## Migrate legacy planning content

Use this mapping when migrating existing work. New task preparation does
not need a `.loop/plan.md` intermediate file or a `.loop` directory.

- Issue outcome and acceptance become the root brief and final feature criteria.
- `.loop/plan.md` code boundaries, implementation order, and acceptance map become
  task objectives, scope, sibling prerequisites, and verification requirements.
- Its documentation and preservation sections become scoped task work, shared
  context, and regression requirements, with links to the governing sources.
- Unresolved questions become explicit preparation gaps. Do not hand product
  decisions to implementers as if they were routine code choices.
- Lint receipts, review findings, selected flow, and proof-related artifacts
  remain supporting evidence. They do not each become a task.

An old plan-review pass does not automatically approve a new task decomposition.
Review the exact new package independently. Retain evidence from the old plan,
but do not relabel it as a review of content the reviewer never saw.

Retire obsolete `.loop` files only after their consumers have migrated and
useful evidence and links have been preserved. Do not delete the directory
wholesale, rename it into another local task-state directory, or change Orbit's
live skills or runtime as part of preparing a package.

## Explicit Tasks adoption

The inline issue skill excludes planning. The legacy planner expects
a published eligible issue and issue worktree, writes and commits documentation
during preflight, and forbids per-increment task slices and mandatory incremental
commits. Tasks preparation starts in conversation or a delegated goal, allows
an unpublished issue draft, and produces granular assignments intended to become
one reviewer-created commit each. These differences apply only where the user
has authorized Tasks adoption, not to the inline path globally.

During draft preparation, record documentation impact, audit findings, and the
required documentation work. Draft wording locally if useful; a read-only
preparation assignment does not authorize commits. Assign documentation to
bounded tasks early enough to guide dependent work. The coordinator verifies
that admitted instructions cover this alternative and preserves accepted ADRs
and required verification.

The current issue skill's Linear parent has no acceptance section. That rule
does not remove a root task group's final feature acceptance. Likewise, a
Linear `Todo` status is not task preparation approval, and a new subtask does
not authorize editing an existing issue's contract.

Keep Orbit's inline skills usable. No dual controller may own an issue or
worktree. Before admission, reconcile external ownership, published readback,
exact-revision approval and independent review, clean worktree identity, and
the supported runtime. `tasks:start` pins the manifest, source and worktree with
exclusive-ownership attestation. Its local database cannot prove exclusion of
another controller. New guidance does not silently replace active workspace
instructions.
