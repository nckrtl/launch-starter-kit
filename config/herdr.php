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
    | other status (working, unknown) only updates the tracked state.
    |
    */

    'notify_statuses' => ['idle', 'done', 'blocked'],

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
