# Sequential task runner

The first runner executes an explicitly adopted feature in an existing linked
Git worktree. A feature must be a root group with one or more direct executable
children in a single dependency chain. Nested runtime groups, parallel work,
automatic merging, and automatic recovery are outside this version.

## Handoff contract

1. Commander starts a fresh implementer for one child task.
2. The implementer changes code, tests it, and submits a Commander handoff.
   It does not commit and must stop work after submitting.
3. The feature's retained reviewer receives the exact submission and reviews it.
   A revise verdict sends the same implementer another instruction in the same
   TaskRun. A pass leads to a separate commit instruction for the reviewer.
4. The reviewer creates one commit and submits. Commander verifies the real
   commit: its tree must equal the reviewed snapshot, its sole parent must be
   the task's base, and the worktree must be clean.
5. Commander starts the next child with a new implementer at that accepted commit.
6. After all children are accepted, Commander runs the configured final command
   and asks the retained reviewer to verify the integrated feature. Only a final
   pass against the unchanged checked commit completes the root.

Commander updates are the only handoff signal. Herdr idle/done is not required
or consumed. This is cooperative single-writer behavior, not process fencing:
agents must yield and wait for their next instruction. Each fresh agent starts in
its own full-width tab in the feature workspace. Finished implementers remain
parked in Herdr; the runner does not close or terminate their sessions.

The preparation MCP tools remain separate. Runtime handoffs use local CLI
commands with a different token for each exact dispatch.

Herdr may return from agent startup before the conversation is ready. Tasks
shares one 15,000ms bound between its launch request and prompt-readiness wait:
only an explicit pre-write `agent_not_ready` prompt refusal permits another
attempt, at 250ms intervals for at most 60 waits (61 one-shot attempts). Socket
request time is additional; this is not a 15-second end-to-end deadline. Before
each attempt, Tasks checks the retained workspace, tab, pane, terminal, name,
checkout and any known conversation. The first observed conversation is pinned
for the remaining attempts. A changed identity, observation failure, any other
prompt error or invalid response stops without another send. Readiness never
requires idle/done. This does not restart agents, replace assignments or recover
an uncertain prompt; exhaustion leaves the existing uncertainty handling intact.

## Project process environment

Final checks and Git inspection inherit only an explicit set of operating-system
values: executable search paths, the user home/identity, locale, terminal, temporary
directory, and XDG directory settings. All other inherited values are explicitly
removed from the subprocess environment, including values Laravel loaded from
Commander's `.env`. Merely setting a subprocess working directory or passing an
empty environment does not provide this isolation.

Project commands and their Composer/PHP children load their own configuration.
Commander database, app, cache, queue, provider credentials, Composer overrides,
and PHP/Node startup overrides do not cross this boundary. Inherited
`APP_BASE_PATH` and `APP_CONFIG_CACHE` cannot redirect a project bootstrap back
to Commander. Git receives only the runner's explicit Git overrides in addition
to that operating-system environment.

Prepare project-local test configuration and configuration caches for the correct
disposable resources. This boundary does not rewrite a project's own `.env` or
cached configuration, and is not an OS sandbox: processes still have the runtime
user's filesystem access. It prevents accidental environment inheritance, not a
project command deliberately accessing Commander files or loading shared secrets.

## Installation and configuration

Install the additive runtime migration through the normal deployment process.
Do not reset the database or rewrite applied task migrations. Runtime tables
can be installed while execution remains disabled.

Configure `config/task-runtime.php` on the host that owns both the repository
and the Herdr Unix socket. The Orbit entry reads:

- `COMMANDER_TASK_RUNTIME_ENABLED`: false unless explicitly enabled.
- `COMMANDER_TASK_ORBIT_REPOSITORY`: canonical primary checkout path.
- `COMMANDER_TASK_ORBIT_WORKTREE_ROOT`: canonical directory containing permitted
  feature worktrees.
- `COMMANDER_TASK_HERDR_SOCKET`: local Herdr Unix socket path.

The PHP entry also defines agent kind/arguments, project instructions, flow
version, final command, and timeout. Commands use argument lists, not interpolated
shell strings. Final timeout must be between 1 and 3600 seconds.

Agent arguments may contain the literal `{worktree}` token. The launcher replaces
it with the admitted absolute worktree path in each argument, preserving argument
order and boundaries. It does not evaluate a shell expression or change the saved
configuration. Arguments without the token pass through unchanged.

Orbit uses this token for its two Codex MCP working directories. Codex's shared
app server can run outside the agent's checkout, so relative MCP `cwd` values
cannot reliably locate Orbit's CLI and Gateway. Each `-c` override is one argument,
such as `mcp_servers.orbit-cli-boost.cwd={worktree}/apps/cli`. Do not add shell or
TOML quotes around this value: Codex accepts the expanded absolute path as a raw
string. Later explicit configuration arguments keep their normal precedence.

Admission snapshots the project configuration; later config edits do not change
existing assignments. The manifest hash binds task content and dependencies,
not deployment configuration. Inspect the configured flow as part of rollout.
Configuration snapshots do not version PHP code: drain active work before
deploying incompatible runner changes. This is not a hot-upgrade system.

The MCP argument templates apply to newly admitted Orbit workspaces. Older
snapshots remain unchanged; recover their existing clients explicitly when needed
instead of editing an immutable assignment or silently adopting current settings.

Project instruction edits apply to newly admitted workspaces. Existing workspaces
keep their admitted text through corrections, later tasks, review, and final
review. Do not edit an active workspace's snapshot or resend an instruction to
roll out wording changes. Changing the prompt renderer or runner itself is a
code deployment, not a project-instruction update.

Use the supported Laravel file cache store and the same lock directory,
database, app key, and release for the CLI, HTTP process, and worker. The
[Tasks lock-store guard](tasks.md) rejects other resolved store classes before
protected mutations, but does not attest that processes share a directory.
The task queue is an isolated database connection named
`task-runtime`, queue `tasks`, with `retry_after=3800`. Run a dedicated worker
through the environment's normal process supervisor:

```bash
php artisan queue:work task-runtime --queue=tasks --tries=1 --timeout=3700
```

Do not start `composer dev` or another HTTP server under Orbit. The existing
delivery worker must not consume the task queue. Do not enable automatic queue
retries for uncertain external dispatches.

## Explicit kickoff

First prepare a clean, registered linked worktree, including dependencies and
project-required disposable-machine tooling. The runner does not create it.
It rejects primary checkouts, unregistered paths, existing Herdr workspaces,
and unsupported Git setups such as sparse checkouts, submodules, unresolved
merges, external clean filters, or hidden index flags.

Confirm no other controller owns the Linear issue or worktree, including other
Commander instances. Local active Delivery records are checked, but the
`--exclusive` assertion is also necessary for external ownership.

```bash
php artisan tasks:inspect orbit ROOT_TASK_ID

php artisan tasks:start orbit ROOT_TASK_ID \
  --worktree=/absolute/approved-root/feature \
  --manifest=HASH_FROM_INSPECTION \
  --source=ORB-ISSUE_NUMBER \
  --exclusive
```

Starting binds the source and worktree and queues advancement. It does not
change Linear status or enable the legacy ProjectOrchestration. Unique database
constraints reject another root using the same canonical worktree or the same
project/source pair.

Orbit admission accepts `--orbit-flow=discovery|proof` (default `discovery`).
`--snapshot-replacement` records snapshot-replacement intent and is valid only
with `--orbit-flow=proof`. Other projects reject both Orbit-specific flags.
These flags do not create a worktree or change its flow. Fresh admission compares
the selection with the existing native `.loop/flow.json`; a missing file means
discovery, as in `bin/loop-flow status`. Malformed or redirected selections fail.
An Incus label does not select proof.

The selection is frozen in `workspace.configuration.orbit_profile` as
`{"schema":1,"flow":"discovery","snapshot_replacement":false}` (with the
requested supported values). Repeated admission must select that same profile;
in particular, a proof workspace still requires an explicit proof flag.
Historical workspaces without this key mean discovery without snapshot
replacement; existing rows and prompts are not rewritten. A present malformed
profile fails closed. Repeated admission does not reinterpret historical work
from the current native flow file or changed shared configuration.

Owner correction, 2026-09-14: proof-topology delivery is withdrawn. Do not select
proof admission, create proof scripts, construct new topologies, or run separate
capture/reacquisition stages. The flag descriptions above document legacy code,
not permission to use it. Current task briefs and workspace instructions require
focused tests and independent review, with existing-topology checks only when
needed. ORB-91 uses the existing topology and a snapshot of that topology.
The controller remains stopped; see [the withdrawal](tasks-orbit-proof.md).

The manifest is rechecked before each next instruction. Normal preparation
freezes when the first TaskRun starts. If briefs are edited after admission but
before that first run, the runner rejects the changed manifest instead of
silently accepting it.

## Audited pending-tail split

A feature that proves too large after execution starts can split only its last,
untouched implementation task. Use `tasks:split-pending` or the authenticated
`split-pending-task` MCP tool. The immediate predecessor must own the exact
current `sent` dispatch and active run. The target must remain pending, have no
run or dependents, and retain its pinned content version and dependency.

The operation first returns a proposal hash. Applying requires that exact hash
and all original pins under the workspace runtime lock and project mutation
transaction. It updates the existing tail in place as the first replacement,
appends the remaining briefs as a sequential chain, and records immutable
before/after manifests, hashes, active run and dispatch, observed HEAD, reason,
and evidence. Exact retries are no-ops; conflicting amendment keys fail.

The admitted workspace manifest remains immutable. Runtime checks use the latest
audited manifest, and later prompts, inspection, and landing evidence include
the amendment history. Recovery, reattempt, final-review, held, uncertain, and
already-started targets fail closed. This is not a general live-manifest editor.

## Agent submission and inspection

Each generated prompt gives the exact local artisan path, dispatch ID, and
secret handoff token. Run the submit command from the exact assigned worktree.
Create its JSON privately from the start. Use a fresh `mktemp -d` directory outside
the checkout (mode `0700`), and create the empty JSON file with mode `0600` under
`umask 077`. Verify both modes before writing the token; do not write it first and
`chmod` afterward. Quote the generated absolute file path in `--file`:

```bash
/absolute/php /absolute/commander/artisan tasks:submit DISPATCH_ID \
  --file=/absolute/private/handoff.json
```

Required fields are `token`, `summary`, and `evidence`. Review instructions
also require `verdict: pass` or `verdict: revise`. Any role can submit
`verdict: blocked` with a reason. Do not submit claimed Git identities;
Commander obtains them independently. Keep exact check commands/results and
machine evidence references in `evidence`. Never invent passing evidence.

This applies to newly written handoffs. It does not change the receipt schema,
replace an existing assignment, or require rewriting old immutable receipts.

For implementation and independent task review handoffs, use the existing
`evidence` text for one entry per assigned acceptance criterion: criterion, exact
check, working directory or topology/Node, observed result and exit status, and a
retained log reference when needed. Distinguish failed checks from checks not run,
and distinguish a known environment failure from an unknown cause. An environment
workaround is not proof of a product fix; record it and rerun affected checks.
Include temporary machine changes and their cleanup or remaining state. A commit
handoff cites only the passed review and commit/tree/base/clean-worktree binding;
it does not repeat that evidence. Final review may cite its own unchanged task
assessments when Commander supplies the matching reviewer/run/tree binding, but
must freshly judge integration, root acceptance, changed or unresolved claims,
and the new final-check result. This is evidence guidance, not a new receipt schema
or status.

For Orbit, issue-topology commands run sequentially even across different Nodes.
Await completion before the next command; do not bypass the topology lock.
Acquisition readiness proves setup only, not the task's acceptance criteria.

An accepted handoff persists the task transition and queues reconciliation.
The agent must then yield. An identical repeated receipt is harmless; a
conflicting receipt, different token, old run, or wrong round is rejected.
Prompts and raw tokens are encrypted in storage and hidden from inspection.
This is isolation between cooperative assignments, not a sandbox against
processes that can read the same user account's files or database.

```bash
php artisan tasks:inspect orbit ROOT_TASK_ID
php artisan tasks:advance WORKSPACE_ID
```

Inspect is read-only. Advance reconciles current task state; it does not resend
a prompt already attempted. A lost queue enqueue can be repaired with advance.
Repeated start only works if the admitted context still matches and the
worktree passes the clean admission check.

## Failure boundaries

For the narrowly supported current implementation whose same native worker and
reviewer were restored after interruption, see [session reconnection](task-session-reconnection.md).
It records new terminal routing without resending the original instruction.

A durable dispatch records intent before Herdr calls. Once sending starts, an
uncertain outcome is not blindly retried. A caught error or interrupted queue
job marks the current dispatch ambiguous and adds workspace attention. Queue
failure callbacks are tied to their own execution key, so a delayed callback
cannot mark a later instruction uncertain.

A valid current receipt can resolve an ambiguous delivery if the assignment
was recorded and all task/Git checks pass. Missing sessions, an interrupted
submit stuck in `submitting`, blocked agents, failed final checks, final revise
verdicts, and reviewer loss need explicit operator inspection. The narrow final
continuation below is not a general resume/reset command or automatic scope expansion.
Do not edit records to force progress without reconciling the actual process
and Git state. A failed queue job before a dispatch is claimed is also visible
in Laravel's failed-job log; it may not have a dispatch attention record.

Git snapshots include staged, unstaged, untracked non-ignored files, deletions,
modes, symlinks, and explicitly staged ignored files. Ignored dependencies and
secrets are not part of the reviewed tree. Snapshot refs under
`refs/commander/task-trees/` retain evidence; automatic pruning is not implemented.

For Orbit, retain its AGENTS.md, accepted ADRs, admitted flow rules, and required
Incus verification. The approved Commander package replaces legacy issue
planning only for the explicitly adopted feature. The default final Composer
command is not proof of machine behavior; the reviewer must check the required
Incus evidence as well. Starting the runner does not authorize live-fleet,
merge, or deployment changes. Harness changes require an explicitly approved,
dedicated harness task; discovering a harness failure in other work does not
authorize an opportunistic repair. This project guidance is snapshotted at
admission and does not change instructions for existing workspaces.

## Continue an exact held final attempt

For the separate first-child implementation blocker case, see
[audited reattempt](task-reattempt.md). It preserves the blocked receipt and
original run, then creates one new-base attempt only after separately verified
upstream integration. It does not use or broaden final continuation.

`tasks:continue-final` lets the coordinator record one correction within the
original feature, or retry final checks after an observed environment repair.
It cannot resume recovery, ambiguous dispatches, missing reviewers, unfinished
children, a passing root, or a changed/dirty candidate. It requires new per-dispatch
check evidence for the exact last accepted commit and effective manifest. Old
workspace-only final checks are not sufficient.

Inspect the final dispatch and preserve the observed cause, check output, and
repair evidence. Confirm exclusive worktree ownership and that every agent has
yielded. Git and Herdr observations run outside database/project locks; the
atomic mutation rechecks the workspace binding and all ledger guards. Exclusive
ownership is a coordinator assertion, not process fencing. A parked agent or
another process can still invalidate an observation; next-step Git and session
checks remain mandatory.

Keep the request JSON outside the worktree. Preview is the default and works
while runtime execution is disabled. It does not write tasks, refs, audits,
dispatches, or prompts. Applying requires the runtime to be enabled.

```bash
php artisan tasks:continue-final WORKSPACE_ID \
  --dispatch=HELD_FINAL_DISPATCH_ID --head=CHECKED_SHA \
  --manifest=EFFECTIVE_MANIFEST_HASH --exclusive \
  --file=/absolute/private/continuation.json
```

For a correction, use this exact shape:

```json
{
  "mode": "append_correction",
  "reason": "Explain why the final finding belongs to this feature.",
  "evidence": "Exact observed failure, reproduction, and retained evidence references.",
  "task": {
    "title": "Correct the integration finding",
    "description": "One bounded implementation task.",
    "acceptance_criteria": "Specific regression and integration checks.",
    "root_criterion": "An exact nonempty quotation from the original root acceptance text."
  }
}
```

The quotation binds the request to existing text; it does not prove semantic
scope. The coordinator must review that scope. The runner appends one direct
executable child depending on the accepted tail. It preserves root acceptance,
accepted children/runs, original admission manifest, captured configuration,
and reviewer. Normal preparation edits remain frozen. The correction gets a
new worker; its review revisions stay in its one new TaskRun. Later final rounds
use fresh dispatches/tokens and verify the new accepted commit and effective
manifest. Repeated final findings can append further audited children.

For a repaired environment with no source or task change, use:

```json
{
  "mode": "retry_final_checks",
  "reason": "Explain why another check is now justified.",
  "evidence": "Exact failed command/result and retained observations.",
  "environment_repair": {
    "cause": "Observed environment cause, not an unexplained nonzero exit.",
    "change": "What was repaired outside the source candidate.",
    "verification": "Exact readiness check, observed result, and evidence reference."
  }
}
```

The runner records these coordinator observations; it does not independently
prove that prose describes a repair or classify every failure as environmental.
Retry keeps HEAD, effective manifest, tasks, runs, and configured command
unchanged. It reruns that command and asks the retained reviewer for new final
evidence. It never treats readiness or the coordinator's assertion as a final pass.

Add `--apply` after reviewing the preview. Add `--advance` only with `--apply`
to queue the next normal instruction after the atomic ledger update. Without
it, inspect then use `tasks:advance`. An identical repeated request returns its
existing immutable audit and queues nothing; a conflicting request for that
origin dispatch fails. If enqueue fails, the audit remains recorded and
`tasks:advance` is the repair. No agent is started or prompted by the mutation.

Each new final dispatch stores immutable check output, exit status, command,
HEAD, manifest, and whether the candidate remained unchanged. A completed
nonzero check is `check_failed`, with no reviewer prompt. Dirty/drifted check
results are held but cannot continue. Interruptions or uncertain external
outcomes remain `ambiguous`. History and old failure callbacks remain bound to
their original dispatch; an audit clears only its exact current final hold.

### Rollout boundary

Install the additive final-continuation migration before loading the new code
in any CLI or queue worker. Preserve a consistent database backup and drain
active job execution; never mix old/new workers. The migration does not backfill
or reinterpret existing checks, prompts, dispatches, or recovery state.
Its `down()` supports disposable migration tests. After continuations exist,
rolling it back discards audit/check history and leaves appended tasks without
their effective manifest chain; use a reviewed forward fix instead.

Compatibility is deliberately limited. Existing pre-final implement/review/commit
receipts retain their contract and can reach a newly prepared final dispatch
after upgrade. Only newly prepared finals carry `final_check_version=1` and can
produce evidence eligible for continuation. An already prepared legacy final
instruction is refused by the new dispatcher without editing or sending it;
keep its original runner until that instruction is reconciled. An already sent
legacy final receipt can still use its old workspace-check validation, but its
result cannot be continued by this command. Legacy final-held and ambiguous
records remain refused. Do not add version markers or copy workspace evidence
onto old dispatches to bypass this boundary.

For existing work such as ORB-247/248, verify that no final dispatch was prepared
before deciding a pre-final transition is supported. Inspect actual state at
rollout, not an earlier status report. The ordinary retained Herdr identity
contract is unchanged: workspace/tab/pane/terminal/name/cwd must match, and a
native UUID is required to match only if one was originally stored. No stricter
recovery UUID requirement is imported here. This change adds no recovery-resume
authority and does not permit starting a new reviewer to replace a lost one.

## Recover accepted work after ledger loss

`tasks:recover-accepted` records prior accepted child work from retained evidence
into a separate offline SQLite quarantine copy. It does not restore missing
historical events, approve the whole feature, replace the live database, or
resume agents. The first supported case requires an intact pre-admission backup
and evidence for every direct child in the approved sequential manifest.

Stop the affected worker and preserve the damaged database and logs first.
Create a separate consistent copy of the intact backup; do not use the backup
itself or the application's database as the recovery target. Preserve unrelated
services and their databases. The command refuses configured application
database paths, linked files, sidecars, WAL-format files, changed backup contents,
and conflicting runtime history. Both inputs must use rollback-journal SQLite
format: even a sidecar-free WAL file can create sidecars when opened read-only.
The importer checks the file header before opening SQLite and never changes its
journal mode. `--exclusive` asserts operator ownership; it does not stop a worker
or grant authority over unrelated data.

Prepare a private, reviewed JSON package containing the source-evidence and
backup paths and SHA-256 digests. Schema 1 selects the manifest, manifest hash,
original workspace, and each child's commit binding, passed approval, native
commit acknowledgment, worker and reviewer using JSON pointers into the retained
source evidence. The importer verifies these against the backup, target briefs
and sibling order, and real registered clean Git worktree. Merely supplying a
commit SHA or a claimed pass is insufficient.

With the runtime disabled, inspect the intended recovery first:

```bash
COMMANDER_TASK_RUNTIME_ENABLED=false php artisan tasks:recover-accepted orbit ROOT_TASK_ID \
  --database=/absolute/private/quarantine.sqlite \
  --backup=/absolute/private/pre-admission.sqlite \
  --evidence=/absolute/private/recovery-package.json \
  --exclusive
```

The default is read-only dry-run. It reports whether the additive
`task_recoveries` migration is needed. Install that migration explicitly on the
quarantine database through the normal migration command, with its effective
connection verified first; the recovery command does not create databases or
run migrations. Recheck the dry-run, then repeat with `--apply` to record the
validated recovery in one transaction. Do not point the application's default
connection at the quarantine database to invoke the importer: it uses its own
explicit connection and deliberately rejects default-connection targets.

Recovery creates current-time, explicitly marked runs and reviews. Original
observed IDs, timestamps, approvals, acknowledgments, earlier review history and
known gaps remain in immutable recovery provenance. They are not relabeled as
newly performed implementation or review. Inspection exposes that provenance,
and recovered handoffs retain the original acceptance evidence for later review.

The recovered children reference their verified accepted commits. The root
stays incomplete; final checks and results remain empty. Operational Herdr
sessions are unset, a workspace attention hold blocks advancement, and no jobs,
dispatches, tokens or prompts are created. Repeating apply is refused once the
quarantine contains a recovery. An error rolls back the recovery transaction.

Keep quarantine inspection, live cutover and explicit session reconciliation
separate. Preserve archived evidence outside temporary storage. Only a later
supported resumption may bind reconciled agents and run new final validation;
never replay old capabilities or clear the hold with raw database edits.

## Resume a recovered feature

`tasks:resume-recovered` supports only a recorded accepted-child recovery whose
root remains pending. It is not a general reset, correction, or worker-management
command. Install the additive `task_recovery_resumptions` migration through the
normal deployment process. Complete and verify any database cutover separately.

Preview uses a separate read-only connection to the explicitly pinned configured
SQLite database. It does not change the default connection, take a writable cache
lock, create Git refs, queue work, or mutate Herdr. This first version requires a
canonical, unlinked rollback-journal database without sidecars and never changes
journal mode. `--database` must match the effective configured database, including
any database URL override. It does not select an arbitrary database to operate on.

```bash
php artisan tasks:resume-recovered WORKSPACE_ID \
  --database=/absolute/commander/database/database.sqlite \
  --recovery=RECOVERY_UUID \
  --head=FINAL_ACCEPTED_COMMIT \
  --exclusive
```

The preview checks the original package/source/backup hashes, persisted source
snapshot and accepted run/review mappings, unchanged task manifest and admitted
configuration, complete supported flow settings, and real clean Git commit chain.
The exact recovery attention hold must remain in place; operational sessions,
final checks/results, and dispatches must still be empty. A competing local
Delivery for the source or worktree blocks resumption.

Every accepted child must identify the same non-null retained reviewer conversation.
Using the admitted socket, fresh `session.snapshot` and `agent.get` observations
must match its repository, linked worktree, workspace, pane, tab, terminal, name,
working directory, and conversation. Historical Commander workspace IDs need not
equal recovered IDs; the reviewer name is never regenerated. Status is recorded
as an observation, not an idle gate. Missing or replaced sessions keep the hold;
there is no fresh-reviewer fallback and completed workers remain parked.

Repeat with `--apply` to record one immutable resumption audit and bind the verified
reviewer under the runtime lock and a database transaction. The command clears only
the original recovery hold. Apply without advancement is allowed while runtime is
disabled. `--exclusive` is operator attestation of ownership and worker exclusion;
neither the enabled flag nor this command stops workers or proves exclusion.

Add `--advance` only with `--apply` and enabled runtime to enqueue the normal
`AdvanceTaskRunner` after the audit and session binding commit. It creates a fresh
final check and final-review dispatch; old checks, prompts, tokens, and approvals
are not replayed as final acceptance. A failed check or final revise leaves the
root incomplete. Inspect through `tasks:inspect` to see recovery and resumption
audits alongside current runtime state.

An identical repeated request returns its existing audit without re-enqueueing or
clearing a later hold. Changing the pins or advancement choice is a conflicting
retry. If queue insertion fails, output explicitly reports **resume recorded,
advancement pending** and the command fails without undoing the committed audit.
Inspect and resolve the queue problem, then use ordinary `tasks:advance`; do not
re-run recovery, replay historical prompts, or edit runtime rows to force progress.

## Validation

```bash
vendor/bin/pest --no-tia --compact tests/Feature/TaskRuntimeTest.php tests/Feature/TaskRuntimeInterfaceTest.php tests/Feature/TaskGitWorktreeTest.php tests/Feature/TaskProcessEnvironmentTest.php
vendor/bin/pest --no-tia --compact tests/Feature/TaskMigrationTest.php
vendor/bin/pest --no-tia --compact tests/Feature/TaskRecoveryTest.php tests/Feature/TaskRecoveryResumptionTest.php
```

Tests use fake agents, a fake Herdr Unix socket, temporary real Git worktrees,
and an isolated test database. They cover receipt-only handoffs, corrections,
one fresh implementer per task, retained reviewer identity, duplicate/stale
updates, uncertain prompt outcomes, queue failure ownership, actual Git drift,
and final feature gating. They do not prove real agent compliance, production
prompt delivery, OS-level process exclusion, or multi-node operation.

Process-boundary tests run nested Composer/PHP checks against disposable SQLite
databases. They reproduce the unsafe inheritance behavior in a fixture, verify
that isolated checks write only the project's database, and preserve the
coordinator fixture byte-for-byte, including inherited cached-config overrides.

This runner adds only backend and CLI behavior, with no new UI controls.
# Fresh worktree preparation

`php artisan tasks:prepare-worktree orbit ROOT --source=ORB-N --manifest=HASH --exclusive`
prepares one previously absent `orb-N` branch and path. The root must contain a
complete, approved, unattempted task chain. The command runs in the foreground.
Preparation is not a root assignment; unique runtime admission remains the
execution boundary. Evidence and locking are scoped to the source and path.
It does not admit the root or create workspace, run, job, or Herdr records. On
success it prints the separate `tasks:start` command.

`--exclusive` attests that the coordinator has checked external ownership.
Local Tasks and active Delivery ownership and a read-only Herdr snapshot are
checked before and after setup. These checks cannot fence unrelated external
writers or prove ownership in another Commander database.

Preparation shares Orbit's native per-issue `controller.lock` and primary
`checkout.lock` under the Git common directory's `orbit-delivery/v1`. Every
legacy `state.json` or `worker.json` blocks fresh creation, including a
`needs_attention` journal. No database transaction or expiring cache lock spans
bootstrap. Existing admitted workspace behavior is unchanged.

Primary preflight reuses the task checkout safety checks before reading native
setup inputs and again before invocation. Hidden index flags, sparse checkouts,
external Git filters, submodules, and unfinished Git operations are refused.
Each primary check also hashes the actual required setup and configuration
files without filters and checks their executable modes against committed
identities. This catches changes hidden by cached Git stats or disabled
file-mode tracking. The command does not clear flags, rewrite the index, or
write snapshot refs to make a checkout appear clean. An ordinary clean-status
result alone cannot authorize setup scripts.

Under those locks, a private `orb-N/task-preparation/intent.json` is persisted
before any setup invocation. It pins the Commander instance, root, manifest,
source, common directory, repository, exact path, command, timeout, and observed
inputs. Captured stdout and stderr and a separate observed-exit record remain
there. A separate `success.json` is written only after zero exit, Git/config
verification, and a fresh manifest/ownership check. New `tasks:start` admission
holds the issue lock and checks this receipt before Git validation can write
snapshot refs. A missing, mismatched, failed, or interrupted receipt blocks
admission, even after the original parent and its lock are gone.

There is no v1 retry, cached-success replay, erasure, or reconciliation command.
Repeating a completed or incomplete invocation is refused. An unresolved intent
requires separately authorized coordinator reconciliation. A dead parent or
released lock does not prove that native children have stopped. Terminal loss
or timeout may leave children running; no detached survival or process
supervision is promised. All remaining private files and partial worktrees are
retained. Output emitted by the wrapper is captured without truncation; native
bootstrap buffers child logs privately and may delete them before emitting
them on interruption, so interrupted child logs can be incomplete.

The default native timeout is 3600 seconds, configured by
`COMMANDER_TASK_PREPARATION_TIMEOUT` (maximum 7200). Temporary resources are
private and outside Git worktrees below `COMMANDER_TASK_PREPARATION_TMP_ROOT`
(default `/tmp`). Real HOME is preserved. Preparation starts with the existing
task process isolation policy and adds private TMPDIR, ORBIT_HOME, COMPOSER_HOME,
a disposable key, in-memory SQLite, empty DB_URL, array cache/session, and sync
queue. It does not force APP_ENV. This is environment isolation, not an OS
sandbox; project-owned Composer/native code still runs with the caller's user.

Native creation fetches/prunes origin, fast-forwards clean primary main, queues
native cache maintenance, installs dependencies, creates template dotenv files,
and runs guidance checks. Fresh remote issue and prefix branches are refused.
If the observed remote-main object is missing locally, preflight fetches only
that exact object with no tags, ref updates, FETCH_HEAD write, or automatic
maintenance; the retained preflight records this object-store side effect.
Unrelated main advancement is permitted. Changes to native `bin/` inputs,
Composer manifests/locks, project bootstrap/config/scripts, PHPUnit files,
dotenv templates, Git ignore/attributes/submodules, or the native plan template
are unsupported until separately inspected. The new branch HEAD must equal
the observed post-update primary and origin/main and the preflight remote main.
The command retains input identities before and after setup.

Configuration-only child probes apply the installed PHPUnit loader to CLI/SDK
`phpunit.xml.dist` and the other three projects' `phpunit.xml`, plus each actual
`phpunit.guidance.xml` used by native guidance commands. They load local dotenv
and project database configuration without providers or PDO. Vendor roots must
be local; contained dependency links are allowed. Redirected configuration,
config caches, alternate dotenv files, changed template dotenv, and dependency
links escaping the worktree are refused. The native `.loop/plan.md`, discovery
flow, and `.loop/proof/` remain intact. Preparation establishes setup only and
does not claim landing readiness; the existing landing adapter also rejects
the empty native proof directory.
