# Integrate current main before Orbit proof

This CLI/runtime operation applies only to Orbit's selected proof profile.
Discovery keeps its relaxed freshness rule. Reattempt and checkpoint workspaces
are not supported by this operation; their recovery evidence must be retained.

Only newly created final dispatches opt into the preflight. Existing prepared,
sent, or ambiguous final assignments are not reinterpreted. Accepted child
commits, original task briefs, run inputs, handoffs and reviews stay unchanged.

## Clean pre-final boundary

After all children are accepted, Commander fetches `origin/main` and verifies
its identity against the remote. Git and remote observations happen outside
the shared runtime/database lock, followed by an exact state recheck. Missing
ancestry creates an immutable `integration_required` final dispatch and an
attention hold before Builder or proof starts. No reviewer prompt is sent.

Main is checked again after a successful Builder check and before proof. If it
moved, the hold also retains that exact Builder result. A hold is not a failed
proof, a review verdict, or an uncertain external dispatch.

Use `tasks:inspect orbit ROOT_ID` to read the dispatch, its `final_preflight`
and workspace attention. This version adds no UI banner or automatic resume.

## Preview, apply, advance

Use the held dispatch's accepted head and manifest. Pin the freshly observed
remote main, not an unverified local primary checkout. For example:

```sh
php artisan tasks:integrate-main WORKSPACE_ID \
  --dispatch=HELD_DISPATCH_ID --head=ACCEPTED_HEAD --manifest=MANIFEST_HASH \
  --main=CURRENT_MAIN_SHA --reason='Current main is required before proof.' \
  --evidence='Evidence explaining this exact ancestry hold.' --exclusive
```

Inspect the preview, then repeat with `--apply`. Applying records one
`TaskFinalContinuation` with mode `integrate_main` and appends one child after
the accepted tail. It does not change the checkout, launch an agent, or queue
advancement. Start the next instruction separately:

```sh
php artisan tasks:advance WORKSPACE_ID
```

The same request is idempotent. A conflicting replay is refused. Dirty or active
work, a changed manifest/head, uncertain dispatch ownership, or a stale requested
main cannot be applied. If hold A precedes newer main B, a fresh request for B
is allowed only when B descends from A. The original hold A remains unchanged
and is exported with the actual integration parent pair. Force-pushed main
requires separate reconciliation; this operation does not guess its meaning.

## Existing task cycle

The new child has a fresh implementer and the retained independent reviewer:

1. Implementer prepares `git merge --no-commit --no-ff PINNED_MAIN`, resolves
   integration conflicts/regressions, runs affected checks, and submits.
2. Reviewer independently checks the exact resulting tree against both ordered
   parents and affected root criteria. Changes requested return to the same
   implementer without a commit.
3. After a pass, a separate instruction asks that reviewer to create one merge
   commit. Commander accepts only the reviewed tree and ordered parents
   `[accepted_tail, pinned_main]`, with a clean worktree.

Only the audit-bound task gets this exception. An ordinary task still needs
exactly one parent. During integration review, `MERGE_HEAD` must contain exactly
the pinned main; conflicts, extra parents and other active Git operations fail.
The local integration never authorizes publication, landing or deployment.

If main advances while this assigned task runs, its exact reviewed pinned merge
can still be accepted. The next clean preflight holds again if another
integration is needed. No accepted commit is rebased or rewritten.

Integration composes with prior normal manifest amendments and final corrections.
Later final corrections remain ordinary single-parent tasks. The pre-proof and
landing packages retain integration parent bindings, the original hold, and
the immutable manifest/continuation chain. Old packages without integrations
retain their existing serialized shape.

## Deployment

Apply the additive migration before deploying the corresponding runner code.
Drain only the affected Tasks process; never restart shared Herdr to deploy this
operation. Preserve active task assignment bytes. New final dispatches opt in
through `final_preflight_version=1`; null keeps historical behavior.

Rollback refuses to discard recorded preflight or manifest evidence. Use a
forward fix once those records exist. The coordinator owns deployment and must
verify the frozen source allowlist/preimages before copying any file.
