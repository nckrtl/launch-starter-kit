<?php

use App\Jobs\ReconcileDeliveries;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ReconcileDeliveries)
    ->name('deliveries:reconcile')
    ->everyMinute()
    ->withoutOverlapping(2);
