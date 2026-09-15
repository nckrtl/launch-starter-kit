<?php

use App\Providers\AppServiceProvider;
use App\Providers\TaskRuntimeServiceProvider;
use App\Providers\ToolbarConfigProvider;

return [
    AppServiceProvider::class,
    TaskRuntimeServiceProvider::class,
    ToolbarConfigProvider::class,
];
