# Revise one existing Orbit publication

`tasks:revise-publication` bridges one owned legacy PR to a newly and independently
approved Tasks package. It does not import accepted work, alter task/run/review
history, change Linear, dismiss reviews, create a PR, approve, merge, release
resources, update primary, or resume a legacy controller. Ordinary closeout
publication remains create-only.

Use this after reconciling the legacy owner and its exact agents, completing
genuine Tasks work, and obtaining supplemental approval for the exact new
candidate, artifact and PR body. The coordinator must resolve the retained review
findings in that reviewed package. Its rationale is an attestation, not automated
proof that the findings were fixed.

The existing branch must be an ancestor of the new candidate. Integrate upstream
before task admission when needed; do not rebase accepted commits or relax the
single-parent TaskRun contract. Preserve the legacy candidate, artifacts and
evidence. Keep the PR title unchanged, including its existing Linear attachment.

## Request and command

Store bounded UTF-8 JSON outside the feature worktree. Retain exact current PR
identity and bytes, plus its complete review list ordered by numeric ID. Include
all submitted reviews. Hash exact body strings without changing final newlines.

```json
{
  "number": 298,
  "url": "https://github.com/nckrtl/orbit/pull/298",
  "repository": "nckrtl/orbit",
  "author_login": "nckrtl",
  "author_type": "User",
  "head_ref": "orb-200",
  "base_ref": "main",
  "head_sha": "<exact old 40-character remote head>",
  "title": "ORB-200: Report production release and dedicated runtime drift",
  "title_sha256": "<SHA256 of the exact existing title>",
  "body": "<complete existing PR body>",
  "body_sha256": "<SHA256 of the exact existing body>",
  "reviews": [
    {
      "id": 5186131384,
      "commit_id": "<reviewed 40-character commit>",
      "state": "CHANGES_REQUESTED",
      "author_login": "tom-nckrtl[bot]",
      "author_type": "Bot",
      "body_sha256": "<SHA256 of the exact existing review body>"
    }
  ],
  "reason": "Ownership handoff, retained findings, and how the new reviewed package resolves them."
}
```

The example review is illustrative: supply the complete actual list. Fewer than
100 reviews are supported. Incomplete pagination, unknown fields, duplicate or
unordered IDs, and invalid identities are refused. The old review body hashes
and identities remain pinned; the GitHub review records are not edited.

```sh
php artisan tasks:revise-publication LANDING \
  --package=APPROVED_PACKAGE_SHA256 --file=/private/revision.json --exclusive
```

The default preview is read-only: no database/cache lock, ledger/ref write,
fetch, or external mutation. Repeat with `--apply` after inspecting the pins.
Apply requires enabled Tasks and the existing coordinator file lock.
`--exclusive` asserts sole external ownership; this local lock does not fence
another Commander installation or a human on GitHub.

The first application requires the exact old branch and PR preimage. An already
changed effect without this bridge's retained intent is refused, even if it
matches the new package. Existing ordinary closeout records also refuse revision;
do not reset or delete them to make it apply.

## Two retained writes

1. `revision-branch` checks real local ancestry and advances only the pinned
   remote branch with `--force-with-lease=refs/heads/orb-N:OLD_SHA`. The ancestry
   guard permits only a fast-forward; the lease refuses intervening changes.
   A missing ref does not authorize recreation.
2. `revision-publication` rechecks the advanced head, exact old title/body, PR
   identity and reviews immediately before PATCH. It writes only the approved
   package's unchanged title and new body and requires exact readback.

GitHub PR PATCH has no server-side compare-and-swap. There is a race window
between preflight and write. This bridge relies on exclusive ownership and
guarded preflight/readback; it does not claim atomic PR updates, distributed
fencing or exactly-once delivery.

Each write uses the existing ledger with separate immutable inputs, intent,
preflight and result. Lost responses reconcile only from exact current state.
An unresolved intended write is never resent. Repeating the same request may
finish a later step after reconciling the earlier one. Changed requests/effects,
inconsistent observations and missing authority remain held. Completed results
still require fresh package, issue, ancestry, PR and review checks.

## Approval and next steps

After both revision operations complete, preview ordinary closeout `publish`.
It adopts the exact branch/PR and publishes the retained package approval through
`tom-nckrtl[bot]`. Only this completed, package-bound revision permits that bot's
exact prior changes request to be superseded. It remains retained, not dismissed.
Another reviewer's unresolved changes request always holds the action.

The old review snapshot must remain unchanged. Only the one exact new package
approval may be added. New or changed reviews require explicit reconciliation.
Dismissed, duplicate, wrong-head or stale package approvals cannot authorize
merging. The bot's newest substantive verdict must be the exact new approval.
All ordinary mergeability, ownership, incident and reservation gates remain.

Revision precedes ordinary closeout. Once ordinary operations exist, inspect and
continue them instead of reapplying revision. Continue unchanged `publish`,
`merge`, `verify`, `release`, and `complete`. This bridge performs no deployment,
migration or worker restart.

## Validation

Focused tests use in-memory SQLite, fake SSH/Git process responses, and disposable
local Git repositories for real lease races. They have no live database, GitHub,
Linear, agent or node effects. This is CLI/backend-only; browser validation does
not apply.
