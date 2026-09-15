# Artifact-only task results

An Orbit replacement-proof child can produce only ignored `.loop/proof` files.
Do not invent a tracked change or an empty commit to accept that work. The child
keeps its original run, base SHA, implementation handoff and review tree. An
append-only `task_artifact_review_bindings` audit identifies its artifact result.
Ordinary coding tasks still require a separately instructed, reviewed commit.

## Bind before review

1. Pause and drain the exclusively owned Tasks queue. Let the assigned implementer
   submit its genuine handoff and stop. Verify its current native session identity
   and that no review instruction exists. Herdr idle is not a handoff.
2. Inspect the acknowledged implementation dispatch, run, newly created review
   round, effective manifest, clean unchanged base tree and exact proof files.
3. Preview with the current identifiers, then apply the returned binding hash:

```sh
php artisan tasks:bind-artifacts WORKSPACE --run=RUN --dispatch=IMPLEMENT_DISPATCH \
  --round=REVIEW_ROUND --manifest=EFFECTIVE_MANIFEST \
  --reason='The task produces only the reviewed proof package.' --exclusive --drained

php artisan tasks:bind-artifacts WORKSPACE --run=RUN --dispatch=IMPLEMENT_DISPATCH \
  --round=REVIEW_ROUND --manifest=EFFECTIVE_MANIFEST \
  --reason='The task produces only the reviewed proof package.' --exclusive --drained \
  --binding=PREVIEW_BINDING_HASH --apply
```

`--exclusive --drained` are operator attestations, not automatic session stopping.
The command does not dispatch, stop agents, change code, commit, or modify an
existing run/review/receipt. Apply requires identical preview inputs. Repeating an
already recorded request reports that original binding; it does not authorize a
new dispatch or assert that the current worktree still matches. Advancement and
submission validate the live package again. Conflicting requests fail closed.

The proof reader requires the issue plan to declare `snapshot_replacement: true`.
Plan `inputs` identify repository sources, not the native proof-file inventory.
The reader inventories all safe flat files beside the issue plan, including
`snapshot-reacquire.json`, without adding them to plan inputs. Native consumers
and the reviewer validate descriptor semantics. Repository files retain
Git-normalized `100644` or `100755`
modes; artifact binding still requires exact `0644` ignored proof-package files.
The general proof reader retains its existing non-executable-file check.
The audit retains the full file bytes and SHA256 values,
the exact ignored proof-file modes (`0644`), base/tree, manifest and original
handoff hash. It rejects redirects, unsafe paths, missing declared files, inventory
additions or removals after preview or binding,
nonignored proof files, changed tracked code, malformed/bounded text and private
dispatch tokens or raw prompts. This known-secret check is not a general secret
scanner; independent review must also check that fixtures contain no credentials.

4. Restore only the owned queue's prior running state, or advance once through the
   normal supported command. The retained reviewer receives the exact binding.
   A passing handoff records its independent verdict and accepts a null-commit
   result in one transaction. No commit instruction is created.

## Corrections and later work

A revise verdict returns to the same implementer/run. Its next genuine handoff
holds the workspace with `tasks:bind-artifacts` instructions. Drain and capture a
fresh round through the same preview/apply procedure. The old binding and verdict
remain unchanged. A correction cannot slip back into ordinary commit acceptance.

The next child uses the last real accepted commit as its base. Proof and landing
exports retain every child and artifact binding in order; an artifact result does
not advance the Git tip. Final continuation and current-main integration also
support an artifact tail. A separately assigned and independently accepted later
artifact correction advances the artifact package, while preserving earlier
packages as history. Final publication compares the actual full package with the
last accepted artifact result. Final review also receives the accepted bindings.
Changing tracked fixtures declared by that package requires another reviewed
artifact binding; an unrelated later code task does not silently approve it.

## Cutover and limits

Deploy only at the drained pre-review boundary. Back up the exact database and
source, apply the one additive migration, and verify old rows are unchanged before
capture. Do not rewrite task state or null the active base. New code tolerates the
table being absent for existing ordinary coding flows, but artifact activation
requires the migration. After any artifact binding is recorded, keep the new
runtime semantics: old code does not understand null-commit children. The migration
refuses to remove a nonempty audit table. Restore/forward-fix only through a
separately reviewed recovery plan, with the owned queue held.

This slice supports original Orbit replacement-proof runs only. Reattempts,
recovered workspaces, and making a main-integration task itself artifact-only fail
closed. A normal real-commit main integration after an artifact child is supported.
There is no generic file-result platform, UI, automatic mode inference, automatic
operator attestation or native topology action here. Source fixtures and mocks
verify the lifecycle; they are not evidence that a live Orbit proof passed.
