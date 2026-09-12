An operator manages Schedules for a Node or an AppInstance from the CLI with `schedule:add`, `schedule:list`, `schedule:show`, `schedule:run`, `schedule:logs`, `schedule:remove`, and `schedule:activate`, each an HTTP-only call through the PHP SDK, and `orbit doctor` renders the `schedule` family. Completion remains an internal Node-authenticated API and SDK transport outside the operator CLI. The operations are in [ADR 0013](https://github.com/nckrtl/orbit/blob/main/docs/decisions/0013-native-systemd-schedule-management.md) and the completion boundary is refined by [ADR 0060](https://github.com/nckrtl/orbit/blob/main/docs/decisions/0060-record-the-latest-schedule-run-status.md).

## Scope

- In: `schedule:add`, `schedule:list`, `schedule:show`, `schedule:run`, `schedule:logs`, `schedule:remove`, and `schedule:activate` over the PHP SDK, with one Node or one AppInstance target selector
- In: locally decidable option validation before HTTP, deterministic human and `--json` output, request IDs, safe shared errors, and bounded log rendering
- In: interactive ambiguity resolution through one bounded choice
- In: `schedule` as a `doctor --family` token
- In: the CLI command inventory, guidance, README, and generated context
- In: preserving the internal Node-authenticated completion API and SDK transport without exposing `CompleteScheduleRequest` through an operator command
- Out: Workspace targets
- Out: local SSH, systemd, `journalctl`, shell, curl, or Schedule execution on the operator machine
- Out: prompts in `--json` or non-interactive mode
- Out: target, placement, authorization, and policy decisions, which stay in the Gateway
- Out: Gateway, PHP SDK, production deployment, and harness changes

## Acceptance

- [ ] Each of the seven operator commands sends exactly one SDK request for its operation and renders the typed response in human and `--json` form with the request ID, and a Gateway failure renders the shared safe error. Proof: `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`.
- [ ] `schedule:add` with both a Node and an AppInstance target, with neither, or with an option that belongs to the other target kind fails before any HTTP request. Proof: `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`.
- [ ] `schedule:list` output contains no command text and `schedule:logs` renders only the bounded lines the Gateway returns. Proof: `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`.
- [ ] An ambiguous target in an interactive terminal is resolved through one bounded choice, and the same input with `--json` or without a terminal fails with an error naming the missing identity and sends no request. Proof: `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`.
- [ ] `orbit doctor --family=schedule` is accepted and renders the `schedule` family at its position in the ordered family set without asserting a numeric family count. Proof: `apps/cli/tests/Feature/Doctor/DoctorCommandTest.php`.
- [ ] The command inventory and guidance list the seven operator commands, state that completion remains internal Node-authenticated API and SDK transport, and name no local host-execution dependency. Proof: `apps/cli/tests/Feature/BoostGuidanceTest.php`.
- [ ] From the operator machine, `schedule:add`, `schedule:show`, `schedule:run`, `schedule:logs`, and `schedule:remove` succeed against a real Node target and a real app-dev AppInstance target, and the logs output shows the run's journald lines. Proof: Incus action `schedule-cli-lifecycle`.
- [ ] Maintained documentation states the seven operator Schedule commands, the internal completion boundary, target selectors, output modes, and the Doctor family token, and generated context is current. Proof: `composer docs-lint`.
- [ ] CLI checks pass. Proof: `cd apps/cli && composer check`.
- [ ] Development verification uses focused affected Pest tests through TIA and changed-project checks. The finished exact candidate passes the Builder root `composer check` gate with TIA. Proof: affected Pest tests, `cd apps/cli && composer check`, and root `composer check`.
- [ ] `schedule:add --instance=INSTANCE --no-start` sends `start=false`, and `schedule:activate UUID` enables a previously installed AppInstance timer; human and JSON output show desired timer state separately from lifecycle state, while `schedule:run` remains one manual invocation. Proof: `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`.
- [ ] A production Schedule can be installed stopped, manually run without activating its timer, and explicitly activated after a first release; repeating activation preserves the same Schedule. Proof: Incus action `schedule-cli-production-activation`.
