# Completed Tasks landing bridge

This opt-in bridge prepares an Orbit candidate package and asks the retained
reviewer for supplemental, exact-package approval. It does not reopen the root,
reinterpret the original final pass, create a PR, merge, release reservations,
change Linear state, or clean up. Historical discovery packages and prompts keep
their existing contract. Explicit proof admission uses the separate
[native proof delivery path](tasks-orbit-proof.md).

## Prepare

Prefix machine-readable Tasks commands with `PAO_DISABLE=1`, for example
`PAO_DISABLE=1 php artisan tasks:landing-prepare ...`. Laravel Pao otherwise
compresses whitespace and ellipses inside printed JSON strings when it detects
an agent. The supported opt-out preserves exact evidence bytes; `--no-ansi`
does not disable Pao. This affects console output, not stored or published inputs.

Inspect ownership, the successful final dispatch, ordered accepted commits,
actual repository Builder receipt, and Linear identity first. Supply a JSON file
outside the worktree:

```json
{
  "candidate": "<exact last accepted 40-character commit>",
  "manifest": "<effective 64-character task manifest hash>",
  "final_dispatch": 5,
  "issue_id": "<Linear issue UUID>",
  "gate_receipt": "/canonical/repository/.git/orbit-checks/<candidate>/<run>/result.json",
  "pull_request_body": "<Substantive Review handoff: acceptance outcome rows with observed results and precise retained evidence; actual focused/project checks; documentation changes or a no-docs reason; deviations and limits; Incus/discovery resource state>",
  "evidence_files": [
    {"name": "incus-proof.log", "path": "/canonical/retained/proof.log", "sha256": "<exact file SHA256>"}
  ]
}
```

Preview with `tasks:landing-prepare <workspace> --file=<file> --exclusive`.
Then add `--proposal=<preview proposal_hash> --apply` to freeze the exact proposal and
publish through Orbit's native `bin/loop-artifacts`. Applying requires the Tasks
runtime to be enabled. Preview uses read-only Git, Linear, and filesystem checks.

For discovery, the `.loop` allowlist is `flow.json`, generated
`commander-tasks.json`, and the native publisher's excluded `runtime/` directory.
Proof also permits the exact issue plan and explicitly declared, flat proof
fixtures. These must already match the immutable pre-proof artifact; landing
does not republish it with later results. Unexpected files are refused.
Inspect and explicitly reconcile old scaffolds; the bridge never deletes them.
Existing exports or artifacts must match exactly; no overwrite, changed-candidate
workaround, or speculative publication retry is permitted.

Publication intent is durable before any file or Git mutation. On a lost response,
the exact published artifact can confirm success. Repeating the same applied
request after an uncertain attempt only reconciles; it does not publish again.
If no artifact can be confirmed, retain the intent for explicit investigation.

The export contains task briefs/order, accepted commits and review evidence,
the current Linear title/description, label names, safe attachment metadata and
contract hash, original final evidence,
validated Builder receipt, and explicitly selected
evidence files. It is an immutable snapshot, not another task authority. Private
dispatch tokens, raw prompts, and runtime configuration are not exported.
Evidence attachments must be bounded regular UTF-8 files with pinned hashes.
Known dispatch tokens/raw prompts in exported text cause refusal. Do not select
credentials or other private files. Unembedded paths in prose are references, not
archived proof. The reviewer must reject missing required proof.

The proposed PR body is required and frozen in the proposal hash before publication.
Supply actual implementation outcomes, not copied preparation criteria or a test
count. The bridge adds only canonical issue/candidate/artifact/gate bindings; it
does not invent acceptance rows. Supplemental review judges the exact complete
body together with the frozen handoffs and retained proof. Artifact `input_hash`
excludes PR wording; the proposal hash binds both artifact inputs and proposed
body, and the final package hash binds candidate, artifact and complete PR body.

Legacy task admission did not record the original Linear contract. The reviewer
must compare the exported current requirements/classifications with accepted root/tasks and block
new, changed, removed or unresolved requirements for coordinator reconciliation.
A fresh contract hash and the old final pass alone do not prove issue coverage.
Attachment titles are included; only canonical `nckrtl/orbit` GitHub issue, PR and
commit URLs are exported. Other attachment URLs, signed credentials and arbitrary
external links are omitted. A missing exported URL is not proof of coverage;
required attachment evidence must be retained explicitly or the review must block.

## Supplemental review

Preview with `tasks:landing-review <landing> --package=<package_hash> --exclusive`.
Add `--apply` only after inspecting the complete frozen package, including its PR
title/body. The exact retained reviewer must have yielded and its Herdr workspace
identity must still match. No replacement agent is started. Existing native
conversation identity is retained when available; none is synthesized.

The new assignment has its own encrypted token/prompt and binds submission to the
landing, assignment, session, candidate, artifact and complete package hash.
The reviewer receives `tasks:landing-submit <landing> --file=<outside-file>` and
must invoke it from the exact feature worktree. Create new handoff files privately
from the start: use a fresh `mktemp -d` directory outside the checkout (`0700`) and
an empty JSON file created as `0600` under `umask 077`. Verify both modes before
writing the token, not with `chmod` afterward. Quote the generated absolute path
in `--file`. Existing assignments and immutable receipts stay unchanged; keep the
same private handoff on an uncertain submission, as instructed by its prompt.
Identical verdict replay is inert;
stale/conflicting submissions are refused. Uncertain prompt delivery never causes
an automatic resend. A pass records supplemental approval only.

Schema-2 proof review prompts reference complete private JSON evidence files,
each with an exact hash and byte size. Commander retains and verifies their bytes before
sending or accepting a submission. This avoids Herdr's message-size limit without
truncating evidence or changing the immutable artifact. Keep these retained
files; they are part of review verification, not disposable prompt scratch space.

## Rollout and remaining boundary

Add the new table with an additive migration on a backed-up, correctly identified
database, then restart relevant long-running Commander processes in a drained
window. No existing rows need rewriting. This slice introduces no scheduled work
or default flow change. Do not run migrations from a test snapshot against live
state. If rollback is needed after use, retain the ledger and forward-fix; do not
drop its audit history.

Tasks reads Linear through the shared policy-neutral `OrbitIssueReader`, then
applies its own eligibility rules. The issue must be in the exact Orbit team,
active (`In Progress` or `In Review`), undelegated, unassigned or assigned to the
configured Nick, and a ready leaf with complete collections and no unfinished
blockers. Foreign `controller:*` labels and `maintenance:monorepo` are refused;
an optional `controller:tasks` label is accepted but not required. Commander DB
ownership checks still exclude a competing Delivery or Tasks workspace. Do not
delegate a Tasks issue to Tom just to satisfy a legacy adapter. Legacy issue
providers retain their existing Tom eligibility rules. This reader correction
requires no migration or repeat review of unchanged Orbit code.

PR publication, exact independent GitHub review, protected merge intent/read-back,
native verification and reservation release use the separate
[closeout commands](tasks-closeout.md). Proof adds its explicit installation,
reacquisition and auxiliary cleanup stages. Neither completed tasks nor this
package's approval bypasses a main correctness hold.

A genuinely rejected supplemental review can receive one explicit
[audited body-only amendment](tasks-landing-amendments.md). The original package,
assignment and verdict remain unchanged. A successor uses the same validated
candidate, artifact and gate, and needs its own independent package review.
Never manufacture a source commit or rerun an unchanged successful gate to fix
PR wording.

The initial accepted commit chain must still begin at the admitted workspace
base. Packaging after an upstream-based first-child reattempt requires the
separate verified reattempt audit contract; this bridge refuses it until that
provenance is supported. Do not rewrite admission or accepted-run history.
