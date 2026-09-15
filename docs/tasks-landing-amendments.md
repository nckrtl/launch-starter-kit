# Body-only landing amendments

Use this only when the coordinator has resolved a rejected supplemental review
through corrected PR wording. This is not source revision, transport recovery,
automatic retry, or permission to publish, merge or clean up.

The command accepts the exact original landing ID and a canonical JSON request
outside its feature checkout:

```json
{
  "package_hash": "<original package SHA256>",
  "review_hash": "<TaskLandingData::hash(original review_result)>",
  "reason": "The precise coordinator resolution of the rejected wording.",
  "pull_request_body": "The complete corrected proposed body, without generated identity header or frozen-input footer.",
  "evidence": {
    "path": "/absolute/canonical/path/outside-checkout/correction.json",
    "sha256": "<SHA256 of the complete correction file>"
  }
}
```

The reason is limited to 4,000 bytes, the proposed body to 50,000 bytes, and the
one complete UTF-8 correction file to 65,536 bytes. No truncation is performed.
Do not include handoff tokens, supplemental review credentials or raw prompts.
The independent reviewer decides whether the corrected wording satisfies the
accepted scope; the command does not infer the meaning of prose.

Preview reads the exact candidate, artifact, Builder receipt, current issue
contract, accepted task evidence, ownership and retained yielded reviewer. It
does not write state, publish, run checks or send a prompt:

```sh
php artisan tasks:landing-amend <original-id> --file=/absolute/request.json --exclusive
```

Read the complete proposed package and audit. Applying requires the exact
returned proposal hash and enabled Tasks runtime:

```sh
php artisan tasks:landing-amend <original-id> --file=/absolute/request.json --proposal=<exact-hash> --exclusive --apply
```

Apply records an immutable successor landing and separate amendment audit in
one workspace-locked transaction. It changes only request.pull_request_body and
the resulting package.body. The canonical renderer retains the title, identity
header and frozen-input footer. The candidate, artifact, input bytes and gate
are reused, not regenerated or republished. The correction file is retained in
full in the audit, outside the original artifact's evidence set.

The original landing row, assignment, encrypted credentials, package and
rejected verdict stay unchanged. No version columns are added to existing
landing serialization. The audit records both IDs, the exact intent, hashes and
complete correction evidence. There is at most one amendment per workspace;
chains, unproven extra rows and originals with any downstream operation refuse.
Exact replay returns the existing successor without external effects, even if
the original correction path is later absent. Conflicting replay refuses.

Normal landing preparation selects the validated original; it never selects
the latest row. Review, closeout and completion use an explicit landing ID and
package hash and validate amendment history. Approval never transfers from
the predecessor or to another package.

Review is a separate explicit operation, using the successor ID and package hash:

```sh
php artisan tasks:landing-review <successor-id> --package=<successor-package-hash> --exclusive
php artisan tasks:landing-review <successor-id> --package=<successor-package-hash> --exclusive --apply
```

The existing review command creates a fresh assignment/token for the retained
yielded reviewer. Its full prompt includes the corrected complete package and
complete correction evidence, plus references to the unchanged artifact and
original rejected review. The existing transport budget may refuse a large
prompt before assignment. Never truncate evidence or automatically resend.
Only a genuine successor pass can proceed through explicit closeout and
completion with that successor ID/hash. Main holds, ownership, publication,
approval, merge, verification and completion checks remain unchanged.

The new migration removes workspace-only landing uniqueness and adds a separate
audit table. It is reversible before amendments exist and preserves all v1
columns and row projections. Rollback refuses populated amendment history or
unproven duplicate landing rows; use a reviewed forward migration instead.

This backend/CLI capability has no browser UI. Its focused Pest coverage is in
TaskLandingAmendmentTest, with real receipt-validation coverage in TaskLandingTest.
