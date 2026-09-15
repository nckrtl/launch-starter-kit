# Tasks

Commander provides project-owned task records and lifecycle actions.
An opt-in sequential runner can dispatch agents for explicitly adopted features.
It does not replace Orbit's existing delivery loop automatically.
Prepared work can be created and edited through the project Tasks UI and the
authenticated Commander MCP server. Planning happens outside Commander.

## Project tasks and flows

A task is a concrete piece of work with an expected outcome. Each project can
define flows in PHP that tell Commander how to complete its tasks. Task records
hold the work and its state; a flow supplies the execution logic. Shared task
actions and integration helpers can be reused across projects, while each
project keeps its own flow logic.

For Orbit, an "implement feature" task represents the whole feature. Prepare
its brief, acceptance criteria, and implementation subtasks with the user.
Commander lets the user inspect the briefs and change the subtask order.
Deciding that the work is ready remains a conversation outside Commander;
there is no approval or kickoff control yet. The subtasks are bounded
implementation assignments, not separate features.

This is a capability of Commander, not a separate platform. There is no
Workflow or WorkflowRun entity. TaskKind's group/executable distinction only
describes task structure; it does not select a flow. The first runner uses
project-specific PHP configuration and explicit CLI kickoff. See
[task-runtime.md](task-runtime.md) for setup, handoffs, and limits. There is
no UI kickoff or runtime MCP tool. The live Orbit flow remains unchanged.

## Task graph

- A Task belongs to a project identified by its shared-knowledge manifest slug.
  Creating it does not enable ProjectOrchestration.
- An executable task has runs but no children. A group has children but no runs.
- Parentage describes decomposition, not execution order.
- Subtasks form one sequential chain within their parent. The first has no
  dependency; each later child depends on the previous child. There is no
  position column. New children append to the chain.
- ReorderTaskChildren replaces the whole sibling order in one transaction.
  Callers send every child ID once, plus the order they last read. Stale orders,
  foreign tasks, branches, multiple heads, and disconnected chains are rejected.
- Root tasks remain independent. The existing PHP AddTaskDependency action can
  connect roots in the same project without cycles. It cannot change child
  chains; use the reorder action instead. No root dependency editor is exposed.
- Descendants inherit their ancestors' prerequisites. Cross-group coordination
  goes through sibling groups, not dependencies on another group's internals.
- Every prerequisite must be completed before a task can start.
- A failed or unfinished prerequisite stops the chain. Reads and execution
  reject malformed chains instead of guessing an order.

## Preparation interfaces

Open Projects → a project → Tasks. Create a group for a feature and add
executable subtasks (or nested groups). Each brief has a title, objective and
context, and acceptance criteria. Empty briefs can be saved while preparing
work; saving does not approve it or claim it is ready for execution.

The detail page shows direct subtasks in execution order with their full briefs.
Up/down controls save each move immediately. Open a nested group to review its
own chain. Top-level tasks are listed newest first, not in execution order.

The MCP endpoint is `/mcp/commander`. It requires the bearer token configured
in `COMMANDER_MCP_TOKEN` and rejects requests when that setting is empty.
Keep this private application and its token within the trusted project network;
the token has Commander-wide access, not per-agent or per-project permissions.

- `list-tasks(project_id)`: top-level tasks in one project.
- `get-task(project_id, task_id)`: brief, content_version, editable flag,
  direct children, and ordered_ids. Read nested groups separately.
- `create-task(project_id, title, kind, creation_key, description?,
acceptance_criteria?, parent_id?)`: create prepared work. Kind is
  `group` or `executable`; omitted parent_id creates a root.
- `update-task(project_id, task_id, expected_version, title, description?,
acceptance_criteria?)`: replace the brief, using content_version from the
  read. Omitted text fields become empty. Project, parent, kind, and status
  cannot be changed through this tool.
- `reorder-task-children(project_id, task_id, ordered_ids, expected_ids)`:
  submit the desired complete order and the previously read ordered_ids.
- `split-pending-task(...)`: preview or apply a narrowly scoped split of the
  untouched pending tail while its immediate predecessor owns the active run.
  Preview first, then apply the returned proposal hash with the same manifest,
  target version, run, dispatch, amendment key, reason, evidence, and two to
  eight complete replacement briefs. The original tail ID becomes the first
  replacement.

Creation keys are unique within a project. Retry the same creation with the
same key and content to get the same task. A reused key with different content
is rejected, including when that task has since been edited. Reusing a stale
content version is also rejected: read again and reconcile the brief.
An already-applied reorder is a no-op while the feature is still editable.

All creation, content, and ordering changes use shared PHP actions. The UI and
MCP expose no lifecycle transitions, agent dispatch, deletion, or worktree
operations. Briefs and order freeze across the entire feature when its first
run starts, including pending siblings, nested groups, and failed attempts.
The audited tail-split operation is the only exception. It cannot edit accepted
or active work, relax normal preparation rules, delete a task, or operate after
recovery, reattempt, ambiguous dispatch, hold, or final review.

Each root represents one execution context, such as a feature. The database
allows only one active TaskRun anywhere under that root, including nested groups. Other
roots can run independently. The runtime's TaskWorkspace adds a unique
canonical worktree binding per root and a project/source binding. These local
constraints do not detect another Commander database or an external controller.

## Connect an agent

Use the HTTP endpoint for agents on the trusted network. The client must resolve
the Commander hostname normally and trust its issuing CA. Do not disable TLS
verification or replace Cluster routing with an SSH MCP transport.

For Codex, add the following to the host's private Codex configuration. Replace
the example URL and credential-file path with those for the intended instance:

```toml
[mcp_servers.commander_tasks]
url = "https://commander.example/mcp/commander"
http_headers_helper = "/usr/bin/cat /absolute/private/commander-headers.json"
enabled_tools = ["list-tasks", "get-task", "create-task", "update-task", "reorder-task-children", "split-pending-task"]
startup_timeout_sec = 20
```

The private JSON file contains an `Authorization` header whose value is
`Bearer <instance token>`. Keep it outside the repository with mode `0600`.
Its token must match that instance's `COMMANDER_MCP_TOKEN`. Do not print the
file or run the header helper in a displayed terminal. The helper is supported
for HTTP connections made locally by each Codex host, not through Codex's remote
execution transport.

Reload MCP configuration or start a new Codex task after changing the settings.
The tool allowlist limits what this client exposes; it does not narrow the
token's server-side permissions. These tools prepare work only and cannot
start a TaskRun or dispatch an agent.

## Minimal execution cycle

A TaskRun spans implementation, review, corrections, and commit acceptance.
It stays running and retains both its task and root execution slots until
explicit acceptance or failure. Ready for review is not run completion.

1. StartTaskRun assigns distinct worker and reviewer references. It records the
   inspected base commit for coding work. A feature keeps the same reviewer;
   another task in that feature requires a different worker reference.
2. MarkTaskRunReadyForReview records a numbered review submission and puts the
   task in awaiting_review. The submission holds immutable output and, for code,
   the full tree snapshot of the uncommitted changes. No commit is required.
3. RecordTaskRunReview records the assigned reviewer's verdict for that submission.
   A revise verdict puts the task in changes_requested. The same worker continues
   the same TaskRun and submits the next review round. A pass puts the task in
   awaiting_commit; it cannot be silently replaced by another submission.
4. After a pass, the reviewer creates one clean commit. Commander independently
   inspects it and calls AcceptTaskRun with the observed commit identity, tree,
   and parents. The tree must match the passed submission, and its sole parent
   must match the run's fixed base.
5. Acceptance completes the run and task atomically, records commit_sha and
   accepted_task_run_id, and releases their execution slots. Agents yield after
   submitting their Commander update. The runtime trusts that handoff without
   also waiting for Herdr idle/done. The next coding task starts from the last
   accepted commit with a fresh implementer.

Non-code runs omit the base and tree identities. They use the same review cycle,
but acceptance takes no commit and retains the passed submission's output.

The runtime also supports explicitly bound [artifact-only Orbit proof results](tasks-artifact-results.md).
These keep their original base and unchanged tree. An audited file binding and
independent passing handoff complete the child without a commit instruction.

A group remains pending until CompleteTaskGroup is explicitly called. It needs
at least one child, all children completed, and satisfied prerequisites. This
is a structural close, not final feature validation. An autonomous feature
coordinator must still perform and record the integrated feature check and
review before closing the root.

## PHP entry points

Use the actions in App\Tasks\Actions; raw model and pivot writes bypass the
supported mutation API. The example assumes injected actions, verified actor
references, and Git identities inspected by a trusted coordinator:

```php
$run = $startTaskRun->handle(
    $task,
    'attempt-1',
    $workerRef,
    $reviewerRef,
    baseSha: $baseSha,
);

$review = $markTaskRunReadyForReview->handle(
    $run,
    $workerRef,
    round: 1,
    output: ['summary' => 'Implementation and checks ready'],
    treeSha: $treeSha,
);

$recordTaskRunReview->handle(
    $review,
    $reviewerRef,
    TaskReviewVerdict::Pass,
    $reviewSummary,
    $evidenceRef,
);

// Only after the reviewer creates the commit and Commander inspects it:
$acceptTaskRun->handle($review, new TaskCommit($commitSha, $commitTreeSha, $commitParentShas));
```

TaskReviewVerdict is in App\Tasks\Enums. TaskCommit is in App\Tasks.
These actions persist state only. They do not launch an agent, capture a Git
tree, run checks, create a commit, or query Herdr.

StartTaskRun's baseSha declares a coding run. MarkTaskRunReadyForReview requires
a matching-format tree SHA for it; non-code submissions cannot attach a tree.
Git identities must be full lowercase SHA-1 or SHA-256 values. A TaskCommit
rejects zero or multiple parents, mixed object formats, and a commit equal to
its own parent. Supplied identities are not proof that those Git objects exist.

## Determinism and race boundaries

- Commander is the only coordinator. Worker and reviewer updates identify the
  assigned role and exact run or review submission. References are checked
  against the persisted assignment, never inferred from the currently visible
  terminal. Future APIs must authenticate those identities, not trust a request
  body that claims an agent reference.
- A start key is scoped to a task. Repeating it returns the original run only
  when input, assignments, and baseline match. Changed input is rejected.
- A review round is scoped to its run. Ready retries return the original
  submission only when output and tree match. New rounds must be consecutive
  and are allowed only in implementation or changes_requested.
- Each submission accepts one verdict. Identical verdict retries are no-ops;
  conflicting verdicts or an unassigned reviewer are rejected. Earlier rounds
  stay as history and cannot control a later round.
- Acceptance requires the latest passed round of the latest attempt. Identical
  acceptance retries retain the original completion time; a different commit,
  tree, or parent cannot rewrite acceptance.
- A feature cannot gain subtasks or change briefs or child order after any
  descendant has attempted execution. A task or its descendants cannot gain
  root dependencies after work has started.
  Task project, parent, kind, attempted content, run input, assignments, and base
  are fixed. Review submission content and recorded verdicts are immutable.
- Short mutations use a per-project cache lock and database transaction.
  Unique constraints additionally protect active task/root ownership, attempt
  numbers, start keys, and review rounds. An interrupted transaction rolls back
  its run, review, and task changes together.

The project cache lock has a 30-second lease and a five-second acquisition
timeout. The workspace runtime lock keeps its 60-second lease and five-second
timeout. Both mutation entrypoints check the actual resolved cache store before
opening a database transaction. This single-host version supports Laravel's exact
`FileStore` class (`CACHE_STORE=file` with the normal file-store configuration).
Array, null, failover, subclasses, and other stores are rejected in every app
environment, including tests. Other Laravel shared stores are outside this
verified scope; this does not mean they are inherently unsafe.

All Tasks CLI, HTTP, and worker processes must use the same file-lock directory.
The type guard does not attest configuration, directory identity, storage
sharing, or multi-node safety. Changing a config value does not replace a store
already resolved by a running process. Tests use explicit private, disposable
file-lock fixtures. Never hold these locks while waiting for agents, Git,
network calls, or long checks. They are not worktree write locks and do not prove
multi-node recovery.

## Herdr and worktree ownership

The runtime assigns work to only one actor at a time.
The worker yields before review; the reviewer yields before corrections.
Only the reviewer may create the accepted task commit, after its pass. A
queued handoff does not by itself prove the previous process has stopped writing.

Explicit task updates drive the cycle. This version does not subscribe to Herdr
idle/done for task advancement. It trusts each agent to yield after submitting
and wait for its next prompt. Dispatch IDs, scoped handoff tokens, active runs,
and review rounds reject delayed or conflicting updates. There is no additional
process-status gate, worker termination requirement, or OS-level write fence.

Before acceptance, the runtime must inspect the actual commit and worktree:
verify the sole parent, exact reviewed tree, required check evidence, and no
additional uncommitted changes. Capture the full intended tree when submitting
for review, including intended new files, deletions, modes, and unstaged edits;
reviewing only a staged diff is insufficient. Git hooks that change content
invalidate the reviewed snapshot.

The runner connects these operations and inspects real Git objects. It does
not claim physical single-writer enforcement from the database constraint.
Agents must cooperate with the handoff contract.

## Failure and recovery

FailTaskRun is a trusted coordinator action. It requires the expected task state,
current review round (zero before the first submission), and a reason. Its
terminal output retains that handoff and the supplied failure result. A delayed
failure cannot abort another round or rewrite a finished attempt.

Failed runs retain their submissions and verdicts. A deliberate new attempt
uses a new start key. Requested review changes are not failure and never create
a new run. Completed tasks are not reopened; later work requires another task.

Passed work in awaiting_commit cannot be blindly failed or restarted: an
external commit may already exist. Keep the run active and inspect the actual
outcome before retrying acceptance. Prompt delivery, commit creation, worker
shutdown, reviewer replacement, and failed-worktree recovery need explicit
runtime reconciliation; no blind external retries are implemented here.

## Legacy boundary and rollout

Delivery, PhaseRun, AgentDispatch, and Receipt remain authoritative for the
existing Orbit engine. The task foundation does not rename their IDs, dual-write
their state, or consume their jobs and Herdr events. Migrate a process through an
explicit cutover; never run both engines for the same issue or worktree.

Task development runs in the isolated Sabre checkout on
`codex/project-tasks`, served at `https://tasks.commander.test`. The live
Orbit delivery engine is not changed. There are four task migrations: the
task foundation, the review gate, additive preparation fields, and runtime
workspaces/dispatches. Apply new
migrations through the normal deployment process; do not rewrite applied
ones. Rolling back removes corresponding task fields and review/acceptance
history; preserve required evidence before a real rollback.

Existing data with unordered children must be inspected and explicitly
converted before using this version. It fails closed on malformed chains
rather than silently choosing an execution order.

The runtime remains disabled until explicitly configured. Its local CLI uses
dispatch-scoped handoff tokens; MCP preparation access is not authority to
impersonate a worker or reviewer.

## Validation

Tests use an isolated in-memory database and temporary project manifests.
Migration tests run outside test transactions, matching Laravel's SQLite
migration path, and check populated rollback and foreign-key integrity.

```bash
vendor/bin/pest --no-tia --compact tests/Feature/TaskTest.php tests/Feature/TaskReviewTest.php tests/Feature/TaskMigrationTest.php
vendor/bin/pest --no-tia --compact tests/Feature/TaskPreparationTest.php tests/Feature/TaskInterfaceTest.php
vp run build
vendor/bin/pest --no-tia --compact tests/Browser/TasksTest.php
vendor/bin/pest --no-tia --compact tests/Feature/DeliveryLedgerTest.php tests/Feature/ShadowDeliveryCommandTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Tasks app/Models/Task.php app/Models/TaskRun.php app/Models/TaskRunReview.php
```

Coverage includes corrections within one run, role mismatches, duplicate and
stale handoffs, root ownership, snapshot/commit matching, and injected transaction
interruptions. These tests do not prove real process exclusion, prompt delivery,
or multi-node recovery. Browser coverage exercises feature creation, child
creation, brief editing, order changes, and frozen controls. Feature tests
disable SSR; verify the built application and SSR separately in a real browser.
