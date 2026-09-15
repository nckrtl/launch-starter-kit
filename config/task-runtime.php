<?php

return [
    // Explicit opt-in on the host that owns both the worktrees and Herdr socket.
    'enabled' => (bool) env('COMMANDER_TASK_RUNTIME_ENABLED', false),
    'preparation' => [
        'timeout' => (int) env('COMMANDER_TASK_PREPARATION_TIMEOUT', 3600),
        'temporary_root' => env('COMMANDER_TASK_PREPARATION_TMP_ROOT', '/tmp'),
    ],
    'projects' => [
        'orbit' => [
            'repository' => env('COMMANDER_TASK_ORBIT_REPOSITORY', ''),
            'worktree_root' => env('COMMANDER_TASK_ORBIT_WORKTREE_ROOT', ''),
            'socket' => env('COMMANDER_TASK_HERDR_SOCKET', ''),
            'herdr_binary' => env('COMMANDER_TASK_HERDR_BINARY', '/home/linuxbrew/.linuxbrew/bin/herdr'),
            'herdr_session' => env('COMMANDER_TASK_HERDR_SESSION', 'commander-tasks'),
            // Set all four values after Orbit has published a managed or adopted Herdr observer.
            'orbit_node_id' => ($value = (int) env('COMMANDER_TASK_ORBIT_NODE_ID', 0)) > 0 ? $value : null,
            'orbit_herdr_session_id' => ($value = (int) env('COMMANDER_TASK_ORBIT_HERDR_SESSION_ID', 0)) > 0 ? $value : null,
            'orbit_herdr_session' => ($value = env('COMMANDER_TASK_ORBIT_HERDR_SESSION')) !== null && $value !== '' ? $value : null,
            'orbit_herdr_observer_origin' => ($value = env('COMMANDER_TASK_ORBIT_HERDR_OBSERVER_ORIGIN')) !== null && $value !== '' ? rtrim($value, '/') : null,
            'agent_kind' => 'codex',
            'agent_arguments' => [
                '-c', 'mcp_servers.orbit-cli-boost.cwd={worktree}/apps/cli',
                '-c', 'mcp_servers.orbit-gateway-boost.cwd={worktree}/apps/gateway',
            ],
            'flow_version' => 1,
            'final_command' => ['composer', 'check'],
            'final_timeout' => 3600,
            'instructions' => <<<'INSTRUCTIONS'
                Follow the current task brief, Orbit AGENTS.md and accepted ADRs, with direct owner instructions taking precedence. Do not write proof scripts, create or run proof topologies, or construct any new topology, including a cold replacement. Do not require separate proof, capture or reacquisition runs for delivery. Use focused automated tests and independent code review. If machine verification is needed, use the existing topology after checking ownership; adjust it and snapshot it only within the assigned scope. Preserve unrelated workloads and all protected ORB-324 resources. Each implementer owns only its assigned code, tests and documentation. Report actual checks and unrun work honestly; removed requirements are not passing evidence. Reuse valid unchanged review/test results. Do not merge, deploy or resume execution unless the current assignment authorizes it.
                INSTRUCTIONS,
        ],
    ],
];
