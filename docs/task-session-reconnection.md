# Reconnect restored task sessions

`tasks:reconnect-sessions` records new terminal identities after an operator has
restored the **same** native Codex conversations in their existing named Herdr
panes. It does not start agents, send prompts, change task status or resend an
existing dispatch. The original task/run, dispatch sessions, tokens, prompts,
reviews and receipts remain untouched. Only an immutable reconnection audit and
the workspace's operational reviewer pointer are written.

This initial operation supports one original-history Orbit workspace with a
current `implement` / `sent` / round-zero instruction, an accepted previous child,
and its retained reviewer. It does not support first-child, blocked, ambiguous,
reattempt, imported recovery, final-review or landing recovery. One audit is
allowed per workspace; another interruption requires explicit reconciliation,
not replacing the existing audit.

## Evidence and native restore

Reconcile the actual process and Git state first. Preserve the interrupted dirty
work and original dispatches. Confirm sole issue/worktree ownership and keep the
other controller paused. Restore each exact native conversation, using the
captured agent arguments followed by `resume UUID`, without a new prompt. Do not
use `--last`, `--fork`, different configuration or a new conversation. Keep both
agents yielded while recording the audit. Do not restart shared Herdr.

Retain private copies of both native transcripts in **persistent** owned storage
outside the worktree, not only `/tmp`. Each must be a canonical regular UTF-8 JSONL
file (at most 32 MiB, with no group/other permissions), with the original native
UUID and checkout in `session_meta` and the exact corresponding original
dispatch/token in a user assignment. Commander verifies the secret in memory;
never put it in the request or output. Reviewer evidence uses its last accepted
commit instruction. Transcript copies remain confidential even though the audit
only records references and hashes.

Commander independently reads Herdr's exact workspace/pane/agent and
`pane.process_info` observations. It verifies the real foreground Codex binary
through Linux `/proc`: PID/start time/boot identity, UID, executable, exact argv,
checkout, and PTY/session/process-group agreement with the pane shell. A node
wrapper or a matching process elsewhere is not sufficient. A null Herdr native
ID stays null; the separately recorded `conversation_id` is supported by native
process and transcript evidence, not invented API data. Any later nonnull Herdr
ID must match it. Future task and supplemental-review prompts recheck the audited
process identity immediately before sending. This is targeted identity checking,
not a new idle/done requirement for normal handoffs.

## Preview and apply

Create a private request outside the worktree. Objects are unordered; Commander
normalizes keys and rejects unknown fields. Example shape (use actual pins):

```json
{
  "dispatch_id": 75,
  "head": "18f5f52341d793bb4ad73722ddd75ded6e3e2e7d",
  "manifest": "64-character approved manifest SHA256",
  "reason": "Observed reboot and exact native restore evidence references.",
  "sessions": {
    "implementer": {
      "conversation_id": "original native UUID",
      "transcript": {"path": "/persistent/private/worker.jsonl", "sha256": "exact raw SHA256"},
      "session": {
        "workspaceId": "wH", "tabId": "wH:t1", "paneId": "wH:p3",
        "terminalId": "new actual terminal", "agentName": "task-w16-t41",
        "workingDirectory": "/fast/worktrees/orbit/orb-91", "agentId": null
      }
    },
    "reviewer": {
      "conversation_id": "original reviewer native UUID",
      "transcript": {"path": "/persistent/private/reviewer.jsonl", "sha256": "exact raw SHA256"},
      "session": {
        "workspaceId": "wH", "tabId": "wH:t1", "paneId": "wH:p2",
        "terminalId": "new actual terminal", "agentName": "task-w16-reviewer",
        "workingDirectory": "/fast/worktrees/orbit/orb-91", "agentId": null
      }
    }
  }
}
```

```sh
php artisan tasks:reconnect-sessions WORKSPACE --file=/private/request.json --exclusive
php artisan tasks:reconnect-sessions WORKSPACE --file=/private/request.json --exclusive --request=EXACT_PREVIEW_HASH --apply
```

Preview is read-only. Apply requires an enabled runtime and the unchanged preview
hash. Task ledger, dirty source fingerprint and both yielded live process
bindings are rechecked inside the workspace lock before the atomic write. Exact
replay returns the saved audit without observing, prompting or advancing again.
Conflicting requests fail. The CLI inspection includes sanitized reconnection
history, and terminal views resolve the new terminal without rewriting old
dispatch sessions.

The normal token-bound handoff remains valid. Do not submit on the agent's behalf
or manufacture a success receipt. If native resume already started work, observe
that exact turn; do not duplicate its prompt. If it is genuinely yielded with no
active turn, any needed explicit manager continuation is a separate disclosed
manual operation, not a dispatch reset or automatic recovery. Observe the real
handoff, review/corrections and reviewer commit before calling recovery successful.

## Deployment

Drain the isolated Tasks worker and concurrent owned mutations during the small
rollout; preserve queued jobs and unrelated services. Back up the database and
deploy an exact reviewed file allowlist with only the additive reconnection
migration. Do not run unrelated pending migrations. Configuration/assignment
snapshots are unchanged. An empty new audit table preserves ordinary behavior.
Rollback must retain audit rows; the down migration refuses to erase them.

Future final dispatches record the new actual reviewer session, so normal final,
proof and landing identity/accepted-commit checks remain in force. Old approvals
are not rewritten. Include the reconnection record as delivery evidence; this
operation does not prove fully automatic reboot recovery or approve the feature.
