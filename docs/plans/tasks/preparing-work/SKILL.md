---
name: preparing-work
description: Prepare or revise a feature's sequential Commander task briefs and obtain independent preparation review. Does not implement or start delivery.
---

# Preparing Work

This project-owned guide is not installed for automatic skill discovery. It
supports the opt-in Tasks path; it does not migrate other delivery controllers.
Prepare a project-owned root group with sequential executable children that
fresh agents can implement, in conversation or under a delegated delivery goal.

Commander owns published task briefs and execution state. Use ordinary files
for drafts and evidence, not a competing `.loop` plan. The
[task interface](../../../tasks.md) supports MCP/UI preparation; the
[runtime](../../../task-runtime.md) supports explicit CLI kickoff. Structured
package approval and whole-package import are not implemented. This skill
prepares work; a coordinator separately owns admission and execution.

## Authority

A preparation request alone permits no publication or kickoff. Approval must
cover the exact independently reviewed revision. Normally the user approves it.
If an explicit current goal delegates routine issue maintenance and engineering
approval, record that authority and the coordinator's approval of the revision.
Do not manufacture a new user acknowledgment or repeatedly ask whether to proceed.
Ask about unresolved material product choices or actions outside that authority.
Continue independent authorized work while such a choice is pending.

Read [the task format](references/task-format.md) before drafting a package. For
Orbit, also read [the Orbit rules and adoption boundary](references/orbit.md).

## Establish the work

Identify the project, requested outcome, existing issue if any, and available
repository. Read its agent guidance, relevant decisions, code, documentation,
and checks. Record the actual inspected commit and any relevant local changes.
Do not claim a cached remote-tracking ref is fresh. If current evidence is
unavailable, name the limitation rather than inventing repository facts.

Resolve product behavior, scope, compatibility, and material failure cases with
the user. Ask focused questions when the answer changes the work. Carry forward
confirmed decisions rather than repeating the interview. Leave code-level
choices open when an implementer can safely decide them within the contract.

For an existing issue, compare its outcome and acceptance criteria with the
conversation. Propose changes explicitly; do not silently replace the published
contract. For a new issue, draft the product contract alongside the task package.
An issue can remain unpublished during preparation.

## Build the task package

Write one root group with the feature outcome, scope, shared context, and final
acceptance criteria. Decompose it into the smallest coherent implementation
tasks that need separate assignments. Each executable task has one objective,
bounded surfaces, observable acceptance criteria, verification, and expected
outputs. Include enough root and task references that a worker does not need
this conversation or the whole repository history.

Keep code and its focused tests together where they establish one behavior.
Do not split by file count or create a separate task for every mechanical edit.
Each accepted implementation task should produce one coherent commit that
preserves the supported intermediate state. If that is not possible, revise
the breakdown or describe the required compatibility bridge.

Dependencies connect siblings only: identical project and parent. Define one
strict child order per group. The first child has no dependency; each later
child depends on the previous child. There is no separate preferred-order field
or branching. Cross-group ordering goes through sibling groups. Execution is
sequential in one feature worktree.

Map every feature acceptance criterion to task acceptance or a final integration
check. Include affected documentation and relevant negative or regression cases.
Distinguish checks that exist from tests a task must add. A planned test is not
passing evidence, and an unavailable verification capability is a preparation gap.

Resolve gaps with the user. Do not declare a package ready if a worker would
have to invent product behavior, cross its scope, or choose an unapproved
architecture to finish it. Discovery need not predict every implementation detail.

## Obtain independent preparation review

Check package keys, parentage, dependency scope, coverage, and reference access,
and use the project's applicable preparation validators when available. Report
preparation checks not run separately from tests planned for implementation.
A missing required preparation check prevents claiming the package is validated;
do not apply a legacy plan linter to a new format it does not support.

Submit one exact package revision, the issue draft or current issue, and the
repository evidence to an independent reviewer. That reviewer checks:

- Coverage of the feature outcome and all acceptance criteria, including the
  combined result and affected documentation.
- Feasibility in dependency order, single-objective task boundaries, sibling-only
  acyclic dependencies, and useful verification at each step.
- Consistency with project decisions, exclusions, and current code.
- Whether a fresh implementer has enough context without making product decisions.

The author and helpers who shaped the package cannot provide its independent
verdict. Commander assigns the formal reviewer when that path is available.
Otherwise return a review-request handoff; do not invent a reviewer, approval,
or tool call. This skill does not launch implementers or native worker subagents.

Record the reviewer's verdict and cited findings separately from package content.
Resolve findings in scope; ask the user about changes to product behavior or
scope. Increment the content revision when revising the package, and obtain a
new independent verdict for that revision. Do not repeat unchanged discussion
or add review rounds without a concrete unresolved finding.

## Record readiness and hand off

Present or record the outcome, order, exclusions, verification, independent
verdict, and remaining gaps. Apply the authority rule to this exact revision.
An old plan approval, casual acknowledgment, or permission to draft is not approval.

Without independent review, return a draft awaiting review. Without applicable
approval, return a reviewed proposal. Approved preparation requires a passing
independent verdict, no unresolved gaps, and a recorded authorized approver.

Keep approval, publication, and launch distinct. A coordinator may perform each
only when the user's request or delegated goal covers it. Publish through
supported interfaces and read back exact content and sibling order. Reconcile
partial publication with creation keys before retrying; never fabricate IDs or
write raw database rows. This preparation role does not launch implementers.

## Handoff

Return the package location and revision, inspected baseline, preparation state,
preparation checks, review evidence, approval and its authority, and the next
authorized action. State whether issue or task publication actually occurred.
End the preparation role there; starting implementation is a separate Commander
decision.

Before later execution, Commander must check whether relevant repository or
issue changes invalidate the approved assumptions. Changed contracts or affected
task content require revision, review, and renewed authorized approval; unrelated commits
do not require repeating the entire design session. Preparation approval never
means an implementation task is completed, and a satisfied dependency alone never
makes an unapproved task runnable.
