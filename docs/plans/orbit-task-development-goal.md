# Commander task development: Orbit delivery goal

## Execution stopped — owner correction, 2026-09-14

The owner removed this controller from implementation. This saved goal does not
authorize resumption. The current assignment is only to remove stale proof-run
instructions from Linear and Commander.

Do not write proof scripts, run proof topologies, or construct any new topology,
including cold replacements or scenario topologies. If a new owner is explicitly
authorized to finish ORB-91, adjust and validate the existing topology, then
snapshot it. Use focused tests and independent review. Separate proof, capture,
and reacquisition runs are not delivery gates. Preserve accepted evidence without
claiming withdrawn or unrun requirements passed.

## Goal

Make Commander's project task system a reliable, self-driving way to develop
Orbit. Use real Orbit work to complete and improve Commander until it can take a
prepared issue through implementation, review, corrections, verification, merge,
and cleanup with minimal human intervention.

Continue autonomously until I stop you or the completion criteria below are
demonstrably satisfied. Do not stop after each task, merge, or milestone to ask
whether to proceed. Choose and execute the next useful action.

Orbit must be delivered through Commander. Manual interventions may unblock
development, but they must not conceal missing Commander capabilities or count
as proof of automation.

## Own Orbit's delivery queue

Latest owner correction (2026-09-14): we are not using separate proof topologies
or proof scripting for now. This supersedes the older ORB-91 proof-flow and
artifact-gate instructions below. Stop that proof-specific work. No new topology
or scenario construction is authorized. Keep relevant local checks and independent
code review. Do not add proof-only publication, capture or review machinery.
The proof assignment is withdrawn, not passed; this controller remains stopped.
Preserve accepted code, partial fixture work, unrelated resources and ORB-324.
Snapshot behavior remains product scope where relevant; a separate isolated
acceptance-proof topology is not a delivery prerequisite.

Current priority (2026-09-13): this Tasks controller is the sole Orbit issue
orchestrator. Keep the other Commander instance's Orbit orchestration paused;
do not automatically restore it after the repair window. Preserve its code,
agents' work, and delivery history while reconciling and taking over unfinished
Orbit deliveries. Stop any other active Orbit processing safely after identifying
its exact owner and state. Do not stop unrelated project services.

Work on one Orbit issue at a time, as the user directed on 2026-09-13. First
confirm that it addresses a real problem in the supported workflow; cancel or
redirect irrelevant work. Keep its scope and tests proportionate. Finish its
delivery or explicit disposition before selecting the next issue. Reuse valid
existing evidence rather than repeating checks merely for new reports.

Prioritize landing In Progress and In Review work over new admissions and
orchestration expansion. Use small cohesive executable tasks, one objective and
clear acceptance per task. Resolve blockers against current accepted ADRs and
historical evidence. Do not turn transport failures, stale instructions, cache
freshness or duplicated checks into unnecessary product gates. Preserve genuine
correctness holds, exact-candidate review and required machine evidence.

The user's 2026-09-14 correction is explicit: task41 was far too broad and its
five-hour duration was unacceptable. Decompose independent objectives before
admission, while keeping genuinely coupled producer/consumer changes coherent.
Track time to an accepted commit, give evidence-based remaining-work estimates,
and report a slipping estimate with its concrete blocker. Do not describe an
accepted child commit as a landed issue. Keep an active correction bounded;
split untouched pending work through supported interfaces, not by rewriting
active assignments or weakening review to meet an estimate.

Manage Orbit's In Progress, In Review, Todo, and Backlog issues. Reconcile
existing agents, worktrees, pull requests, Commander records, and other active
sessions before taking ownership. Never assign two controllers or competing
writers to the same work.

Reassess each issue before executing it. Use current code, accepted ADRs,
historical decisions, issue discussions, and observed behavior to determine
whether it remains relevant and describes the right direction.

Do not create product requirements from optional coordinator tooling. ORB251 was
canceled at the user's direction because Orbit's supported workflow does not use
JUnit XML. Do not resume it or require its implementation to land other issues.

Implement relevant work. Update outdated requirements, split oversized work into
bounded tasks, and resolve clearly obsolete, duplicate, or superseded issues
with an evidence-backed explanation. Never report cancellation or
reclassification as implementation.

Prefer finishing active work and reviews before starting more work. Agents may
help independently with the current issue, but do not start parallel features.
Prioritize dependencies, delivery blockers, and correctness failures.

The owner's 2026-09-14 speed correction is explicit: Orbit still has one user.
Prefer a clean breaking cutover and an explicitly scoped manual fleet migration
over compatibility infrastructure for old layouts. Do not rebuild test
generations. Preserve real user data, unrelated workloads and
the specifically protected ORB-324 resources. Do not infer cleanup ownership.
Do not add migration automation, exhaustive historical-state support or new
Commander machinery unless a concrete current delivery requires it. For ORB-91,
prioritize a working clean three-sample topology, normal deployment and routes,
repeat-setup preservation, and snapshotting the existing topology.
Use existing checks and accepted implementation; do not spend another large
engineering cycle replacing already-working delivery mechanisms for neatness.
Distinguish requirements removed by the owner from outcomes actually verified.

ORB-91 reconciliation on 2026-09-14: accepted ADR 0073 in current main removes
native-flat production layout adoption. C6/P6's successful adoption experiment
is superseded, not implemented or passed. Linear records this disposition;
retain original Commander briefs and accepted bindings as history. The former
proof-package correction is withdrawn. Preserve clean creation, ownership and
legacy-state refusal, deployment, routing, repeat safety and snapshot reuse.
Do not expand the current integration into removing dormant product APIs.

Address Orbit bugs encountered along the way, including bugs not yet recorded
in Linear. Check for an existing issue; otherwise create one with the observed
and expected behavior, reproduction evidence, and acceptance criteria. Take
responsibility for investigating, fixing, testing, reviewing, and delivering
it, not merely filing it.

Distinguish product defects from environment failures, stale instructions, and
agent mistakes. A workaround does not complete a bug fix. Keep unrelated fixes
in separate tasks and pull requests. Newly discovered bugs become part of this
goal's queue.

## Complete and use Commander

Keep this capability in Commander, using project-owned Tasks and TaskRuns,
reusable integrations, and project-specific PHP flows. Keep orchestration in
Laravel. Use the Laravel MCP package for agent-facing interfaces where
appropriate.

Complete the capabilities needed for reliable delivery: task preparation and
order review, kickoff, worktree preparation, execution visibility, recovery,
integrated review, corrective work, merge, and cleanup. Avoid a separate
platform, visual flow builder, or speculative infrastructure for hypothetical
scale.

Use Commander's supported interfaces for real Orbit work. When intervention
exposes a missing capability, record and address the underlying gap. Do not
force progress by editing runtime records without reconciling actual agent and
Git state.

Keep Orbit's inline agent setup usable. Commander tasks own execution
assignments; do not maintain a competing task list in `.loop`. Preserve required
Orbit evidence and existing delivery contracts until an explicit, verified
migration replaces them.

Coordinate Commander development with its other active session. Preserve
unrelated work. Deploy incompatible runner changes by draining or explicitly
migrating affected runs. Never silently change the meaning of active
assignments or let old and new engines control the same issue.

## Preserve the minimal execution model

Keep implementation sequential within each feature. Prepare bounded tasks with
one objective, explicit acceptance criteria, and a sibling dependency chain.
Keep feature execution sequential too; parallel help is limited to the current issue.

Commander coordinates agents through Herdr:

1. Start a fresh implementer for each task.
2. The implementer implements, verifies, submits work for review, and yields
   without committing.
3. A retained reviewer reviews the feature's tasks. Requested changes return to
   the same implementer within the same TaskRun.
4. After approval, the reviewer creates one clean commit. Commander verifies
   that the commit matches the reviewed changes and expected base before
   accepting the task.
5. Start the next task from the accepted commit.
6. Verify and independently review the integrated feature before completing
   its parent task or merging it.

For a genuine evidence-only child, use the supported exact artifact binding and
independent review without inventing a product commit. Coding children still
follow the reviewer-created commit cycle above.

Commander updates drive transitions. Herdr idle/done may provide wake signals,
but silence or process status is not proof of success. Do not add a mandatory
dual-signal gate without evidence that it is needed.

Bind updates to their actual assignments and review rounds. Reject stale or
conflicting updates. Reconcile uncertain external actions before retrying them.
Keep deterministic safeguards where they prevent concrete races; avoid
speculative coordination complexity.

## Use nodes and agents effectively

Feel free to utilize the Shark and Sabre nodes as you see fit when they can
speed up development or improve quality. The latest one-issue-at-a-time direction
supersedes the earlier permission to implement parallel features. There remains
explicit permission to delegate implementation, investigation, testing,
and independent review to agents and to distribute development and test work
across the available nodes.

Choose agent concurrency within the current issue from actual capacity,
dependencies, and observed results. Use isolated contexts where needed. Preserve one
coordinator per issue, cooperative single-writer ownership within each worktree,
and sequential implementation within each feature. Coordinate integration and
merges so concurrency does not bypass required review or invalidate evidence.

Inspect node ownership and existing workloads before allocating resources.
You may prepare isolated development/test environments, run agents and checks,
and clean up resources owned by this goal. Preserve unrelated sessions, data,
services, and credentials. Node access does not authorize disruptive live-fleet
changes by itself; the owner's separate one-off Orbit migration authority below
applies to necessary feature cutovers, not cleanup of unrelated resources.

Keep Incus resource hygiene current. Reconcile issue/attempt ownership and
remove VMs and worktrees left by completed issues through Orbit's native
closeout and `bin/worktree-remove` commands. Verify resource absence afterward.
Preserve active issue topologies, required retained proof, shared snapshots and
unrelated guests; do not infer ownership from a VM name alone.

You have full Herdr management authority for this goal. Inspect and control
the explicitly identified session, including safely stopping finished workers
and closing their unused panes or workspaces. Recheck current ownership and
occupancy before shutdown. Keep saved transcripts and delivery history. This
does not authorize stopping unrelated agents or the shared Herdr server.

Reduce or redistribute concurrency when resource contention, integration
conflicts, or review load makes delivery slower or less reliable. Do not build
a general distributed platform merely to use another node; add only the
capabilities justified by this delivery work.

## Decide and deliver autonomously

Make routine engineering, prioritization, issue-maintenance, and recovery
decisions yourself. You may assign independent agents, change code and tests,
update project documentation and skills, create pull requests, and merge
independently approved work after required checks pass.

Optimize total delivery time and cost, including rework. Use stronger reasoning
for uncertain planning and review, and cheaper suitable implementers where
observed quality supports it.

Write or revise Orbit ADRs when a durable architectural decision is necessary.
Explain the evidence and tradeoffs, obtain independent review, and land
governing decisions before dependent implementation. Do not use ADRs to silently
expand product scope or weaken acceptance criteria.

You may operate isolated development/test instances and their disposable
resources. The owner's updated goal also authorizes necessary one-off Orbit
fleet migrations to deliver breaking feature cutovers. Resolve the exact owned
targets, inspect the data impact, retain appropriate recovery material, and
verify the migrated result. Prefer a bounded manual migration over building
compatibility infrastructure. This does not authorize unrelated production
deployments, avoidable user-data loss, or changes to protected ORB-324 resources.
If a material product decision genuinely needs me, ask a focused question and
continue other safe, independent work.

## Apply Astra best practices

Audit the project-owned instructions used by Commander and Orbit's active
delivery path: `AGENTS.md`, skills, role prompts, and task instructions. Make
useful corrections, then improve them from observed results. Instruction
cleanup must support delivery, not become an endless redesign or a prerequisite
for every edit.

Use `skill-creator` when creating or updating skills:

- Keep descriptions short and precise about when they apply.
- Keep entrypoints small and use progressive disclosure for conditional detail.
- Remove duplicate, contradictory, obsolete, and generic guidance.
- Prefer outcomes and decision criteria over elaborate itineraries.
- Retain exact procedures where correctness, ownership, permissions, or fragile
  operations require them.
- Keep shared guidance useful for the different models that will consume it.

Replace blanket reading requirements with contextual references. Keep durable
requirements in one maintained place. Avoid duplicating safeguards already
enforced by code or adding universal rules for isolated failures.

Run meaningful affected tests and required delivery checks. Broaden or repeat
verification when changes, failures, or unresolved uncertainty justify it. Avoid
tests that merely enforce wording or mirror implementation.

Simplification must preserve acceptance criteria, independent review, task
ownership, commit verification, and safe recovery. Validate significant
instruction changes through realistic agent behavior, not merely shorter files
or format checks.

If an instruction causes an unnecessary stop or conflicts with the intended
outcome, identify it and correct the project-owned guidance where authorized.

## Persist and prove completion

Continue through implementation, running the result, inspection, corrections,
review, merge, and cleanup. Do not stop at a first implementation, passing test,
proposed plan, or next-step suggestion while authorized work remains.

"Works perfectly" means demonstrated reliability, not endless refactoring.
Completion requires:

- Meaningful automated coverage for task execution, review corrections, stale
  and duplicate updates, recovery, and deployment boundaries.
- Browser tests and real-browser verification for Commander's user-facing flows.
- Orbit's required checks and real Incus evidence where applicable. Setup
  readiness is not feature acceptance.
- Repeated real Orbit deliveries through Commander, including correction and
  recovery scenarios, with one issue delivered at a time.
- Every relevant issue in the target queue, including newly discovered bugs,
  implemented, verified, and merged, or explicitly resolved as obsolete,
  duplicate, or superseded.
- No unexplained stuck runs, unowned blockers, or required delivery steps hidden
  in manual workarounds.

Do not invent optional work merely to remain busy, weaken completion criteria
to finish early, or claim that a blocked issue is resolved.

Maintain a durable record of ownership, decisions, evidence, failures, and next
actions so work can resume safely after interruptions. Give concise progress
updates without waiting for acknowledgment.

Begin by reconciling the current Commander task implementation, other active
sessions, and ORB-242's remaining work. Verify current state instead of assuming
this starting point is still unchanged. Then keep delivering and improving the
system from observed results.
