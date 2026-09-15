# Orbit proof-instruction cleanup — 2026-09-14

The owner requested removal of proof-topology execution instructions from every
Orbit issue in Linear and every Commander task. Implementation remains stopped.

## Result

- Read all 347 Orbit issue descriptions, all 345 existing comments, and all 50
  Commander tasks (44 Orbit tasks and 6 unrelated UI demonstration tasks).
- Updated 167 issue descriptions. Removed explicit machine-proof acceptance
  clauses where present and made the current no-proof/no-new-topology direction
  explicit. Closed issue states and recorded outcomes were not changed.
- Edited 22 comments. Linear refused edits to 34 other-account comments; attached
  and read back an explicit withdrawal reply to each. Those original comments
  remain visible as superseded history.
- Corrected 38 Orbit task briefs and all 16 saved Orbit workspace instructions.
- Withdrew task50/run31/dispatch125. No implementation or validation pass claimed.
  Workspace16 holds execution stopped. Its stored profile is discovery with
  snapshot replacement false; no workspace now selects proof.
- Checked the other Commander database: 14 legacy delivery records, no task
  table, no proof-selected phase input, and Orbit orchestration remains paused.
- Corrected current runtime instructions and the goal/checkpoint/related delivery
  documentation so the old procedure is not presented as the next step.

## Current direction

No proof scripts, proof topology runs, cold replacements, new scenario topologies,
or separate capture/reacquisition gates. Keep focused automated tests and independent
review. If an explicitly authorized delivery owner needs machine checks, adjust
and verify the existing topology, then snapshot it when required.

This cleanup does not authorize this controller to resume implementation.

## Verification

All 167 changed issue descriptions were read back; follow-up wording corrections
were read back too. Linear normalized an old NCK issue link to its current ORB
identifier. All 22 edited comments and 34 withdrawal replies were read back.
All 38 changed task briefs match the expected database values; task50 was also
read back through Commander MCP.

SQLite foreign-key checks and quick_check passed. The data correction verified
that accepted task state, all other TaskRuns, and independent review records were
unchanged. Task worker60 is inactive with MainPID 0; active task dispatch count is
zero. PHP syntax checks and git diff --check passed.

No feature code or UI implementation changed; no feature/browser test run was
needed. The only PHP configuration change is agent instruction text. No VM,
topology, snapshot, source commit, or partial Orbit worktree file was modified.

## Preserved history and recovery

Original issue/comment content, task briefs, workspace settings, consistent
backups of both Commander databases, applied maintenance scripts, and detailed
results are retained in:

`/tmp/orbit-no-proof-audit.wNZ7AuLr`

Completed reviews and manifests remain historical evidence. They do not approve
the edited briefs retroactively; the stopped workspace deliberately retains its
hold. Legacy proof handler code and worktree artifacts were not deleted or run.
The old admitted run was not converted into a runnable replacement flow.

Changed issue IDs: ORB-1, ORB-2, ORB-4, ORB-5, ORB-6, ORB-7, ORB-8, ORB-9, ORB-10, ORB-11, ORB-12, ORB-13, ORB-14, ORB-15, ORB-16, ORB-17, ORB-19, ORB-22, ORB-25, ORB-26, ORB-27, ORB-29, ORB-30, ORB-31, ORB-32, ORB-33, ORB-35, ORB-36, ORB-37, ORB-38, ORB-40, ORB-46, ORB-69, ORB-71, ORB-72, ORB-75, ORB-76, ORB-79, ORB-83, ORB-84, ORB-86, ORB-87, ORB-89, ORB-90, ORB-91, ORB-92, ORB-93, ORB-94, ORB-95, ORB-96, ORB-99, ORB-101, ORB-102, ORB-105, ORB-106, ORB-107, ORB-108, ORB-109, ORB-110, ORB-111, ORB-112, ORB-114, ORB-118, ORB-119, ORB-120, ORB-121, ORB-125, ORB-127, ORB-128, ORB-129, ORB-130, ORB-131, ORB-132, ORB-142, ORB-147, ORB-149, ORB-151, ORB-152, ORB-153, ORB-155, ORB-156, ORB-157, ORB-158, ORB-165, ORB-167, ORB-168, ORB-169, ORB-170, ORB-171, ORB-172, ORB-173, ORB-174, ORB-175, ORB-178, ORB-180, ORB-181, ORB-182, ORB-183, ORB-185, ORB-186, ORB-187, ORB-188, ORB-189, ORB-190, ORB-191, ORB-192, ORB-193, ORB-194, ORB-195, ORB-196, ORB-197, ORB-198, ORB-199, ORB-200, ORB-201, ORB-203, ORB-204, ORB-205, ORB-207, ORB-210, ORB-211, ORB-212, ORB-213, ORB-214, ORB-215, ORB-216, ORB-217, ORB-219, ORB-220, ORB-222, ORB-225, ORB-226, ORB-227, ORB-228, ORB-229, ORB-230, ORB-231, ORB-232, ORB-234, ORB-235, ORB-236, ORB-239, ORB-242, ORB-243, ORB-245, ORB-246, ORB-248, ORB-255, ORB-256, ORB-257, ORB-258, ORB-259, ORB-260, ORB-286, ORB-295, ORB-296, ORB-304, ORB-310, ORB-312, ORB-314, ORB-323, ORB-324, ORB-332, ORB-336, ORB-340, ORB-341, ORB-346.

Changed task IDs: 7, 8, 10, 11, 12, 13, 14, 15, 16, 17, 20, 22, 24, 25, 26, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50.

