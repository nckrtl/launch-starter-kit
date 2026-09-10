# Project delivery orchestration

Status: proposed implementation plan

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
    "version": 1,
    "repository": "/home/nckrtl/orbit",
    "worktreeRoot": "/fast/worktrees/orbit",
    "herdrSession": "orbit",
    "concurrency": 3,
    "defaultFlow": "discovery"
}
```

Use these PHP boundaries:

- `ProjectConfig` defines the common type and version contract.
- `OrbitProjectConfig` is a strict data DTO for Orbit.
- `ProjectConfigCast` decodes JSON, resolves the DTO by `type`, applies version
  upcasters, and validates the result.
- `ProjectConfigRegistry` maps known type names to DTOs, upcasters, and workflow
  definitions.

Every config has a schema version. Add an upcaster when a stored shape changes.
Do not put live delivery state, receipts, event cursors, locks, or agent IDs in
the config column.

Snapshot the relevant config type and version when a delivery starts. An edit to
project config must not silently change the meaning of an in-flight delivery.

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
- config snapshot needed to preserve in-flight behavior;
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

- Add `project_orchestrations` with its versioned config cast.
- Add `ProjectConfig`, `OrbitProjectConfig`, registry, and upcaster boundary.
- Add focused tests for hydration, validation, unknown types, and upcasting.
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

Use focused Pest tests for DTO casting, config upcasting, state transitions,
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

## Explicit non-goals for the first slice

- a generic visual workflow editor;
- executable workflows stored as YAML or JSON;
- arbitrary shell commands stored in project config;
- replacing Herdr as the agent runtime;
- replacing repository scripts that already provide stable adapters;
- sending every successful transition to Tom; and
- making cache warmth a delivery correctness gate.
