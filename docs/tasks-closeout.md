# Exact Tasks closeout for Orbit

This explicit CLI bridge publishes and lands an independently approved Tasks
package. It leaves task acceptance, dispatches, original final review, package,
and supplemental review unchanged. It does not invent a Delivery/PhaseRun or
change Linear state. The former proof-topology and ORB-91 postinstall procedure
is withdrawn by owner direction on 2026-09-14. Do not run those stages or create
new topologies. Normal closeout does not shut down agent sessions or remove the
original issue worktree.

Only the active exclusive coordinator on the host owning the Tasks database and
worktrees may apply these commands. Pause other merge coordinators while using
incident repair exceptions. The project file lock serializes these commands and
hold changes on this host; it is not a distributed lock across separate
Commander installations. GitHub's existing Orbit reservation still gates merge.

For an explicitly handed-over existing PR with a different head/body, use the
separate [publication revision bridge](tasks-publication-revision.md) after a
new exact Tasks package is independently approved. Ordinary publish remains
create-only; it never silently updates a legacy branch or PR.

## Explicit stages

Start with an independently approved `TaskLanding` and its exact package hash:

```sh
php artisan tasks:closeout LANDING --package=HASH --stage=publish --exclusive
```

Without `--apply`, this previews current evidence and remote state without a
ledger write, object fetch, push, publication, merge, or cleanup. Repeat the exact
command with `--apply` to apply it. Tasks runtime must be enabled for writes.

Use these stages in order, previewing each before applying:

1. `publish`: publish the exact missing candidate branch with a create-only
   lease (`--force-with-lease=refs/heads/orb-N:`). The empty expected value
   cannot change an existing ref, including one created after the initial read.
   An identical candidate can be adopted without an update. Create
   the exact reviewed PR title/body; publish the retained independent review
   through the authorized reviewer principal. Existing matching effects are
   inspected and adopted. Differing branches or PR bytes are refused, not patched.
2. `merge`: verify exact PR and package-specific approval, confirmed mergeability,
   branch rules, current task/issue/artifact ownership, and main incident gates;
   acquire the exact reservation; check the gates again immediately before
   merging the pinned candidate. Retain the preflight facts with the write intent.
3. `verify`: explicitly fetch authoritative main and run native `loop-flow
   verify-merge` for the admitted flow. Retain its lineage, observed main SHA, and native failure
   state. A red native status stays red. This stage does not run the Gateway suite
   or clear any incident. Each distinct verification is retained separately.
4. The former proof closeout/postinstall step is withdrawn. Do not execute it.
5. `release`: after exact merged lineage is verified, release only this package's
   reservation. All main incident holds remain in force. Legacy proof-bound
   records are historical; they do not authorize a new topology run.

The existing authenticated SSH RPC validates `nckrtl` as PR/merge principal and
`tom-nckrtl[bot]` as the separate reviewer principal. Tasks uses the same RPC
services with a sanitized child environment. Credentials stay in their existing
wrappers. No additional Python implementation or credential copy is introduced.

The review marker binds package, candidate, artifact, retained review assignment
and verdict hash, and exact PR title/body hashes. A generic same-head `Approved.`
is insufficient. Another reviewer's unresolved changes request, dismissed or
wrong-principal marker, content/head drift, incomplete pagination, missing rules,
or unconfirmed mergeability holds the action. Identical published bytes do not
require a new reviewer prompt. The only tolerated Linear contract addition is
the exact own-PR attachment; other issue drift requires reconciliation.

## Durable uncertainty

Every external write has immutable inputs and a separate operation identity.
`prepared` means no write intent was issued. A failed read or preflight may be
retried there. Immediately before the one write, the operation retains preflight
facts and becomes `intended`. Only exact readback proves completion.

A lost response is reconciled from that readback. An unresolved intended write
becomes `unknown`; repeating a command only inspects it, never resends it. Keep
the reservation after an uncertain merge. Do not reset an operation, edit its
inputs, delete its history, or synthesize success. A prepared step with changed
immutable inputs also needs explicit reconciliation. Native post-merge fetch and
verification are explicit repeatable observations, not PR/merge retries.

## Main incidents

Main holds are project-owned, not candidate-owned. Import known failures before
any candidate exists. A cached green result, including zero-selected TIA, never
removes a hold. Give each distinct incident a stable identifier and exact
relevant-case identities. A recurrence after clearance gets a new identifier.

Write a JSON request outside the worktree root:

```json
{
  "repository": "/canonical/orbit",
  "main_sha": "<current authoritative main SHA>",
  "incident": "gateway-known-incident",
  "relevant_cases": ["exact case identity", "exact reporting outcome"],
  "attestation": "What I inspected and why this is a current correctness hold.",
  "evidence_file": "/retained/incident-proof.txt",
  "evidence_sha256": "<exact file SHA256>"
}
```

Preview with `tasks:main-hold import --file=FILE --exclusive`; add `--apply` only
after checking it. Evidence must be a canonical bounded regular UTF-8 file. Its
bytes are retained with the hash, not merely its temporary path. Do not select
secrets. This is coordinator attestation, not automated interpretation of logs.

To authorize a repair, use `tasks:main-hold repair` with the shared repository,
current main, attestation, and evidence fields, plus:

```json
{
  "hold_id": 1,
  "landing_id": 1,
  "package_hash": "<exact independently approved repair package>",
  "retained_holds": [
    {"id": 2, "evidence_hash": "<exact other incident evidence hash>"}
  ]
}
```

The authorization names only the incident it repairs. Every other currently
open incident must be explicitly acknowledged by ID/hash, ordered by ID. Those
incidents remain held; the authorization does not claim to repair or clear them.
This permits a reviewed repair while retaining separate relevant incidents,
without allowing unrelated work to merge.

Any new, changed, or unlisted incident scope blocks. Authorization must match the
current reviewed package and known native failures. If main/failures or the open
incident scope changes, an explicit fresh authorization can be appended for the
same package; previous evidence remains immutable. Rewording the same exact
authorization in place is refused. Neither a repair label nor a broad bypass flag
can authorize a merge.

## Case proof versus clearance

After the exact repair merge and native discovery lineage are recorded, run the
actual relevant cases on pinned main. Use the verified isolated main-check
procedure: fresh unfiltered Gateway tests, correctly scoped private output and
cache, and canonical `ORBIT_HOME=/tmp/orbit-gateway-testing` only inside the
verified non-root PrivateTmp sandbox. Verification must exercise the current
acceptance requirements; optional reporting is not a required gate. Never
silently change Orbit's acceptance requirements or claim an overall red command
was green.

Use `tasks:main-hold observe` to retain repaired-case observations even when the
whole verification is still red. It never clears an incident. Include the shared
fields, exact hold/landing/package, and:

```json
{
  "merge_sha": "<recorded exact repair merge>",
  "verification_exit_code": 1,
  "checks": [
    {
      "case": "exact case identity from the imported incident",
      "command": "exact command that actually exercised this outcome",
      "cwd": "/verified/private/main-checkout",
      "main_sha": "<same pinned authoritative main>",
      "executed": true,
      "selected": true,
      "cached": false,
      "exit_code": 0
    }
  ]
}
```

Provide exactly one result for every relevant case in that incident, including
non-test outcomes such as ordinary XML parsing when required. The retained
evidence file must substantiate the claims, exact commands, checkout, and main.
The boolean fields are explicit coordinator attestations, not proof inferred
from a test total. Missing, failed, cached, skipped, or zero-selected case results
are refused. Unrelated failed cases and the actual overall command exit code stay
visible in the observation and native status.

Use `tasks:main-hold clear` only for sufficient final proof: all incident cases
actually pass on current main containing the reviewed repair, overall verification
exits zero, and fresh native status reports no current correctness failure. Its
clearance is immutable. Observing repaired cases from a red run cannot clear an
overall Gateway hold. A prerequisite proof does not satisfy unverified acceptance
criteria. No automatic status or count-based clearance exists.

## Withdrawing an obsolete requirement

When a coordinator-imposed requirement is no longer relevant, use
`tasks:main-hold withdraw --file=FILE --exclusive`, inspect the preview, then add
`--apply`. This is not a repair or a successful test result. The active exclusive
coordinator must explain the decision in the bounded, hash-pinned evidence file.
Runtime must be enabled for writes, and current native main status must pass.

```json
{
  "repository": "/canonical/orbit",
  "main_sha": "<current authoritative main SHA>",
  "hold_id": 1,
  "hold_hash": "<exact imported evidence hash>",
  "attestation": "Why this requirement is obsolete and who authorized withdrawal.",
  "evidence_file": "/retained/withdrawal-decision.txt",
  "evidence_sha256": "<exact file SHA256>"
}
```

Only these fields are accepted. No landing, candidate, merge, or case proof is
required or accepted. Withdrawal stores `resolution: withdrawn` in the existing
immutable clearance record, preserving the original incident and every repair
authorization. It removes only that hold from the active scope; other holds and
native merge checks remain in force. An identical request is inert while its
main pin remains current. Changing a terminal resolution is refused. A scope
change still requires fresh authorization for any remaining repair hold.

## Rollout and remaining work

This slice adds only `task_closeout_operations` and `task_main_holds`; it does not
rewrite accepted TaskRuns or enable scheduled closeout. After independent review,
the coordinator must verify the exact allowlist/preimages, back up and identify
the intended Tasks database, apply the additive migrations in a drained window,
and restart the appropriate long-running processes. Never migrate from an
isolated test snapshot against live state. After audit records exist, rollback
means source/deployment rollback only, preserving both tables and their audit
history. Never run migration rollback or these migrations' `down()` methods
after use: they drop the tables. Use a forward fix that preserves the records.

The proof extension reuses this ledger without a new migration. Its explicit
stages cover primary-checkout reconciliation, native snapshot closeout,
postinstall review, both auxiliary topology releases, and auxiliary worktree/ref
removal. Session shutdown and original issue worktree cleanup remain separate
coordinator work. [Linear completion](tasks-completion.md) is a separate command.
Do not forge legacy Delivery or PhaseRun rows to reach these adapters.
