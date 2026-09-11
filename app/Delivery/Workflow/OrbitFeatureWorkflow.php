<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

final readonly class OrbitFeatureWorkflow
{
    public const string TYPE = 'orbit-feature';

    public const int VERSION = 1;

    public const string INITIAL_PHASE = 'planning';

    public const string PLANNING_AGENT_ROLE = 'planner';

    public const int PLANNING_PROMPT_VERSION = 1;

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
}
