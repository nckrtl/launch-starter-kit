<?php

declare(strict_types=1);

namespace App\Delivery\Repositories;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitDeliveryLoopStarter;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitDeliveryAdmissionFailed;
use App\Models\ProjectOrchestration;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class ProcessOrbitDeliveryLoopStarter implements OrbitDeliveryLoopStarter
{
    public function __construct(private ProjectConfigRegistry $configs) {}

    public function start(string $projectId, string $issueKey): void
    {
        $project = ProjectOrchestration::query()
            ->where('manifest_project_id', $projectId)
            ->first();

        try {
            $config = $project === null ? null : $this->configs->hydrate($project->config);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw new OrbitDeliveryAdmissionFailed('The Orbit delivery admission config is invalid.', 0, $exception);
        }

        $commander = realpath(base_path());
        $artisanPath = base_path('artisan');
        $artisan = realpath($artisanPath);
        $php = realpath(PHP_BINARY);

        if ($project === null || $project->state !== ProjectOrchestrationState::Enabled
            || ! $config instanceof OrbitProjectConfig || $config->defaultFlow !== 'discovery'
            || $commander === false || $commander !== base_path()
            || $artisan === false || $artisan !== $artisanPath || is_link($artisanPath) || ! is_file($artisan)
            || $php === false || ! is_executable($php)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1) {
            throw new OrbitDeliveryAdmissionFailed('The Commander Orbit delivery admission adapter is unavailable.');
        }

        $unit = 'commander-orbit-admission-'.strtolower($issueKey).'.service';

        try {
            $state = Process::timeout(10)->run([
                'systemctl', '--user', 'show', $unit, '--property=ActiveState', '--value',
            ]);

            if ($state->successful() && in_array(trim($state->output()), ['active', 'activating'], true)) {
                return;
            }

            $started = Process::path($commander)->timeout(10)->run([
                'systemd-run', '--user', '--collect', '--unit='.$unit,
                '--property=WorkingDirectory='.$commander,
                '--property=UnsetEnvironment=SSH_AUTH_SOCK',
                'env', '-u', 'SSH_AUTH_SOCK', $php, $artisan,
                'delivery:start-orbit', $projectId, $issueKey, '--idempotent', '--force',
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitDeliveryAdmissionFailed('The Commander Orbit delivery admission adapter could not run.', 0, $exception);
        }

        if ($started->failed()) {
            throw new OrbitDeliveryAdmissionFailed('The Commander Orbit delivery admission adapter did not start.');
        }
    }
}
