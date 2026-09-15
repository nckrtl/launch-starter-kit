---
name: creating-issues
description: Use in Commander for the registered Orbit project when asked to draft, create, or update an Orbit Linear issue from defined requirements.
---

# Creating Issues

Read the [Orbit project guide](../../AGENTS.md) and use its selected checkout and issue board.

Turn defined work into a clear Linear issue. This skill owns issue structure, fields, validation, and publication.

Read the request, affected architecture, code, tests, and existing issues. Use Orbit’s `grill-with-docs` when the feature needs shaping. For an existing issue blocked by unclear requirements, use [resolve-pipeline-issues](../resolve-pipeline-issues/SKILL.md) to diagnose and settle them.

## Describe the work

Use [template.md](template.md) for the outcome, scope, and acceptance criteria. Each `Proof:` entry names a test, command, or machine observation that demonstrates the criterion.

Link the feature’s proposed ADRs.

Keep each issue focused on one outcome. Split independently deliverable work and record real dependencies. A parent groups its child issues.

Set the Linear fields that apply.

| Field | Content |
| --- | --- |
| Type | Feature, Improvement, or Bug |
| Components | Affected Composer projects |
| Documentation | `docs` label when maintained pages change |
| Machines | `incus` label for behavior that needs machine verification |
| Decisions | Links to accepted and proposed ADRs, with their status |
| Dependencies | `blocks` and `blocked by` relationships |
| Source | Request, report, or related issue |
| Status | Backlog for unresolved requirements; Todo when the work is ready to implement |

Use `Readiness` to explain unresolved behavior, scope, or verification needs. Remove it when these are settled. Track unfinished prerequisite issues through dependency relations.

## Check and publish

Save the draft outside the repository. Run `composer issue:lint -- <file>` in `apps/docs`; use `--parent <file>` for a parent issue.

Publish within the user's authorization. Check the current issue before updating it, preserve useful history, and read back the saved result. Report the issue link and any remaining questions.
