---
name: resolve-pipeline-issues
description: Use in Commander for the registered Orbit project when asked to resolve unclear requirements or verification blockers in Orbit Linear issues, individually or across Blocked and Backlog.
---

# Resolve Pipeline Issues

Read the [Orbit project guide](../../AGENTS.md) and use its selected checkout and issue board.

Diagnose what prevents an existing issue from being ready to implement, then settle those gaps. Use [creating-issues](../creating-issues/SKILL.md) for issue structure and publication.

## Select the work

For a named issue, work on that issue. For the whole backlog, handle one issue at a time: Blocked first, then Backlog. Prioritize prerequisites that unlock other work and refresh the queue after each update.

When assigned to give advice only, inspect the named issue and return a proposal with recommendations for open decisions.

## Understand the problem

Read the current issue, comments, related issues, ADRs, documentation, code, and tests. Check whether the reported problem still exists.

Introduce the issue by ID and title. Explain what it delivers, what remains unresolved, and your recommended solution with its main trade-off.

Separate unclear requirements from unfinished prerequisites. Put prerequisite issues in `blocked by` relations. A complete issue can be Todo while those dependencies remain unfinished.

## Resolve and update

Find repository facts yourself. Use Orbit’s `grill-with-docs` to settle feature choices and prepare their documentation. For a failed main check or cache refresh, use [maintaining-monorepo](../maintaining-monorepo/SKILL.md) to diagnose the failure.

Summarize the agreed outcome, scope, acceptance criteria, decisions, and verification. Use [creating-issues](../creating-issues/SKILL.md) to draft, lint, and publish the update within the user's authorization.

Move the issue to Todo when its requirements and verification are clear. Remove the resolved `Readiness` section and preserve useful comments and dependency relations.

For an advisory assignment, return the recommended issue changes, supporting evidence, and decisions still needed. For a backlog-wide request, continue to the next issue until the queue is resolved, the user pauses, or further work needs unavailable input.
