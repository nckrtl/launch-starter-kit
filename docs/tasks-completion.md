# Tasks delivery completion

`tasks:complete` records delivery of one independently approved Orbit Tasks
package after the existing closeout stages have published, approved, merged,
verified, and released its reservation. It does not run those stages.

```sh
php artisan tasks:complete LANDING_ID --package=PACKAGE_SHA256 --exclusive
php artisan tasks:complete LANDING_ID --package=PACKAGE_SHA256 --exclusive --apply
```

The default is a read-only preview. Applying also requires the existing Tasks
runtime enable flag and exclusive coordinator ownership. The command uses the
existing closeout coordinator lock and operation ledger. It does not introduce
a migration, scheduler, automatic transition, workflow builder, or legacy
Delivery/PhaseRun record.

## Meaning of completion

A root task's existing completed status means its implementation was accepted.
This command does not rewrite that status, task/run history, the landing package,
or its independent review. A separate `delivery-complete` operation records that
the reviewed package reached authoritative main, passed the required delivery
proofs, and has an exact Linear Done observation.

Successful output includes the historical delivered record and a fresh current
observation. Delivery completion does not mean operational cleanup is complete.
The completion command itself does not reconcile the primary checkout, run
maintenance, shut down agents, or remove resources. Those operations remain
separate. For proof, required native closeout and ORB-91's reviewed postinstall
auxiliary cleanup must already be recorded. The original issue worktree and
agent sessions still need coordinator cleanup; a delivery receipt does not
claim that all operational resources are gone.

## Linear transition and ownership

The completion-specific reader uses the existing authenticated Orbit RPC and
snapshot contract validation without the legacy active-only/delegated checks.
It verifies the issue UUID/key, Orbit team, authenticated viewer, complete
collections, approved requirements, exact own-PR attachment allowance, and the
absence of readiness, child, unresolved blocker, or foreign-controller holds.

Eligible states are In Progress or In Review with the started type, or the
team's unique exact Done state with the completed type. The issue must remain
undelegated and either unassigned or assigned to the configured Nick user.

The only external mutation is one `issueUpdate` containing `stateId`. It does
not change `assigneeId` or `delegateId`, temporarily delegate to Tom, or clear
Nick's assignment. This supersedes the owner-clearing suggestion in the initial
design: Tasks has no Tom delegation to release, and GitHub/Linear integration
may already move the issue to Done during merging.

The observed permitted assignee and null delegate are pinned in the durable
Linear intent and completion receipt. Even a change between the two permitted
assignees is drift once that intent exists. Exact already-Done issues with
unchanged permitted ownership can be adopted without a mutation, but only after
all delivery and applicable repair-proof gates pass.

## Evidence required

Completion validates the retained exact publication, independent-review approval
marker, merge, native verification lineage for the admitted flow, and reservation release.
It rereads current PR/merge state, checks the retained artifact ref in the
canonical primary repository and origin, and checks that current authoritative
main contains the reviewed merge. These observations do not fetch, push, merge,
repair, or remove anything.

The former proof-package closeout and ORB-91 postinstall acquisition requirements
are withdrawn by owner direction on 2026-09-14. Do not run proof topologies,
construct replacements, or perform separate reacquisition. Legacy handler checks
and records may still reflect that old admission; they are not instructions to
satisfy it. Do not fabricate a pass or silently treat the corrected task brief as
approved by a historical review. The controller remains stopped.

These checks read retained archive, ledger and private review evidence. They do
not require the original or auxiliary checkout after valid completion, or require
the delivered generation to remain the currently promoted generation. Preserve
the retained evidence files through cleanup. See
[Orbit proof delivery](tasks-orbit-proof.md).

Reservation status must be a complete valid idle or foreign-owned observation.
A reservation belonging to this issue/PR, inconsistent partial ownership, and
reader failures stop completion. Another feature's reservation is not released
or treated as an error merely because it exists.

Main incident records remain intact. If this package is an authorized repair,
each applicable incident requires retained actual relevant-case evidence:
selected and executed, noncached, zero-exit cases at a main that includes this
merge, with an overall verification exit code of zero. An overall-red command
does not establish repair acceptance even if selected cases passed. A retained
proof at an earlier main can remain valid when that main is an ancestor of
current main. Open incidents and current native failures remain visible; this
command never clears a main incident or claims unrelated failures were fixed.

## Admission and interrupted writes

Before the first intent, completion runs the existing full approved-package
guard, including real local Git provenance for an audited reattempt. It retains
a `completion-admission` operation binding the immutable landing and accepted
database evidence, plus the acceptance guard source hash. The full guard runs
again immediately before a first mutation. Missing admission is not synthesized
after an intended or uncertain write.

After intent, reconciliation validates that retained admission and accepted
evidence without replaying worktree Git proof. An already delivered feature can
therefore still be inspected after legitimate worktree removal or agent shutdown.
For reattempts, the accepted-evidence validation also checks the retained
prerequisite review, main verification, and restoration files outside the
worktree. Those files must remain available and unchanged; this is not a claim
that every terminal check is database-only. Fresh artifact, PR, merge, main,
reservation, issue, and applicable repair observations remain mandatory.

The `linear-completion` operation records intent before the RPC and writes its
exact read-back result afterward. A failed or lost response does not authorize
another mutation. An uncertain operation can only be reconciled from matching
current state. A failure before intent remains retryable. A failure while saving
the final local delivered record can be retried without repeating Linear work.
Terminal receipts omit volatile issue timestamps; current observations retain
them. Repeating a completed delivery never rewrites its historical result.

Linear does not provide a compare-and-swap contract here. The coordinator lock,
exact preflight, preserved ownership, and read-back checks reduce races but do
not fence unrelated external writers. If ownership, requirements, or other
required facts drift, stop and reconcile rather than retrying the mutation or
claiming exactly-once behavior.

## Validation boundary

The focused completion suites use in-memory SQLite, fake external services,
and disposable local Git repositories. They cover state-only mutation, exact
already-Done adoption, owner drift, lost-response reconciliation, retained-proof
drift, main repair acceptance, foreign reservations, and a real audited
reattempt before and after worktree removal. No test operates a live agent,
issue, PR, node, or repository. This slice is backend/CLI-only, with no UI or
endpoint changes; browser validation is not applicable.
