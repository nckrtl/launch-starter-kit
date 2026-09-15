# Orbit proof delivery is withdrawn

Owner direction, 2026-09-14: do not use the former proof-topology delivery procedure.
Do not write proof scripts, select proof admission, build a cold replacement,
capture a new proof, or run post-install discovery/proof reacquisition.

For ORB-91, the authorized delivery owner should adjust the existing topology,
verify the sample behavior, and snapshot that existing topology. Keep focused
automated tests and independent code review. Do not construct a new topology.

Commander implementation is stopped. Editing requirements is not permission to
resume it. Task50 and dispatch125 are withdrawn; accepted commits and historical
reviews remain preserved. Removing a requirement is not a passing test result.

Legacy proof handlers and archived records may still describe the old procedure.
They are not current task requirements. Do not execute them or build a replacement
delivery mechanism to satisfy the withdrawn gates.
