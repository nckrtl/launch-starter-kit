# Project delivery orchestration

Status: implementation in progress; live cutover remains disabled

Continuation thread: `codex://threads/01a08cc2-0105-74b1-aeb0-be013aa73267`

The implementation thread should begin with the discovery checklist near the
end of this document. It should then implement stages 1 through 4 as the first
reviewable slice. Keep real pull request merging disabled until event
correlation, receipt validation, and idempotent advancement have passed in
shadow mode.

## Purpose

Commander should run routine project delivery workflows. Tom should choose work,
set priorities, explain state, and handle exceptions that require judgment. Tom
should not manually compose prompts or route every successful agent result.

The first project-specific workflow is Orbit. The design must also support other
projects with different phases, commands, and receipt rules.

## Outcome

For an Orbit issue, Commander can:

1. prepare a worktree through the repository's trusted scripts;
2. start the correct Herdr agent with a prompt owned by the workflow;
3. correlate Herdr status events with the delivery and phase that dispatched it;
4. validate the agent's receipt and repository state;
5. advance, retry, wait, or raise an exception from that result;
6. create and land an approved pull request through deterministic adapters;
7. enqueue post-merge main refresh and cache maintenance; and
8. expose its state and reasoning to Tom over MCP.

The normal path advances without a Tom wake-up. Commander informs Tom when a
decision, repair, or policy exception is required.

## Implementation status

Commander now owns the durable Orbit path from verified preparation through
planning, independent plan review and correction, implementation and correction,
pull request publication and review, deterministic merge, merge-lineage
verification, primary checkout reconciliation, cache refresh enqueueing, and
Herdr worktree-workspace shutdown.

Workspace shutdown is a persistent landing stage. It targets only the workspace
ID recorded by the delivery, accepts only ledger-owned idle or done agents,
records exit and close intent before sending input, verifies idle shells, and
checks that no unrelated Herdr workspace, agent, or pane disappeared. It supports
the installed protocol 20 close request and sends the explicit `close_group:
false` guard on protocol 22 and newer.

The remaining landing slices are proof-topology closeout where required,
repository-owned worktree removal and branch absence verification, Linear `Done`
transition with ownership clearing, and the final Commander `Completed`
transition. The normal `bin/loop ISSUE` entry point still invokes the legacy
controller; route it through Commander only after those closeout stages pass.

## Ownership boundaries

### Commander

Commander owns workflow state, phase transitions, prompts, dispatch correlation,
receipt validation, retries, locks, audit history, and exception routing.

### Herdr

Herdr owns agent processes, panes, and terminal sessions. A Herdr status event is
a wake signal. It is not proof that a phase succeeded.

### Project repositories

Repository scripts remain the trusted execution adapters for worktree creation,
dependency setup, focused and project checks, topology management, pull request
preparation, landing, cache publication, and cleanup. Commander calls typed PHP
blocks that wrap these scripts and records the command result.

Project configuration selects registered blocks and options. It must not contain
arbitrary shell commands.

### Tom

Tom owns work selection and priority, user-facing explanations, and exceptions
that cannot be decided from policy and validated receipts. Tom consumes
Commander state through MCP and must not maintain a second workflow state.

## Project configuration

Store execution configuration in one JSON `config` column. Hydrate it into a
project-specific DTO with `spatie/laravel-data`. Store JSON rather than serialized
PHP objects.

The existing shared-knowledge manifest remains the project registry and source
for human-facing metadata. A Commander project orchestration record links to its
stable manifest project ID and adds the execution config.

```json
{
    "type": "orbit",
    "repository": "/home/nckrtl/orbit",
    "worktreeRoot": "/fast/worktrees/orbit",
    "herdrSession": "orbit",
    "concurrency": 3,
    "defaultFlow": "discovery"
}
```

Start with these PHP boundaries:

- `ProjectConfig` defines the common project type contract.
- `OrbitProjectConfig` is a strict data DTO for Orbit.
- `ProjectConfigRegistry` resolves and validates DTOs by `type` when a project
  is configured.
- Eloquent stores the validated config as a plain JSON array.

Do not put live delivery state, receipts, event cursors, locks, or agent IDs in
the config column.

Project config is live. An edit applies to active deliveries the next time they
advance. Each advancement validates and reads config once, so a concurrent edit
applies on the following advancement. Add config schema versions and delivery
snapshots only when an actual compatibility requirement appears. Workflow
versions remain separate because they identify executable transition behavior.

## Persistent model

Use separate records for data that must be queried, locked, retried, or audited.
Names may be adjusted to match existing Commander conventions.

### `project_orchestrations`

- manifest project ID, unique;
- config JSON;
- enabled or paused state;
- timestamps.

### `deliveries`

- project orchestration ID;
- external issue provider and stable issue ID/key;
- workflow type and version;
- status and current phase;
- branch, worktree path, candidate SHA, and pull request identifiers when known;
- timestamps and completion/failure details.

Only one non-terminal delivery may exist for the same project and external issue.

### `phase_runs`

- delivery ID, phase name, and attempt number;
- status;
- structured input and output;
- start, finish, and failure data.

The tuple of delivery, phase, and attempt is unique.

### `agent_dispatches`

- phase run ID and agent role;
- Herdr session, workspace, pane, and agent identifiers;
- prompt name/version or hash;
- status and dispatch/settled timestamps.

This table is the correlation boundary between a Herdr event and a delivery.

### `receipts`

- phase run ID;
- receipt kind and schema version;
- immutable structured payload;
- candidate SHA when the receipt is candidate-bound;
- validation status and validation errors;
- capture timestamp.

Receipt validation checks structure, repository facts, and required command
results. The agent's prose judgment is data, not automatic authority.

### `external_events`

- provider and provider event ID, unique;
- event kind and payload;
- correlated delivery/dispatch when known;
- received, processed, and failed timestamps.

Persist before processing so webhook retries and process restarts are safe.

### `maintenance_runs`

- project orchestration and optional delivery ID;
- maintenance kind, status, and attempts;
- structured results and timestamps.

Post-merge main refresh and cache warming belong here. Cache availability is an
optimization and does not rewrite the delivery's reviewed result.

## Workflow composition

Define workflows in PHP. A project config chooses a registered workflow and
supplies data; it does not describe an executable graph in YAML or JSON.

```php
interface DeliveryWorkflow
{
    public function initialPhase(Delivery $delivery): Phase;

    public function nextPhase(Delivery $delivery, ValidatedReceipt $receipt): Transition;
}
```

Each phase uses small reusable blocks with typed inputs and outputs. Initial
blocks include:

- `CreateWorktree`;
- `PrepareDependencies`;
- `SeedCaches`;
- `RunProjectCheck`;
- `PrepareLoopWorkspace`;
- `StartHerdrAgent`;
- `AwaitAgentSettlement`;
- `CollectReceipt`;
- `ValidateReceipt`;
- `CreatePullRequest`;
- `PublishReview`;
- `MergePullRequest`;
- `QueueMainRefresh`;
- `RemoveLoopWorkspace`; and
- `RemoveWorktree`.

Blocks should call existing repository scripts where those scripts already own
the behavior. They return structured results and never decide the next business
phase themselves.

Orbit's initial discovery workflow is:

```text
prepare worktree
  -> preflight planning
  -> preflight review
  -> implementation
  -> implementation review
  -> changes requested -> implementation (repeat)
  -> approved
  -> merge
  -> enqueue main refresh/cache maintenance
  -> clean local loop state and worktree
  -> complete
```

The proof flow is an explicit Orbit workflow variant. Discovery remains the
default. Incus capability and proof mode are separate inputs: an issue may use
Incus discovery without enabling proof.

`.loop` may remain a local adapter surface for existing repository tools and
agent receipts during migration. Commander is the authoritative workflow
ledger. `.loop` must stay outside the approved merge result and be removed by
the landing adapter before merge when repository policy requires it.

## Advancement and idempotency

Use one queued `AdvanceDelivery` job as the entry point for routine progress.

1. Acquire a per-delivery lock.
2. Reload the delivery, current phase, dispatch, events, and receipts.
3. Return successfully if the observed event or completed transition was already
   handled.
4. Collect and validate the expected receipt and repository facts.
5. Persist the completed phase and transition in one database transaction.
6. Dispatch the next external action after commit.

Each block receives an idempotency key derived from delivery, phase, and attempt.
External creations such as Herdr sessions and pull requests record their remote
identifier before another attempt can create a duplicate.

Use a short project landing lock only around the merge operation and its required
main-freshness checks. Queue cache maintenance after the merge. Cache warming
must not keep an otherwise eligible delivery in the review queue.

A scheduled reconciliation job is the fallback for missed Herdr webhooks,
crashed queue workers, and incomplete external calls. It inspects non-terminal
deliveries and queues `AdvanceDelivery`; it does not implement a second state
machine.

## Herdr integration

The existing listener already stores `HerdrEvent` records. Change its normal
delivery path as follows:

1. store the raw event once;
2. correlate the pane to an `agent_dispatch`;
3. queue `AdvanceDelivery` for the related delivery when the pane settles;
4. mark the event processed only after correlation and queueing succeeds; and
5. notify Tom only when no dispatch can be correlated, receipt validation fails,
   retries are exhausted, or policy requires a decision.

Commander must create the Herdr session or pane and persist the returned IDs.
The phase owns the prompt template. Tom does not supply a resume prompt on the
normal path.

The first implementation should preserve the current Tom webhook behind a
feature flag or exception notifier until the new correlation path has been
observed in shadow mode.

## Receipt contract

Define versioned receipt DTOs for at least:

- preflight plan ready;
- preflight review complete;
- implementation ready for review;
- implementation review complete;
- changes applied;
- merge complete; and
- maintenance complete or failed.

Common fields include delivery ID, issue key, phase, attempt, outcome, worktree,
head SHA, produced artifact paths, commands with exit codes, and a concise agent
summary. Reviewer receipts also identify the reviewed SHA and contain structured
findings and a verdict.

Validation must distinguish:

- malformed or missing receipt;
- stale receipt from another phase or attempt;
- candidate SHA mismatch;
- failed deterministic check;
- reviewer changes requested; and
- infrastructure failure.

These results lead to different transitions and explanations.

## MCP for Tom

Start with authoritative read tools:

- `list_projects`;
- `get_project_config`;
- `get_project_status`;
- `list_active_deliveries`;
- `get_delivery`;
- `get_delivery_timeline`;
- `get_next_eligible_issue`;
- `explain_delivery_wait`;
- `get_maintenance_status`; and
- `get_resource_usage`.

Later add narrow mutation tools such as `start_delivery`, `pause_delivery`,
`retry_delivery`, and `set_project_capacity`. Mutations must call the same
application services as internal jobs. MCP tools must not implement transitions
or edit workflow rows directly.

## Delivery states

Use explicit, queryable states. A practical initial set is:

- `queued`;
- `preparing`;
- `waiting_for_agent`;
- `validating_receipt`;
- `waiting_for_changes`;
- `ready_to_merge`;
- `merging`;
- `landed`;
- `cleaning`;
- `completed`;
- `blocked`;
- `failed`; and
- `paused`.

The phase identifies what work is occurring. The status identifies how Commander
is currently handling it. Avoid encoding every phase/status combination as a
separate enum value.

## Implementation sequence

### 1. Configuration foundation

- Add `project_orchestrations` with plain JSON config.
- Add `ProjectConfig`, `OrbitProjectConfig`, and a small validation registry.
- Add focused tests for hydration, validation, unknown types, and live updates.
- Expose read-only config and project status through MCP.

### 2. Delivery ledger

- Add deliveries, phase runs, dispatches, receipts, external events, and
  maintenance runs.
- Define enums and database constraints for terminal and active states.
- Add a timeline query that combines these records without mutating them.

### 3. Workflow kernel

- Add `DeliveryWorkflow`, phase, transition, block, and block-result contracts.
- Implement locking, idempotency keys, and `AdvanceDelivery`.
- Prove duplicate jobs and repeated events do not duplicate external actions.

### 4. Herdr correlation

- Persist the IDs returned when Commander starts an agent.
- Correlate the existing listener's events to dispatches.
- Collect and validate a harmless test receipt when a test agent settles.
- Run in shadow mode while the existing Tom notification remains available.

### 5. Orbit preparation and review loop

- Wrap Orbit's existing worktree and loop scripts in typed blocks.
- Encode prompts and receipt schemas for planning, preflight review,
  implementation, and implementation review.
- Advance changes-requested reviews back to the existing implementer dispatch.
- Keep proof opt-in and discovery as the default.

### 6. Landing and maintenance

- Require the exact approved head and valid local quality receipt.
- Serialize only the merge-critical section.
- Enqueue main refresh and cache maintenance after merge.
- Surface maintenance failure as a separate repair concern.
- Remove local loop state and the merged worktree after required landing steps.

### 7. Tom cutover and observability

- Add the remaining read MCP tools and concise exception payloads.
- Show active deliveries, waits, attempts, and maintenance in Commander.
- Stop routine idle/done webhook delivery to Tom after shadow-mode parity is
  verified.

## Initial acceptance criteria

The first end-to-end Orbit slice is complete when:

1. Commander starts one delivery from a registered Orbit project config.
2. It creates a worktree by calling the existing adapter and records its path.
3. It starts a Herdr agent with a workflow-owned prompt and records the returned
   identifiers.
4. A repeated Herdr settled event produces only one phase transition.
5. A valid receipt advances the delivery without Tom intervention.
6. A missing, stale, or invalid receipt raises one actionable exception without
   advancing.
7. Tom can use MCP to see the delivery, its timeline, current wait, and the
   reason intervention is needed.
8. Existing Herdr monitoring and project registry behavior remains covered.

The slice does not need to merge a real pull request. Add merge and maintenance
only after dispatch correlation and idempotent advancement are reliable.

## Verification strategy

Use focused Pest tests for config validation and live updates, state transitions,
idempotency, event correlation, receipt validation, retries, and locking. Use
fakes around Herdr and repository adapters. Add one integration test that runs a
delivery through multiple phases with repeated events.

Add browser coverage only when a user-visible delivery interface is added. MCP
tools require feature tests for schemas, authorization, and structured output.

## Discovery required before coding

The implementation thread should confirm these facts from the current systems:

- the Herdr RPC methods and response identifiers for creating sessions, panes,
  and prompts;
- the current Orbit script contracts and receipt files;
- the stable issue identifier and event source for starting a delivery;
- whether the existing project registry work will introduce a database-backed
  project model that this plan should extend; and
- which current Tom webhook failures and retries must be preserved during
  shadow mode.

These checks may refine names and adapters. They should not change the ownership
boundaries or create a second workflow engine.

## Discovery findings

Discovery was completed against Commander, Herdr 0.9.0, Orbit's accepted
delivery contracts, and the installed Orbit delivery controller on 2026-09-10.

### Herdr control and correlation

Herdr's bundled schema reports protocol 22. Commander can continue to use its
newline-delimited JSON socket client and add typed adapters for these methods:

- `worktree.open` opens the repository-created checkout and returns the
  workspace, tab, root pane, worktree, and `already_open` state;
- `pane.split` returns the new pane;
- `agent.start` returns agent data including the workspace, tab, pane, terminal,
  name, agent session, status, revision, and state-change sequence; and
- `agent.prompt` returns the resulting agent data after submission.

Commander must call Orbit's `bin/worktree-create` before `worktree.open`.
Herdr's own `worktree.create` must not replace the repository adapter.

The current Orbit controller uses deterministic agent names, records a dispatch
before startup, and stops when startup is ambiguous or a retained worker is
missing. Preserve those rules. A timeout does not prove that `agent.start` or
`agent.prompt` failed before creating external state.

Herdr event envelopes contain `event` and `data`, but no provider event ID. For
Herdr, `external_events.provider_event_id` is therefore nullable and the local
event row is the ingestion identity. Provider IDs remain uniquely constrained
when a provider supplies one. Repeated Herdr events may produce separate raw
event rows, but the unique dispatch settlement and transactional phase
transition must make advancement idempotent. The listener may enrich a
correlated event with the agent's `state_change_seq`; it must not claim that
value was present in the original event.

### Orbit repository contracts

`bin/worktree-create ISSUE --flow=discovery` requires an uppercase Linear key,
a clean primary checkout on `main`, and a fast-forwardable `origin/main`. It
creates or reuses the issue branch and registered worktree, initializes the
ignored `.loop` workspace, bootstraps dependencies and compatible caches, queues
newer cache maintenance without waiting, and prints the worktree path on its
last output line.

Orbit's current controller receipts are immutable JSON objects keyed by a
random dispatch ID. Schema 1 records the issue key, dispatch, agent, phase,
result, candidate SHA, handoff, artifact SHA, Builder gate receipt when
required, and creation time. Planning receipts validate the saved plan
artifact. Implementation and review receipts validate the exact candidate,
artifact, clean worktree, complete pull request body, and Builder gate.
`bin/review-check` writes the candidate-bound Builder receipt below the Git
common directory. `.loop/runtime` contains mutable session, dispatch, prompt,
and receipt files and is excluded from published candidate artifacts.

Stages 1 through 4 use a harmless test receipt and do not wrap the full Orbit
preparation or review loop yet. Stage 5 will add typed wrappers around these
existing contracts.

### Delivery identity and start event

Orbit uses Linear's UUID as the stable external issue ID and an uppercase key
such as `ORB-234` as its human-facing key. The current start event is an explicit
`bin/loop ORB-234` invocation after Tom has selected eligible work. The existing
controller verifies Tom's delegation, issue state, blockers, children, and
readiness before it creates a worktree.

The shadow slice records `provider=linear`, the Linear UUID, and the issue key,
but does not take over Linear mutation or routine `bin/loop` dispatch. Tests and
an internal application service start the harmless delivery. After the slow
worktree and candidate checks, Commander fetches the issue again, verifies that
the retained snapshot bytes are unchanged, and compares the current issue to
the recorded contract before it creates the delivery. Contract schema 2 uses
the installed Python controller's JSON serialization and contract fields so the
two implementations produce the same hash.

Commander also acquires the installed controller's non-blocking per-issue lock
at `<git-common-dir>/orbit-delivery/v1/<lowercase-issue-key>/controller.lock`
before the first Linear fetch. It holds the lock through worktree preparation,
the second fetch, verification, and delivery creation. Any existing `state.json`
or `worker.json` stops the start, including completed or orphaned legacy state.
Commander does not delete or modify those legacy files. Once Commander releases
the lock, the registered issue worktree remains the durable signal that makes a
later legacy start stop at its existing-worktree check.

This verification timestamp is audit evidence, not reusable authorization for
a later planning dispatch. Live planning must repeat the provider and candidate
checks at its own dispatch boundary. A later cutover will route the existing
driver entry point through the same start service after those authorization and
eligibility adapters exist.

The next shadow boundary is `PrepareOrbitPlanningHandoff`. It reloads the
delivery and live project config, acquires the same per-issue controller lock,
and verifies the exact registered issue worktree, exact lowercase issue branch,
candidate and tree, clean and conflict-free state, discovery flow, startup
quality receipt, and retained issue snapshot. Only after those repository
checks does it fetch Linear again and compare the current schema-2 contract. It
returns the fresh normalized issue payload and all verified repository bindings
in a readonly handoff.

This handoff is deliberately non-runnable. It does not create an agent dispatch,
queue advancement, call Herdr, change workflow state, or mutate Linear. An
eligible `Todo` issue can produce the handoff, but the handoff remains marked
non-dispatchable. Live planning still needs a separate Linear transition to `In
Progress`, a read-back, and the same final verification immediately before the
prompt is submitted.

The Linear transition is now isolated behind `OrbitIssueTransitioner`; the
read-only `OrbitIssueProvider` contract remains unchanged. The SSH adapter
resolves exactly one team state named `In Progress`, sends only that state
mutation through the installed Hermes controller RPC, and always performs an
authoritative read-back. A lost or failed mutation response is accepted only
when the read-back proves the exact state ID, name, type, unchanged ownership,
and unchanged planning contract. This primitive is not yet wired to a delivery
or dispatch, so it cannot mutate Linear until the durable live-planning stage
explicitly invokes it.

The live ledger identity is `orbit-feature` version 1, with `planning` as its
initial phase. `StartOrbitDelivery` validates an enabled discovery-mode Orbit
config and records the same verified issue snapshot,
candidate receipt, worktree, branch, candidate, and tree used by shadow
preparation. It creates no dispatch, job, Herdr process, or Linear mutation.
It leaves the delivery in the explicit non-runnable `preparing` status, which
the generic advancement action ignores. The existing shadow start remains
unchanged. This gives the live planning path
its own real `Delivery` and `PhaseRun` instead of adding a second planning-only
ledger beside the workflow kernel. Activation waits for Commander-owned
planning receipt validation and the durable prompt stage.

### Project registry boundary

The shared-knowledge project registry is currently file-backed. Its stable ID
is the validated lowercase directory slug, and no database-backed project model
exists. `project_orchestrations.manifest_project_id` is therefore a unique
string validated against `SharedKnowledgeProjectRepository`, not a foreign key.
A future project model can add a foreign key without changing delivery identity
or copying human-facing manifest data into Commander.

### Tom webhook compatibility

The current listener writes a derived `HerdrEvent`, sends Tom a synchronous
HMAC-signed request with a 10-second timeout and three 250-millisecond retries,
then records `notified_at`. A final failure leaves the event unnotified and is
logged by the listener; no queue retry currently replays it. The request uses
the local Herdr event ID for `X-Request-ID` deduplication.

During shadow mode, keep this path enabled by default and make orchestration
capture additive. Correlation or queueing failure must not suppress the existing
Tom notification. The new raw `external_events` ledger is authoritative for
workflow processing; `herdr_events` remains the compatibility record until
shadow-mode parity permits cutover.

### First-slice construction order

Implement the reviewable slice in this order:

1. Add configuration DTOs, a validation registry, orchestration record, and
   read-only MCP tools.
2. Add portable ledger migrations, string-backed enums, relationships, database
   constraints, and a deterministic timeline query.
3. Add a small test workflow and `AdvanceDelivery` with a cache lock, database
   transaction, explicit after-commit dispatch, bounded retries, and durable
   idempotency keys.
4. Extend the Herdr fake with `worktree.open`, `pane.split`, `agent.start`, and
   `agent.prompt`; persist their returned identifiers before waiting.
5. Capture and correlate settled events in shadow mode, validate the harmless
   versioned receipt, and prove repeated jobs and events advance only once.
6. Expose delivery, timeline, current wait, and resource state through thin
   read-only MCP tools. Do not add a delivery UI in this slice.

Use separate interfaces only at external boundaries such as Herdr and receipt
or repository verification. Internal workflow services remain small concrete
classes. Queue work must not wait for an agent, and its timeout must remain below
the configured database queue `retry_after` value of 90 seconds. Because test
queues are synchronous and test cache uses the array store, database uniqueness
is the final idempotency defense; a shared-store concurrency test is required
before claiming cross-process lock coverage.

## Explicit non-goals for the first slice

- a generic visual workflow editor;
- executable workflows stored as YAML or JSON;
- arbitrary shell commands stored in project config;
- replacing Herdr as the agent runtime;
- replacing repository scripts that already provide stable adapters;
- sending every successful transition to Tom; and
- making cache warmth a delivery correctness gate.
