<?php

return [
    'projects_path' => env('COMMANDER_PROJECTS_PATH', '/home/nckrtl/shared-knowledge/projects'),

    'mcp_token' => env('COMMANDER_MCP_TOKEN'),

    'orbit' => [
        'url' => env('COMMANDER_ORBIT_URL', 'https://10.44.0.1'),
        'ca' => env('COMMANDER_ORBIT_CA', storage_path('app/orbit-gateway-ca.crt')),
    ],

    'github_binary' => env('COMMANDER_GITHUB_BINARY', '/home/linuxbrew/.linuxbrew/bin/gh'),

    'delivery' => [
        'orbit_auto_admission' => (bool) env('COMMANDER_ORBIT_AUTO_ADMISSION', false),
        'orbit_controller_label_id' => env('COMMANDER_ORBIT_CONTROLLER_LABEL_ID', '34651888-f68e-4bf2-b322-ec05c2a7fc64'),
        'orbit_planning_resolutions' => [
            'ORB-71' => [
                'issue_id' => '6c72f49f-55f7-4cb9-b47a-41fa9d9473cf',
                'resolution_receipt_sha256' => '43640aaf5de5c119a3b1651c03c9fea4638b4aa002cf74596e9714373cdda4e4',
                'current_contract_sha256' => '45d634da29199f29881ee2511c7803f9fde3e296ef9bb66c35530b9664e19a2e',
                'corrected_contract_sha256' => 'a1aaca1eed1efead10af4e6fb561f0aa2fda98fd42d33581d31679f42ac5657b',
                'corrected_description' => resource_path('delivery/orbit/planning-resolutions/orb-71.md'),
                'corrected_description_sha256' => '0a167f2d51d776771a8a6622767db17bef9422a26fd814f12192bf43920c222f',
            ],
        ],
    ],

    'hermes' => [
        'ssh_target' => env('COMMANDER_HERMES_SSH_TARGET', 'nckrtl@10.44.0.9'),
        'binary' => env('COMMANDER_HERMES_BINARY', '/Users/nckrtl/.hermes/hermes-agent/venv/bin/hermes'),
        'profiles' => [
            'anna' => '/Users/nckrtl/.hermes',
            'tom' => '/Users/nckrtl/.hermes/profiles/tom',
        ],
        'tom_linear_viewer_id' => env('COMMANDER_HERMES_TOM_LINEAR_VIEWER_ID', '4fa61558-9052-45f7-8a7c-49e0b891d4bf'),
        'nick_linear_user_id' => env('COMMANDER_HERMES_NICK_LINEAR_USER_ID', '691cb14c-60d5-415a-a5c7-a7c19fe83424'),
        'orbit_linear_team_id' => env('COMMANDER_HERMES_ORBIT_LINEAR_TEAM_ID', 'adf44a8f-4b0c-46ae-a846-1414e7d919aa'),
        'timeout' => (int) env('COMMANDER_HERMES_TIMEOUT', 5),
        'cache_seconds' => (int) env('COMMANDER_HERMES_CACHE_SECONDS', 15),
    ],

    'herdr' => [
        'sessions' => [
            [
                'node' => 'beast',
                'name' => 'orbit',
                'socket' => env('HERDR_SOCKET', '/home/nckrtl/.config/herdr/sessions/orbit/herdr.sock'),
            ],
            [
                'node' => 'beast',
                'name' => 'launch',
                'socket' => env('COMMANDER_HERDR_LAUNCH_SOCKET', '/home/nckrtl/.config/herdr/sessions/launch/herdr.sock'),
            ],
        ],
    ],
];
