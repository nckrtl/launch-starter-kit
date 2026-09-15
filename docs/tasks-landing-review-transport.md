# Supplemental review transport

New supplemental assignments retain the complete package, PR title/body, role
instructions and private receipt. Frozen inputs are referenced by the existing
artifact ref, artifact commit, sole candidate parent, `.loop/commander-tasks.json`,
raw byte count and SHA256. The reviewer must verify the complete raw blob first,
then inspect every section and attachment in bounded reads, retain a coverage
inventory, and include both failed and successful checks. No evidence is removed,
re-encoded or republished. Existing assignment prompts remain immutable.

The application budget is **65,536 bytes** for the serialized JSON request plus
newline, including reserve for the largest PHP request ID. This is a conservative
application ceiling, not a claim about Herdr's unconfirmed exact server limit.
The diagnosed multi-megabyte request was 2,778,722 bytes; its reference rendering
was approximately 12 KiB. The budget leaves room for that rendering while
rejecting unexpectedly large packages. It cannot guarantee server acceptance;
any delivery uncertainty still fails closed. No live transport boundary probing
is part of this change.

`SocketClient::requestLine()` is the shared encoder. The supplemental transport
fingerprint uses request ID `2`: a fresh client performs `agent.get` as ID `1`
before the prompt. Ordinary dispatch preserves its existing not-ready policy;
the budget also reserves ID growth. Recovery uses that exact two-request sequence
and `promptAgentOnce()`, with no not-ready retry. Adapter tests compare the actual
fake-server request with the preflighted bytes, including JSON escaping and the
newline.

## Explicit recovery

`tasks:landing-review-recover` is a one-shot operation for a known oversized
supplemental request refusal. It does not create a new review assignment. Use it
only after the coordinator has correlated a retained server
`request line is too large` diagnostic with that exact original attempt. A
timeout, connection close, or missing recent output is insufficient evidence.
The server diagnostic does not include the assignment ID, so that correlation
remains an explicit operator responsibility; byte/hash and time verification
cannot establish it alone.

Keep an exact copy of the single raw server diagnostic line outside the worktree.
The file must be canonical, regular, readable UTF-8, no more than 4096 bytes, and
preserve its original bytes, timestamp and SHA256. The timestamp must be within
two seconds of the unchanged failed landing's `updated_at`. Keep the surrounding
diagnostic report separately to support the operator's correlation. Do not use a
rewritten summary as the raw evidence file.

The JSON request outside the worktree has only these fields:

```json
{
  "assignment": "original assignment UUID",
  "package_hash": "original package SHA256",
  "candidate_sha": "original candidate commit",
  "artifact_sha": "original artifact commit",
  "input_hash": "original frozen blob SHA256",
  "original_prompt_sha256": "SHA256 of the saved decrypted original prompt",
  "original_wire_sha256": "SHA256 of original JSON request plus newline",
  "original_wire_bytes": 2778722,
  "session": {
    "workspaceId": "retained workspace",
    "tabId": "retained tab",
    "paneId": "retained pane",
    "terminalId": "retained terminal",
    "agentName": "retained reviewer",
    "workingDirectory": "/absolute/assigned/worktree",
    "agentId": null
  },
  "refusal": {
    "kind": "request_line_too_large",
    "path": "/absolute/outside-worktree/original-diagnostic.log",
    "sha256": "SHA256 of exact diagnostic file bytes",
    "occurred_at": "2026-09-13T02:16:08.326119Z"
  }
}
```

Values above illustrate the format and are not an authorized recovery request.
No token or arbitrary prompt belongs in this file. Internally, the application
uses the original secret token and renders the reference prompt from the frozen
package. Keep diagnostic and request files private. Do not print decrypted
prompts or tokens to prepare the hashes.

```sh
php artisan tasks:landing-review-recover LANDING --file=/absolute/recovery.json --exclusive
php artisan tasks:landing-review-recover LANDING --file=/absolute/recovery.json --exclusive --request=EXACT_PREVIEW_REQUEST_HASH --apply
```

Preview reads and verifies the current package, original prompt, artifact,
evidence, ownership and yielded reviewer, then returns the exact request hash and
transport metadata. It creates no recovery record and sends nothing. Apply also
requires the Tasks runtime enabled. It revalidates those guards and records one
unique intent under the existing workspace lock. Its own database transaction
must commit before sending; an enclosing transaction is refused. External reads
and the send occur outside that database transaction.

The unique recovery row retains immutable input pins, the original landing hash,
the raw refusal evidence, observed session, encrypted reference prompt, prompt
and wire hashes, and sizes. It changes only once from `intended` to `sent` or
`unknown`. A crash after claiming leaves `intended`, which also forbids resend.
Repeating the exact pinned request returns the existing observation, including
after a verdict. Conflicting requests fail. Evidence or session drift after the
claim consumes the attempt without sending. No scheduler or generic retry engine
is added.

The original `TaskLanding` assignment, prompt, token, package, error and history
remain unchanged by recovery. Transport acknowledgement does not approve the
package: the landing stays `review_unknown`. Only the retained reviewer's real
receipt through `tasks:landing-submit` can produce `approved` for `pass` or
`rejected` for `revise`/`blocked`, using all existing receipt checks.

## Migration and rollback

The additive `2026_09_13_023057_create_task_landing_review_recoveries_table.php`
migration creates only the recovery audit table. It does not backfill, edit or
reset existing landings or assignments. Review and back up the target database
before an explicitly authorized deployment and migration. This implementation
does not itself authorize a live migration or recovery send.

The `down()` migration drops an empty recovery table. It refuses to drop a table
with any audit rows. If rolling back application code after an attempt, retain
the new table and all rows; the previous application can coexist with it. Use a
separately reviewed forward migration if later schema changes are needed. Never
delete an intent to make a resend possible.

This change has no HTTP route, UI workflow, frontend or SSR surface. Validation
uses focused PHP tests, synthetic accepted-task fixtures, a fake Herdr socket,
and disposable Git artifacts. Browser testing is not applicable.
