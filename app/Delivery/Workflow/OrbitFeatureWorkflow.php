<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

final readonly class OrbitFeatureWorkflow
{
    public const string TYPE = 'orbit-feature';

    public const int VERSION = 1;

    public const string INITIAL_PHASE = 'planning';

    public const string PLAN_REVIEW_PHASE = 'plan_review';

    public const string IMPLEMENTATION_PHASE = 'implementing';

    public const string PR_REVIEW_PHASE = 'pr_review';

    public const string LANDING_PHASE = 'landing';

    public const string RESOLUTION_PHASE = 'resolution';

    public const string PLANNING_AGENT_ROLE = 'planner';

    public const string PLAN_REVIEW_AGENT_ROLE = 'plan-reviewer';

    public const string IMPLEMENTATION_AGENT_ROLE = 'implementer';

    public const string PR_REVIEW_AGENT_ROLE = 'pr-reviewer';

    public const string RESOLUTION_AGENT_ROLE = 'resolver';

    public const int PLANNING_PROMPT_VERSION = 1;

    public const int PLAN_REVIEW_PROMPT_VERSION = 1;

    public const int IMPLEMENTATION_PROMPT_VERSION = 1;

    public const int IMPLEMENTATION_CORRECTION_PROMPT_VERSION = 1;

    public function planningPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
    ): string {
        return <<<PROMPT
Plan the implementation for {$issueKey}. Do not implement the feature.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: planning
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: {$worktree}/.agents/skills/planning-features/SKILL.md

Read that process and the assigned worktree guidance. Read the Linear snapshot in
`.loop/issue.json`, including its description, labels, attachments, and relations.
Discover HEAD locally. Dependencies and caches were prepared, and the recorded
startup quality check passed.

Complete only planning. You may coordinate bounded native helpers within this
process, but you must integrate their work and stop them before handoff. Do not
start another delivery role, change Linear or GitHub, merge, or invoke the legacy
Orbit controller's advance command.

Use Orbit's current `bin/plan-lint` and `bin/loop-artifacts save` commands. A plan
ready for independent review must have `Review verdict: PENDING`. Write your
complete handoff as a regular file inside `.loop/runtime/`.

Before ending, run one receipt command from the assigned worktree root.

Ready:
`{$receiptCommand} --result=ready --handoff=.loop/runtime/planning-handoff.md --artifact=FULL_SHA`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/planning-handoff.md`

For ready, replace `FULL_SHA` with the exact SHA printed by the saved artifact
command. The receipt command validates and records your result; it does not choose
your verdict or advance the delivery. Correct a reported structural error before
returning. If planning cannot complete, use `blocked` and include its
classification, evidence, and smallest next action in the handoff. Return the
receipt ID in your final response and stop.
PROMPT;
    }

    /** @param array<string, mixed> $reviewReceipt */
    public function planningCorrectionPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
        array $reviewReceipt,
    ): string {
        $receipt = json_encode(
            $reviewReceipt,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<PROMPT
Correct every finding from the independent plan review for {$issueKey}. Do not implement the feature.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: planning
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: {$worktree}/.agents/skills/planning-features/SKILL.md

Read that process and the assigned worktree guidance. This is the retained Builder;
preserve the existing work, dependencies, caches, and verified prior checks. Correct
the plan and maintained documentation using every finding in this immutable review
receipt:

```json
{$receipt}
```

Complete only the planning correction. You may coordinate bounded native helpers
within this process, but you must integrate their work and stop them before handoff.
Do not start another delivery role, change Linear or GitHub, merge, implement product
code, or invoke the legacy Orbit controller's advance command.

Use Orbit's current `bin/plan-lint` and `bin/loop-artifacts save` commands. The revised
plan must have `Review verdict: PENDING`. Write your complete correction handoff as a
regular file inside `.loop/runtime/`.

Before ending, run one receipt command from the assigned worktree root.

Ready:
`{$receiptCommand} --result=ready --handoff=.loop/runtime/planning-correction-handoff.md --artifact=FULL_SHA`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/planning-correction-handoff.md`

For ready, replace `FULL_SHA` with the exact SHA printed by the saved artifact command.
The receipt command validates and records your result; it does not choose your verdict
or advance the delivery. Correct a reported structural error before returning. If the
correction cannot complete, use `blocked` and include its classification, evidence,
and smallest next action in the handoff. Return the receipt ID in your final response
and stop.
PROMPT;
    }

    /** @param array<string, mixed> $planningReceipt */
    public function planReviewPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
        array $planningReceipt,
    ): string {
        $receipt = json_encode(
            $planningReceipt,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<PROMPT
Independently review the submitted implementation plan for {$issueKey}. Do not implement or rewrite the plan.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: plan_review
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: /home/nckrtl/orbit/.agents/skills/reviewing-feature-plans/SKILL.md

Read that process and the assigned worktree guidance. Review the exact candidate and
planning artifact identified by this immutable planning receipt:

```json
{$receipt}
```

Complete only the independent plan review. Do not edit documentation, product code,
tests, the issue contract, Linear, or GitHub. Use Orbit's current `bin/plan-lint` and
`bin/loop-artifacts save` commands as directed by the review skill. Write your complete
handoff as a regular file inside `.loop/runtime/`.

Before ending, run one receipt command from the assigned worktree root.

Pass:
`{$receiptCommand} --result=pass --handoff=.loop/runtime/plan-review-handoff.md --artifact=FULL_SHA`

Fix:
`{$receiptCommand} --result=fix --handoff=.loop/runtime/plan-review-handoff.md --artifact=FULL_SHA`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/plan-review-handoff.md`

For pass or fix, replace `FULL_SHA` with the exact SHA printed by the saved artifact
command. The receipt command validates and records your result; it does not choose the
verdict or advance the delivery. Correct a reported structural error before returning.
Return the receipt ID in your final response and stop.
PROMPT;
    }

    /** @param array<string, mixed> $reviewReceipt */
    public function implementationPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
        array $reviewReceipt,
    ): string {
        $receipt = json_encode(
            $reviewReceipt,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<PROMPT
Implement the independently approved plan for {$issueKey} in the retained Builder.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: implementing
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: {$worktree}/.agents/skills/developing-features/SKILL.md

Read that process and the assigned worktree guidance. Implement the exact candidate and
approved planning artifact identified by this immutable review receipt:

```json
{$receipt}
```

Complete only implementation. You may coordinate bounded native helpers within this
process, but you must integrate their work and stop them before handoff. Do not change
Linear, create or merge a GitHub pull request, or invoke the legacy Orbit controller's
advance command.

Run the required checks through the retained Builder, commit the implementation, push the
exact issue branch, and publish the exact candidate artifact. Prepare a complete pull
request body as a regular file inside `.loop/runtime/`. Write your complete implementation
handoff there as well. Keep `.loop` untracked.

Before ending, run one receipt command from the assigned worktree root.

Ready:
`{$receiptCommand} --result=ready --handoff=.loop/runtime/implementation-handoff.md --artifact=FULL_SHA --gate=ABSOLUTE_GATE_PATH --body=.loop/runtime/pull-request-body.md`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/implementation-handoff.md`

For ready, replace `FULL_SHA` and `ABSOLUTE_GATE_PATH` with the exact published artifact
SHA and successful Builder gate receipt. The receipt command independently verifies the
clean pushed candidate, artifact, gate, and pull request body; it does not publish the pull
request or advance the delivery. Correct a reported structural error before returning. If
implementation cannot complete, use `blocked` and record its classification, evidence, and
smallest next action in the handoff. Return the receipt ID in your final response and stop.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $implementationReceipt
     * @param  array<string, mixed>  $pullRequest
     */
    public function implementationCorrectionPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
        array $implementationReceipt,
        array $pullRequest,
    ): string {
        $receipt = json_encode(
            $implementationReceipt,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $published = json_encode(
            $pullRequest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<PROMPT
Resolve the published candidate's actual merge conflicts with main for {$issueKey} in the retained Builder.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: implementing
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: {$worktree}/.agents/skills/developing-features/SKILL.md

Preserve the approved plan, completed implementation, and prior acceptance evidence. The
published pull request was verified as unmergeable for this exact implementation receipt:

```json
{$receipt}
```

```json
{$published}
```

Resolve only the real conflicts against current main. Do not restart preflight merely because
main advanced. You may coordinate bounded native helpers within this process, but you must
integrate their work and stop them before handoff. Do not change Linear, create or merge a
GitHub pull request, or invoke the legacy Orbit controller's advance command.

Run the required checks through the retained Builder, commit the correction, push the exact
issue branch, and publish the corrected candidate artifact. Update the complete pull request
body inside `.loop/runtime/` and write the complete correction handoff there. Keep `.loop`
untracked.

Before ending, run one receipt command from the assigned worktree root.

Ready:
`{$receiptCommand} --result=ready --handoff=.loop/runtime/implementation-handoff.md --artifact=FULL_SHA --gate=ABSOLUTE_GATE_PATH --body=.loop/runtime/pull-request-body.md`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/implementation-handoff.md`

For ready, replace `FULL_SHA` and `ABSOLUTE_GATE_PATH` with the exact corrected artifact SHA
and successful Builder gate receipt. The receipt command independently verifies the clean
pushed candidate, artifact, gate, and pull request body. Correct a reported structural error
before returning. If correction cannot complete, use `blocked` and record its classification,
evidence, and smallest next action in the handoff. Return the receipt ID and stop.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $implementationReceipt
     * @param  array<string, mixed>  $pullRequest
     */
    public function pullRequestReviewPrompt(
        string $issueKey,
        string $worktree,
        int $deliveryId,
        int $phaseRunId,
        int $dispatchId,
        string $receiptCommand,
        array $implementationReceipt,
        array $pullRequest,
    ): string {
        $artifactSha = $implementationReceipt['artifact_sha'] ?? null;

        if (! is_string($artifactSha) || preg_match('/^[a-f0-9]{40}$/', $artifactSha) !== 1) {
            throw new \InvalidArgumentException('The implementation receipt has an invalid artifact SHA.');
        }

        $receipt = json_encode(
            $implementationReceipt,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $published = json_encode(
            $pullRequest,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        return <<<PROMPT
Independently review the exact published pull request for {$issueKey}.

Issue: {$issueKey}
Worktree: {$worktree}
Assigned phase: pr_review
Flow: discovery
Delivery: {$deliveryId}
Phase run: {$phaseRunId}
Dispatch: {$dispatchId}
Current process skill: {$worktree}/.agents/skills/reviewing-pull-requests/SKILL.md

Read that process and the assigned worktree guidance. Review the exact candidate, artifact,
Builder gate, pull request body, and published pull request identified below:

```json
{$receipt}
```

```json
{$published}
```

Complete one independent formal review and return every blocking finding in one pass. Use
focused diagnostics only for a concrete uncertainty. Do not edit product code, change Linear,
mutate GitHub, merge, or invoke the legacy Orbit controller's advance command. The external
orchestrator owns review publication and every pull request mutation.

Write the complete review handoff inside `.loop/runtime/`. For approval, also write the complete
final pull request body there, starting from the submitted body and preserving its exact Builder
gate line and evidence bindings. Keep `.loop` untracked.

Before ending, run one receipt command from the assigned worktree root.

Approved:
`{$receiptCommand} --result=approved --handoff=.loop/runtime/pr-review-handoff.md --artifact={$artifactSha} --body=.loop/runtime/pull-request-body.md`

Changes requested:
`{$receiptCommand} --result=changes --handoff=.loop/runtime/pr-review-handoff.md --artifact={$artifactSha}`

Blocked:
`{$receiptCommand} --result=blocked --handoff=.loop/runtime/pr-review-handoff.md --artifact={$artifactSha}`

The receipt command independently validates the unchanged candidate, published artifact,
submitted Builder gate, and review binding. It does not publish the GitHub review or advance the
delivery. Correct a reported structural error before returning. Return the receipt ID and stop.
PROMPT;
    }
}
