<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('ships a bounded restartable Commander delivery worker', function (): void {
    $unit = File::get(base_path('ops/systemd/commander-delivery-worker.service'));

    expect($unit)
        ->toContain('WorkingDirectory=/home/nckrtl/apps/commander/main')
        ->toContain('UnsetEnvironment=SSH_AUTH_SOCK')
        ->toContain('ExecStart=/usr/bin/php /home/nckrtl/apps/commander/main/artisan queue:work database')
        ->toContain('--name=commander-delivery')
        ->toContain('--queue=default')
        ->toContain('--tries=0')
        ->toContain('--timeout=540')
        ->toContain('--max-time=3600')
        ->toContain('--no-interaction')
        ->toContain('ExecReload=/usr/bin/php /home/nckrtl/apps/commander/main/artisan queue:restart')
        ->toContain('Restart=always')
        ->toContain('TimeoutStopSec=600')
        ->toContain('KillMode=mixed')
        ->not->toContain('--force');

    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(540);
});

it('ships a persistent one-minute Laravel scheduler timer', function (): void {
    $service = File::get(base_path('ops/systemd/commander-scheduler.service'));
    $timer = File::get(base_path('ops/systemd/commander-scheduler.timer'));

    expect($service)
        ->toContain('Type=oneshot')
        ->toContain('WorkingDirectory=/home/nckrtl/apps/commander/main')
        ->toContain('ExecStart=/usr/bin/php /home/nckrtl/apps/commander/main/artisan schedule:run --no-interaction')
        ->toContain('TimeoutStartSec=55')
        ->not->toContain('--force');
    expect($timer)
        ->toContain('OnCalendar=*-*-* *:*:00')
        ->toContain('Persistent=true')
        ->toContain('AccuracySec=1s')
        ->toContain('Unit=commander-scheduler.service')
        ->toContain('WantedBy=timers.target');
});
