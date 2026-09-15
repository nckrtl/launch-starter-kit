# ORB-91 session recovery coordination

Update08:25 UTC: recovery is deployed as the independently reviewed20-file
integration, preserving the UI task's source. Audit1 reconnects the same original
worker/reviewer; historical dispatches remain unchanged. Tasks worker restarted
as PID933383; shared Herdr was untouched. Original worker41 is now working after
one explicit continuation. Do not restart or replay it. The UI owner independently
applied the placement migration; live task41 page and terminal now render without
JavaScript errors. See the persistent archive's DEPLOYED.md for exact evidence.

2026-09-14. Gas City task owns ORB-91 root39/workspace16 recovery.
The separate UI task owns Orbit observer placement and its migration. Neither
effort should overwrite the other's source or change existing Orbit assignments.

Reconnection work is isolated in
`/tmp/commander-session-reconnect.sWNh8ucb/source` and under independent review.
It adds a terminal-binding audit for the two original resumed conversations.
No reconnection code or migration is deployed yet. Both agents remain idle.

Files overlapping the UI work are `app/Tasks/Runtime/TaskTerminalSessions.php`
and `tests/Feature/TaskRuntimeTest.php`. The recovery coordinator must apply only
reviewed changes against verified preimages, preserving the UI changes.

A real browser visit to `/projects/orbit/tasks/41` at08:11 UTC returned a
MissingAttributeException for `TaskWorkspace.orbit_herdr_session_id`. The UI-owned
`2026_09_14_120000_add_orbit_herdr_placement_to_task_workspaces` migration was
pending. This is not caused by reconnection and is not authorization to mix that
migration into the recovery rollout.

Do not restart shared Herdr or prompt the restored ORB-91 agents. The exact Tasks
worker unit is `orbit-process-49-tasks-worker.service`; only that worker is in
scope for the recovery coordinator's short drained rollout. The other Commander
controller must remain paused. Task41's original dispatch75 and all historical
receipts must stay unchanged.

Persistent recovery evidence:
`/home/nckrtl/apps/commander/tasks/storage/app/private/orbit91-session-reconnect.246gmLZM/`.
Never replay its already-used native restore operations.
