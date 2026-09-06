<?php

declare(strict_types=1);

return [
    /*
     * Keep the visual annotation runtime available, but do not contact a sync
     * server unless this application explicitly configures one.
     */
    'enabled' => env('LARAVEL_TOOLBAR_AGENTATION_ENABLED', true),
    'endpoint' => env('LARAVEL_TOOLBAR_AGENTATION_ENDPOINT'),
];
