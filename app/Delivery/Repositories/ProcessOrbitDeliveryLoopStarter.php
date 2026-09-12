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

        $repository = $config instanceof OrbitProjectConfig ? realpath($config->repository) : false;
        $loopPath = $repository === false ? '' : $repository.'/bin/loop';
        $loop = $repository === false ? false : realpath($loopPath);

        if ($project === null || $project->state !== ProjectOrchestrationState::Enabled
            || ! $config instanceof OrbitProjectConfig || $config->defaultFlow !== 'discovery'
            || $repository === false || $repository !== $config->repository
            || $loop === false || $loop !== $loopPath || is_link($loopPath) || ! is_executable($loop)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1) {
            throw new OrbitDeliveryAdmissionFailed('The configured Orbit loop admission adapter is unavailable.');
        }

        $unit = 'commander-orbit-admission-'.strtolower($issueKey).'.service';

        try {
            $state = Process::timeout(10)->run([
                'systemctl', '--user', 'show', $unit, '--property=ActiveState', '--value',
            ]);

            if ($state->successful() && in_array(trim($state->output()), ['active', 'activating'], true)) {
                return;
            }

            $started = Process::path($repository)->timeout(10)->run([
                'systemd-run', '--user', '--collect', '--unit='.$unit,
                '--property=WorkingDirectory='.$repository,
                '--property=UnsetEnvironment=SSH_AUTH_SOCK',
                'env', '-u', 'SSH_AUTH_SOCK', $loop, $issueKey,
            ]);
        } catch (RuntimeException $exception) {
            throw new OrbitDeliveryAdmissionFailed('The Orbit loop admission adapter could not run.', 0, $exception);
        }

        if ($started->failed()) {
            throw new OrbitDeliveryAdmissionFailed('The Orbit loop admission adapter did not start.');
        }
    }
}
