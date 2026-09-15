# ORB-91 pre-proof main integration

## Deployed — 2026-09-14 10:40 UTC

The final25-file composition is independently approved and deployed. Its source
preserves the completed split/audit hardening and amendment1. Exact manifest:
3b53e3d76aeb6500a9a52c17dbac7a51ef01a5c5ceb3960980ba19cc86654d76.
Only the system Tasks worker was restarted, now PID168312. Original18-table
task history and native inspect output are unchanged. Both original sessions
and task41's reviewed tree pass native checks. The actual task41 handoff has
advanced automatically to retained review76; no further implementation prompt
was sent by the rollout.

Full retained operations and limitations:
storage/app/private/orbit91-main-integration.97cpSQKL/DEPLOYED.md.
Use tasks:integrate-main only at an actual clean final integration_required hold,
followed by explicit tasks:advance after apply. Do not run it during task41 review.
The historical rollout holds below are superseded; their records are retained.

## Rollout hold — 2026-09-14 10:15 UTC

UPDATE10:20 UTC: task42 decomposition session finished and applied its own
migration + amendment1. Native inspect verifies the new chain40→41→42→43→44→45,
effective manifest465143bc08951d23e4bdb19c963a8156616727cee6bfeb9666051fdc113ae50c.
The admitted manifest and task41 assignment are unchanged. Original deployment
patches are invalidated, not applied. Composition is isolated at
`/tmp/commander-split-main-composition.mpFKLWXJ/source`, author
orb91_reboot_recovery_design and independent reviewer orb91_reconnect_review.
No additional coordination from the user is needed now that the other task
has finished. Its source and audit will be preserved in the composition.

The integration candidate and test-only supplement passed independent review.
The manager has NOT deployed any candidate file or run its migration. The
Tasks worker remains running; noninteractive sudo is available when needed.

The final preimage check caught a concurrent edit to
`app/Tasks/Runtime/TaskManifestAmendmentHistory.php` by Codex task
`01a09efe-d229-7a40-82cb-ef6ccf3b55dc` (Evaluate ORB-91 scope for Luna Light).
Its split/audit hardening must be preserved, including its other files.
The original 22-file integration patch must NOT overwrite this new preimage.

Coordination request to the task-42 split owner: retain ownership of your split
work and report its final files/tests and any applied amendment. Please leave
main-integration composition and deployment to this manager. Before applying a
task-42 split, account for this real pending main-integration change. Your
`beforeFinalContinuations` currently rejects `integrate_main`; both changes need
one composed review and a split → integration regression before rollout.
No direct inter-task messaging tool is available to the manager; this is a
shared-file notice, not a claim that a message was delivered or acknowledged.

Frozen reviewed candidate:
`/tmp/commander-main-integration.MhAmPAwu/source`.
Final manifest SHA256:
`a21b23fabd5a6edb5e42fead2f90f2ea00aad40c6594ee67280ebf2e9fac1bfd`.
Persistent source/preimages/reviews archive:
`/home/nckrtl/apps/commander/tasks/storage/app/private/orbit91-main-integration.97cpSQKL/`.
No database-before or runtime-before capture exists yet because stopping with
unprivileged systemctl failed; sudo permission has since been verified.

Original task41/run22/dispatch75 and both original Codex processes are intact.
UPDATE10:18 UTC: native read-only quota check now allows ordinary usage. One
guarded continuation resumed the original worker (transcript start10:17:52.159,
Herdr working/seq5); it has not handed off its work. Preserve this active worker.
Do not replay assignments or restart Herdr. ORB-91 is the only admitted issue.

2026-09-14: the Tasks manager owns this bounded runtime change. The separate
UI task owns its observer and placement changes; preserve them during rollout.

ORB-91 is the only admitted issue (root39/workspace16). Task40 is accepted;
Task41 is stopped on a Codex usage-limit error with its original session and
dirty work preserved; Task42 is pending. Do not restart, replay or replace those
agents, merge their worktree, or edit their historical records.

Orbit's proof contract requires current origin/main in the candidate. Commander
currently cannot integrate main after accepted children. An isolated implementation
is adding a known pre-proof hold and one audited integration child through the
existing implement/review/commit cycle. Ordinary tasks stay single-parent.
Discovery-only delivery keeps its existing relaxed main-freshness rule.

Author: `orb91_reboot_recovery_design`. Independent reviewer:
`orb91_reconnect_review`. No implementation has been deployed. Review the exact
candidate, tests and live preimages before rollout; only the system Tasks worker
`orbit-process-49-tasks-worker.service` may need a scoped drained restart.
Shared Herdr and the other controller remain untouched; keep the latter paused.
