<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('ships a bounded restartable Commander delivery worker', function (): void {
    $unit = File::get(base_path('ops/systemd/commander-delivery-worker.service'));

    expect($unit)
        ->toContain('WorkingDirectory=/fast/apps/commander')
        ->toContain('ExecStart=/usr/bin/php /fast/apps/commander/artisan queue:work database')
        ->toContain('--name=commander-delivery')
        ->toContain('--queue=default')
        ->toContain('--tries=0')
        ->toContain('--timeout=540')
        ->toContain('--max-time=3600')
        ->toContain('--no-interaction')
        ->toContain('ExecReload=/usr/bin/php /fast/apps/commander/artisan queue:restart')
        ->toContain('Restart=always')
        ->toContain('TimeoutStopSec=600')
        ->toContain('KillMode=mixed')
        ->not->toContain('--force');

    expect((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(540);
});
