<?php

return [
    'projects_path' => env('COMMANDER_PROJECTS_PATH', '/home/nckrtl/shared-knowledge/projects'),

    'mcp_token' => env('COMMANDER_MCP_TOKEN'),

    'orbit' => [
        'url' => env('COMMANDER_ORBIT_URL', 'https://10.44.0.1'),
        'ca' => env('COMMANDER_ORBIT_CA', storage_path('app/orbit-gateway-ca.crt')),
    ],

    'github_binary' => env('COMMANDER_GITHUB_BINARY', '/home/linuxbrew/.linuxbrew/bin/gh'),

    'hermes' => [
        'ssh_target' => env('COMMANDER_HERMES_SSH_TARGET', 'nckrtl@10.44.0.9'),
        'binary' => env('COMMANDER_HERMES_BINARY', '/Users/nckrtl/.hermes/hermes-agent/venv/bin/hermes'),
        'profiles' => [
            'anna' => '/Users/nckrtl/.hermes',
            'tom' => '/Users/nckrtl/.hermes/profiles/tom',
        ],
        'tom_linear_viewer_id' => env('COMMANDER_HERMES_TOM_LINEAR_VIEWER_ID', '4fa61558-9052-45f7-8a7c-49e0b891d4bf'),
        'nick_linear_user_id' => env('COMMANDER_HERMES_NICK_LINEAR_USER_ID', '691cb14c-60d5-415a-a5c7-a7c19fe83424'),
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
