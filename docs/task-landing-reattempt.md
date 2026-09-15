# Landing evidence after a first-child reattempt

Landing may recognize one audited first-child base transition. It does not change the workspace admission base, replace an accepted commit, or grant permission to land.

This extension depends on the reviewed first-child reattempt runtime, including exact retained-tree refs and local-object-only Git checks. It adds no command, route, provider binding, schema migration, or agent action.

## Eligibility

The normal landing checks still apply: exclusive ownership, approved task scope, accepted task reviews and commits, successful final checks and final review, current issue identity, the exact Builder receipt, frozen artifact inputs, and separate package approval.

If the first accepted run uses the admission base and has no checkpoint marker or reattempt records, the original package fields, field order, serialization, and hash behavior remain unchanged. No null reattempt fields are added.

A changed first-run base is accepted only when all these facts agree:

1. Exactly one workspace checkpoint and one cutover audit bind the original first-child attempt and its replacement. They are the first two root runs and workspace dispatches. The original attempt is 1; the accepted replacement is 2. Recovered roots are excluded.
2. The original acknowledged round-zero implement receipt remains blocked and unchanged. The original run retains its admission base, inputs, and identities. It has no review or accepted commit and has only the exact audited incomplete/superseded result. Its checkpoint state was still running, without an earlier result, failure, or finish time.
3. The replacement retains the worker and reviewer identities, exact checkpoint input, and idempotency key. Its acknowledged round-zero implement dispatch has the exact step key and a fresh valid token. Stable Herdr/native conversation identity cannot change. Runtime status and sequence metadata may change.
4. The admitted workspace assignment, configuration, and original manifest remain intact. Later final continuations must retain a contiguous, hash-consistent history that reconstructs the original manifest. Only the existing append-correction and final-check-retry modes are recognized.
5. Checkpoint and restoration requests are exactly normalized. Their request hashes use the reattempt runtime's JSON encoding and hash equations. Independent-review, exact-main verification, and restoration evidence files remain readable, bounded, outside the worktree, and equal to their recorded hashes.
6. Both direct refs under `refs/commander/task-reattempts/CHECKPOINT_REQUEST_HASH/` still identify the exact preserved `tree` and `index_tree`. Missing, rebound, symbolic, or non-tree refs fail closed.
7. The prerequisite has the exact reviewed candidate as the second parent of its two-parent merge. Its merge tree is the conflict-free merge tree. Both the merge and the admission base are ancestors of the historically verified main commit.
8. The preserved working and staged deltas each apply cleanly onto that verified base. Their recomputed trees equal the audited cutover trees. Branch, Git directory, common directory, and registered worktree assignment remain unchanged.
9. Every accepted commit has exactly one actual Git parent: the proven new base for the first child, then the preceding accepted commit. Each commit's actual tree equals its passing reviewed tree. The current clean HEAD, index, and working tree equal the final accepted candidate.

The resumed implementer may finish work beyond the initial restored cutover tree. The cutover tree is not substituted for the final passing review tree.

## Evidence and guards

Only the reattempt path adds `database.reattempt` and `reattempt_proof` to the existing schema-1 input object. They record sanitized ledger provenance, hash-bound requests and observations, the retained ref map, restoration trees, accepted commit headers, and final-continuation history. Raw prompts, tokens, original input values, and runtime configuration are not exported by these additions. The existing secret guard still checks the complete package.

The database projection and local Git proof are checked during capture and checked again by the existing package guards, including under the workspace runtime lock. The checks do not fetch, prompt agents, contact issue services, repair refs, change the source index, or write source objects. Restoration and worktree observations use disposable indexes/object directories. Partial/promisor repositories fail closed, and Git reads set `GIT_NO_LAZY_FETCH=1`.

Landing validates historical prerequisite evidence. It does not require today's `origin/main` to remain at the historical main commit. The original checkpoint and cutover operations already required that exact remote readback. Later ordinary main advancement is not a new base override.

The hash-bound prerequisite reports are trusted coordinator evidence, not a fresh PR-provider attestation or a claim that this extension ran those commands. Referenced reports are checked but are not automatically embedded; the existing explicit `evidence_files` mechanism controls archival. Existing retention and cleanup limits remain unchanged.

## Refusal and scope

Missing or inconsistent evidence refuses packaging. There is no fallback to the first run's base, generic rebase, third attempt, later-child transition, recovered-root transition, automatic repair, or accepted-history rewrite.

This extension does not create a PR, merge it, move an issue, close out a task, clean a worktree, add an Incus gate, or alter the existing final/review/artifact guards. Those operations retain their separate authorization and review requirements.

## Verification

`tests/Feature/TaskLandingReattemptTest.php` builds disposable local repositories and real checkpoint/cutover records, completes two accepted children, and exercises both final-continuation modes. Agents, issue reads, queue dispatch, and artifact publication are faked. HTTP stray requests are blocked and the database is in memory.

Coverage includes exact ordinary-package compatibility; successful audited reattempt packaging; old-history preservation; missing or corrupted ledger records, evidence files, refs, Git objects, prerequisite merges, assignments, review output, and actual commit parents/trees; guard-time drift; recovered-root refusal; and retained final-continuation history. No browser validation is needed: this is backend/CLI-only evidence validation with no page, component, or HTTP endpoint change.
