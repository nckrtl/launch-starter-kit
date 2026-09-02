<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Herdr socket
    |--------------------------------------------------------------------------
    |
    | Unix socket of the Herdr session whose agents Commander follows.
    |
    */

    'socket' => env('HERDR_SOCKET_PATH', '/home/nckrtl/.config/herdr/sessions/orbit/herdr.sock'),

    /*
    |--------------------------------------------------------------------------
    | Slack channel
    |--------------------------------------------------------------------------
    |
    | Channel ID that receives agent-status notifications. Falls back to the
    | default Slack notification channel.
    |
    */

    'slack_channel' => env('HERDR_SLACK_CHANNEL', env('SLACK_BOT_USER_DEFAULT_CHANNEL')),

    /*
    |--------------------------------------------------------------------------
    | Notified statuses
    |--------------------------------------------------------------------------
    |
    | A pane entering one of these statuses is recorded and posted. Every
    | other status (working, unknown) only updates the tracked state. Set
    | HERDR_NOTIFY_STATUSES to a comma-separated list to narrow the set.
    |
    */

    'notify_statuses' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('HERDR_NOTIFY_STATUSES', 'idle,done,blocked'))),
        fn (string $status): bool => $status !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | Status labels
    |--------------------------------------------------------------------------
    |
    | How a Herdr status is worded when posted. Herdr reports "done" for an
    | idle agent nobody has viewed yet, which reads as idle to the recipient.
    | The stored event keeps Herdr's raw status.
    |
    */

    'status_labels' => ['done' => 'idle'],

    /*
    |--------------------------------------------------------------------------
    | Debounce
    |--------------------------------------------------------------------------
    |
    | Seconds a status must hold before it is posted; a pane that flips back
    | to work inside this window is not announced.
    |
    */

    'debounce_seconds' => 5,

];
