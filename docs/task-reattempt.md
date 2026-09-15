# Reattempt the first blocked implementation

This opt-in coordinator operation supports one case: the first child is on its
first implementation instruction, at review round zero, with one active TaskRun
and an acknowledged `blocked` receipt. There must be no review, accepted child,
final check, uncertain dispatch, or other run. One reattempt is allowed per
workspace. Other failures remain held for separate inspection.

Use it only after separately owned prerequisite work was independently reviewed,
merged, and successfully verified on main. It does not expand the task or waive
acceptance. An automated-only task remains automated-only; its own safety tests
and exact candidate quality gate remain required.

The operation never merges, rebases, resets, switches branches, reapplies source
patches, or resolves conflicts. It does not change task content, dependencies,
admitted configuration, or the workspace's original base. It is not final
continuation, recovery, automatic retry, or agent replacement.

## Establish authority and evidence

Inspect the current ledger, registered worktree, branch, and retained agent.
Confirm that every possible writer has yielded and that no other controller owns
the issue or worktree. Exclusive ownership is a coordinator assertion, not an
operating-system write fence.

Pin the prerequisite issue, authoritative PR URL, reviewed candidate, merge, and
verified main SHA. Retain the independent review and successful main verification
in accessible, bounded, canonical files outside the worktree. Bind them by SHA-256.
Verification must name its exact command, directory, main SHA, and exit code.

The application checks file hashes, Git ancestry, the merge's exact second parent
and conflict-free tree, and authoritative `origin/main` at checkpoint and cutover.
It does not query the PR provider, rerun tests, or decide whether the evidence is
truthful or sufficient. The coordinator must establish those facts before apply.

There are two explicit integration routes. Without an `integration` field, the
original admitted base must be an ancestor of verified main, and cutover HEAD must
equal that exact main commit. Existing requests and audits retain this meaning.
For an admitted base with feature-only commits, add
`"integration": "preserve_history"` to the checkpoint request. This permits only
the history-preserving merge described below; it is not an ancestry exemption.
Unknown strategies and null values are refused. No new migration is required.

## Preserve the blocked work

Save a request outside the worktree, replacing every placeholder with observed
evidence. The command array below is illustrative, not a prescribed main gate.

```json
{
  "reason": "Why the completed prerequisite justifies a reattempt.",
  "evidence": "Original failure and exact prerequisite resolution references.",
  "prerequisite": {
    "source": "ORB-PREREQUISITE",
    "pull_request": "https://github.com/OWNER/REPOSITORY/pull/NUMBER",
    "candidate": "REVIEWED_CANDIDATE_SHA",
    "merge": "VERIFIED_MERGE_SHA",
    "main": "VERIFIED_MAIN_SHA",
    "review": {"path": "/private/review.json", "sha256": "FILE_SHA256"},
    "verification": {
      "head": "VERIFIED_MAIN_SHA",
      "command": ["composer", "test:affected"],
      "working_directory": "/exact/verified/project",
      "exit_code": 0,
      "log": {"path": "/private/main-check.log", "sha256": "FILE_SHA256"}
    }
  }
}
```

Preview is the default and works while runtime execution is disabled:

```bash
php artisan tasks:prepare-reattempt WORKSPACE_ID \
  --dispatch=BLOCKED_DISPATCH_ID --head=ORIGINAL_RUN_BASE \
  --manifest=APPROVED_MANIFEST_HASH --exclusive \
  --file=/private/prerequisite.json
```

Preview uses temporary indexes and object directories. It writes no persistent
objects, refs, index, source, database, dispatch, queue, or agent state. It returns
`state_hash` and the observed HEAD, branch, working tree, staged tree, and index
hash. Add `--state=STATE_HASH --apply` to retain that exact state in an immutable
checkpoint. Applying requires runtime execution enabled.

The working tree includes staged, unstaged, nonignored untracked files, deletions,
modes, symlinks, and explicitly staged ignored files. The staged tree is retained
separately. Ignored unstaged dependencies and private configuration are not backed
up; inspect and preserve them separately before source operations. Unsupported
Git states, embedded repositories, external clean filters, and external merge
drivers fail closed. Partial/promisor repositories are rejected before object
lookup; Git inspection also disables lazy fetching. Materialize needed objects
separately under explicit authority. The intentional main-ref readback remains
read-only and never fetches objects. An attached branch is required.

This checkpoint is a new observation, not proof that work stayed unchanged after
the original blocked receipt, which has no captured snapshot. Review and authorize
the currently observed task edits. Retained trees are reachable at
`refs/commander/task-reattempts/REQUEST_HASH/tree` and `/index_tree`. Both refs are
published in one create-only transaction. Existing partial, rebound, or symbolic
refs fail closed; they are never repaired silently. Both exact direct refs and
tree objects are rechecked before cutover and prompting, including the final
local checks under the runtime lock. Lost retention leaves execution held. A database
failure after capture may leave unused retained refs. It does not release the
hold or authorize integration. There is no automatic pruning.

## Integrate separately, then cut over

After checkpoint application, keep the existing worker parked. One authorized
owner preserves the task edits, advances the same branch to the exact verified
upstream commit, and restores the task's working and staged changes. Keep this
operation recoverable and retain its log. Do not import the prerequisite as a
no-commit overlay or hide it in the feature's accepted task commit.

With `integration: preserve_history`, the coordinator instead makes one normal
merge on that same branch, after retaining the checkpoint and safely setting its
dirty work aside. The merge must have exactly two parents in this order:

1. The checkpoint's original HEAD, including all previously authored commits.
2. The exact independently reviewed, merged, and verified prerequisite main SHA.

The merge tree must equal Git's conflict-free merge of those parents. No extra
source changes, squashing, rebasing, cherry-picking, substituted parents, or
manual conflict resolution are admitted. Checkpoint preview already verifies
that these histories merge without conflicts, using disposable objects only.
Then restore the checkpoint's staged and working changes separately. The
existing restoration log must describe these operations. The cutover preview's
HEAD and state hash pin the actual integration commit; the immutable checkpoint
request and cutover observation retain its strategy and identity.

The application independently applies both retained base-to-tree patches to the
new base in temporary indexes and requires the observed trees to match exactly.
For `preserve_history`, that new base is the validated integration commit, not
main. Previously committed feature content remains in its ancestry and tree;
only uncommitted task deltas are replayed from the checkpoint.
There is no three-way fallback or conflict resolver. Conflicts, extra changes,
wrong branches, missing evidence, or changed upstream pins leave the original
attempt held. Do not edit the ledger or audits to bypass a refusal.

Save the restoration request outside the worktree:

```json
{
  "reason": "Why the preserved task can resume on verified upstream.",
  "evidence": "Exact source integration and restoration observations.",
  "log": {"path": "/private/restoration.log", "sha256": "FILE_SHA256"}
}
```

```bash
php artisan tasks:reattempt CHECKPOINT_ID --exclusive \
  --file=/private/restoration.json
```

Inspect the no-write preview. Add its `--state=STATE_HASH --apply` to cut over.
The transaction ends the old incomplete run with an explicit audited failure
reason, creates attempt two on a new fixed base, retains worker/reviewer
references, records an immutable audit, and prepares one fresh-token instruction
for the exact retained worker session. The original blocked dispatch, receipt,
run inputs, and base remain history. Only the matching hold is cleared.
`TaskWorkspace.base_sha` remains the original admission base.

Add `--advance` only with `--apply` to queue advancement. Otherwise inspect then
use `tasks:advance`. An identical applied request returns its audit without another
enqueue; a conflicting request fails. Failed enqueue leaves the audit and prepared
dispatch intact; normal advancement can repair it.

Sending checks ledger ownership, configuration, manifest, restored Git state,
evidence files, and retained session again. Missing/replaced sessions never start
a new worker. Changed or uncertain dispatches stay held without blind resending.
Old receipts and callbacks cannot acquire the new dispatch. Ordinary review,
one-commit acceptance, and final verification then use the new run base.
The history-preserving route also rereads authoritative main before sending and
rechecks the integration parents, merge tree, and exact restored trees under the
runtime lock. If main advances before sending, execution remains held for
inspection; it does not choose a new base or reprompt automatically.

## Rollout and downstream validation

Install the additive migration through reviewed deployment only. Back up the
database, drain active execution, and keep CLI/worker releases consistent. Do not
reinterpret old records or manually backfill audits. The migration's `down()` is
for disposable tests; after use, rollback discards base-transition evidence, so
use a reviewed forward fix.

`tasks:inspect` exposes both immutable records without handoff tokens. Downstream
delivery consumers must validate the exact checkpoint/new-run/base provenance
before accepting a first run with a base different from workspace admission.
Until that support is reviewed and integrated, such landing attempts must stay
fail-closed. Never replace base equality with an unconditional exception.

The landing validator checks the same history-preserving integration parents and
merge tree, replays the retained dirty/staged deltas, and starts the accepted
one-parent task-commit chain at that exact integration commit. It does not use a
later main as a substitute. Later upstream advancement is allowed at landing;
the pinned prerequisite must still be proven in the original recorded graph.

This CLI/backend feature has no user-visible browser workflow. Its tests use
isolated databases, disposable repositories, and fake agents. No browser surface
or new machine gate is added.
