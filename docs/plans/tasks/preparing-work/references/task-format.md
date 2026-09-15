# Preparation package and task format

Preparation artifact format, not a whole-package import or approval schema.
Use Markdown for drafts. Preserve these facts for exact-revision review and
publication through the existing individual-task interfaces.
Do not create `TaskRun` records during preparation.

## Package identity and context

Record:

- **Package key:** a stable local identifier, unique within the project. It is
  not a fabricated task database ID.
- **Project:** Commander's existing project slug and the repository inspected.
- **Revision:** increment when issue content, task content, references, checks,
  or ordering changes. Review and approval records refer to this exact revision;
  appending those records does not itself revise the content they assess.
- **Source:** the existing issue identity and inspected contract, or the complete
  proposed issue payload. Include a useful confirmed-design summary without
  making the conversation a required input for implementers.
- **Baseline:** the actual inspected commit, relevant dirty changes if any,
  and the freshness limitations of the evidence. An unresolved code-ownership
  conflict or unverified required source is a gap, not an invented clean baseline.
- **Root group:** one task key identifying the whole feature or bug.
- **Project policy:** references to the applicable architecture and verification
  rules, including any issue-specific environment requirement.
- **Gaps:** unresolved decisions, missing evidence, or unavailable capabilities,
  with an owner and the question or action needed to resolve each one.

All node keys are stable and unique within the package. A future importer maps
the package key and node keys to task IDs and retains that mapping for safe
publication retries. A local key is not an API authorization or execution claim.

A read-only session may return the draft in its response. If the project slug
cannot be verified, label it as proposed and retain that gap; do not claim a
registered identity or create one implicitly. Confirm identity and the retained
package location before approval and publication.

## Root and intermediate groups

For Orbit's "implement feature" flow, the root task represents the complete
feature and its children are implementation assignments. The flow is project
PHP logic, not another task container or a Workflow model. Flow selection is
not yet part of the backend API; do not invent an executable flow ID in a draft.

Every group has a key, title, project, parent key (null for the root), objective,
scope in/out, shared context, child keys, and any sibling dependencies. Use
`kind: group`. Groups organize outcomes; they are not worker assignments.

The root also holds the feature's observable acceptance criteria with stable
keys such as `F1`, and the final integrated verification and review requirements.
An intermediate group inherits the root's contract and may add a narrower
acceptance boundary. Do not duplicate the root brief into every child.

Root final acceptance is distinct from the current backend's check that every
child is complete. Passing individual task reviews is necessary but not enough
to prove the whole feature. Completion means the outcome stated by this root;
landing or release is required only when that outcome includes it.

## Executable task

Use the following record for each implementation assignment:

```markdown
### Task <stable key>: <imperative title>

Kind: executable
Project: <project slug>
Parent: <group key>
Depends on: <previous sibling key, or none for the first child>

Objective: <one observable result>

Scope:

- In: <surfaces and behavior this task changes>
- Out: <adjacent behavior this task must preserve>

Context:

- <relevant root context or decision reference and why it matters>
- <code, documentation, and test references verified during preparation>
- <specific prerequisite outputs this task will consume, if any>

Acceptance:

- <local criterion key>: <observable condition>; covers <feature criterion keys>

Verification:

- <check key>: <criterion keys>; <working directory>; <command or observation>;
  expected <result>; availability <existing, to add in this task, or prerequisite>

Outputs:

- <code/documentation change and focused checks needed for this objective>
- Reviewable uncommitted changes and verification evidence. The reviewer creates
  one clean commit after a pass; the implementer does not commit each round.
- Evidence maps each acceptance criterion to the actual check, working directory
  or environment, observed result and exit status, and retained log reference
  when needed. Failed and unrun checks, setup corrections, and temporary changes
  with their cleanup or remaining state stay explicit.
```

This is a template, not a complete package. Replace its fields with inspected
facts and agreed requirements. Several criteria can support one objective; do
not turn a task into several unrelated objectives to reduce the task count.
An enabling task may protect an invariant rather than directly deliver a feature
criterion; state that invariant and why downstream work requires it.

Verification must identify both the behavior to check and an executable means
of checking it. Name planned new tests honestly and assign their creation to this
task or a prerequisite. If an external environment must exist later, distinguish
known provisioning work from an unresolved capability. The latter prevents
preparation approval. Do not attach fabricated command results to a draft.

References must be discoverable from the assigned repository or published
artifacts. A planner-only absolute path, unstated chat decision, or inaccessible
document does not supply usable worker context. The task assignment later
includes the root brief, relevant ancestor context, this task, and its required
prerequisite outputs; it need not expose every other project's tasks.

## Ordering and coverage

List one strict child order per group. The first child has no dependency; each
later child depends on its predecessor. These links are the execution order,
not a separate preferred-order field. No branches or disconnected children are
allowed. Create children in that order or use the atomic reorder interface.

Dependency targets must exist, be distinct from the source, share its parent
and project, and form no cycle. Children inherit a group's sibling prerequisites.
A child cannot depend on an ancestor, descendant, cousin, or a root outside its
own sibling set. Group boundaries should preserve coherent interfaces rather
than require access to each other's internal tasks.

Give a coverage map from each root acceptance key to its task criteria and/or
final integrated checks. The map must cover the complete outcome, not just the
union of changed files. A final check lists its criteria, working directory,
command or observation, expected result, and required environment. Full-feature
verification is not duplicated as a mandatory full-suite run after every task.

The preparation baseline is evidence for the plan, not the fixed base of every
future commit. Commander selects each TaskRun's actual base from the accepted
worktree state. Initially, one implementer works at a time; the previous accepted
commit is the next task's baseline. The implementer keeps the same session and
TaskRun through correction rounds, handing off uncommitted changes for review.
Each handoff binds its output and full Git tree snapshot to a numbered review
round. Changed content needs a new handoff, not a new TaskRun. The persistent
reviewer requests changes or passes that exact snapshot, then creates one clean
commit after a pass. Commander verifies the commit's tree and sole parent before
completing the task and starting a fresh implementer for the next task. Accepted
commits stay fixed. The reviewer's conversation is not the ledger.

Only one actor may write the feature worktree at a time. Explicit handoffs drive
transitions; Herdr idle/done signals only wake Commander to recheck current state.
They do not select a task, supply a verdict, or grant permission to write.

## Preparation review and approval

Keep records outside the task content with:

- **Preparation checks:** structural and project-specific checks performed,
  their observed results, and any required checks not run. Keep these separate
  from future implementation verification; a planned passing test is not a result.
- **Review:** package key, reviewed revision, actual reviewer identity, verdict
  (`pass`, `revise`, or `blocked`), cited findings, and an accessible evidence
  reference. No independent verdict is recorded before a reviewer supplies one.
- **Approval:** package key, exact approved revision, approver, and authority.
  Cite the user's approval or the current goal delegating routine approval plus
  the coordinator's actual decision. Never call the latter a new user acknowledgment.
  Leave approval absent until it has occurred.
- **Publication:** actual Linear issue and Commander task identities only after authorized,
  verified publication; otherwise explicitly `not published`.

The preparation state is `draft`, `reviewed`, or `approved`: `reviewed` requires
a passing independent review of the current revision; `approved` additionally
requires no unresolved gaps and authorized approval of that revision.
These are preparation artifact states, not current `TaskStatus` enum values.
Content changes return the package to draft; retain earlier reviews and approvals
as history, not authorization for the changed package.

Approval is separate from runtime eligibility, execution status, and external
publication. A worker handing off its changes does not establish task acceptance.
The backend provides an awaiting-review gate and explicit acceptance before
releasing dependents. Runtime CLI handoffs use assignment-bound tokens; the
preparation MCP and UI expose neither those tokens nor lifecycle transitions.

## Storage and ownership

In the target architecture, Commander owns preparation briefs and plans, task
content and revisions, acceptance criteria, dependencies, approvals, execution
state, and review verdicts. Git owns code and durable project documentation.
Linear retains the product issue contract; the package records the exact issue
contract it was prepared against. Do not mirror each implementation task into
a Linear issue unless it is genuinely separate product work.

Larger logs, reports, screenshots, and proof artifacts may live in files or
artifact storage. Attach immutable references to the relevant package revision
or run and, where applicable, the exact commit. Optional scratch files and
read-only exports are not another editable source of truth and cannot mark
tasks approved, ready, or complete.

Draft in conversation or an agreed ordinary document. No `.loop` plan is needed.
Publish through supported MCP/UI actions and read back the briefs and sibling
chain. After publication, revise through Commander while preparation is editable;
keep older artifacts as evidence, not a second editable source of truth.

Existing `.loop` content belongs to the legacy process. Preserve it until its
consumers are deliberately migrated and retained evidence is preserved; it is
not a prerequisite for new task preparation.

The opt-in runner inspects real worktrees and Git trees, verifies reviewer
commits against their accepted base and reviewed snapshot, and requires final
checks and integrated review before completing the root. Corrections stay in
the same TaskRun; each feature permits one active implementer assignment.
Cooperative handoffs are not an OS-level write fence. Structured preparation
approval and whole-package import remain absent. Automatic merge/cleanup and
general recovery are not supplied by preparation. Read the
[runtime capabilities](../../../../task-runtime.md) before admission; draft
approval does not create new interfaces or bypass their requirements.

Individual task briefs can now be created, edited, read, and reordered through
the authenticated MCP tools and project UI described in
[the live task interface](../../../../tasks.md). Those interfaces do not store
this draft's formal package approvals, and creating tasks never launches workers.
