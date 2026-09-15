<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\Delivery;
use App\Models\Task;
use App\Models\TaskWorkspace;
use App\Support\NetworkOrigin;
use App\Tasks\Orbit\OrbitTaskProfile;
use App\Tasks\Preparation\TaskPreparationGuard;
use App\Tasks\TaskGraph;
use App\Tasks\TaskMutation;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final readonly class StartTaskWorkspace
{
    public function __construct(private TaskRuntimePlan $plans, private TaskGraph $graph, private TaskMutation $mutation, private GitTaskWorktree $git, private TaskPreparationGuard $preparation) {}

    public function handle(Task $root, string $worktree, string $expectedManifest, string $sourceKey, bool $exclusiveAdoption,
        ?string $orbitFlow = null, bool $snapshotReplacement = false): TaskWorkspace
    {
        if (config('task-runtime.enabled') !== true || ! $exclusiveAdoption || trim($sourceKey) === '' || mb_strlen($sourceKey) > 255) {
            throw new LogicException('Enable the task runtime and explicitly confirm exclusive source/worktree adoption.');
        }
        $orbitProfile = OrbitTaskProfile::select($root->project_id, $orbitFlow, $snapshotReplacement);
        $configuration = config('task-runtime.projects.'.$root->project_id);
        if (! is_array($configuration)) {
            throw new LogicException('No PHP task flow is configured for this project.');
        }
        if ($root->project_id !== 'orbit' && array_key_exists('orbit_profile', $configuration)) {
            throw new LogicException('An Orbit execution profile cannot belong to another project.');
        }
        foreach (['repository', 'worktree_root', 'socket', 'agent_kind', 'instructions'] as $key) {
            if (! isset($configuration[$key]) || ! is_string($configuration[$key]) || $configuration[$key] === '') {
                throw new LogicException('Task runtime configuration is incomplete: '.$key);
            }
        }
        if (($configuration['flow_version'] ?? null) !== 1 || ! is_array($configuration['final_command'] ?? null)
            || ! array_is_list($configuration['final_command']) || $configuration['final_command'] === []
            || ! is_int($configuration['final_timeout'] ?? null) || $configuration['final_timeout'] < 1 || $configuration['final_timeout'] > 3600
            || ! is_array($configuration['agent_arguments'] ?? null) || ! array_is_list($configuration['agent_arguments'])) {
            throw new LogicException('Unsupported task flow configuration.');
        }
        $orbitHerdrPlacement = $this->orbitHerdrPlacement($configuration);
        foreach ([...$configuration['final_command'], ...$configuration['agent_arguments']] as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new LogicException('Task commands must use string argument lists.');
            }
        }
        $allowedRoot = realpath($configuration['worktree_root']);
        if ($allowedRoot === false || $allowedRoot !== $configuration['worktree_root'] || ! str_starts_with($worktree, $allowedRoot.'/')) {
            throw new LogicException('Worktree must be inside the configured canonical worktree root.');
        }
        $admit = function () use ($root, $configuration, $worktree, $expectedManifest, $sourceKey, $orbitProfile, $orbitHerdrPlacement): TaskWorkspace {
            $base = $this->git->validate($configuration['repository'], $worktree);

            return $this->mutation->handle($root->project_id, function () use ($root, $configuration, $worktree, $base, $expectedManifest, $sourceKey, $orbitProfile, $orbitHerdrPlacement): TaskWorkspace {
                $root = Task::query()->findOrFail($root->id);
                $manifest = $this->plans->hash($root);
                if (! hash_equals($manifest, $expectedManifest)) {
                    throw new LogicException('The approved task content or order changed. Inspect and approve the current manifest.');
                }
                $existing = TaskWorkspace::query()->where('root_task_id', $root->id)->first();
                if ($existing !== null) {
                    if (OrbitTaskProfile::forWorkspace($existing) !== $orbitProfile) {
                        throw new LogicException('The feature is already bound to a different Orbit execution profile.');
                    }
                    if ($existing->worktree !== $worktree || $existing->source_key !== $sourceKey || $this->plans->effectiveHash($existing) !== $manifest) {
                        throw new LogicException('The feature is already bound to a different execution context.');
                    }

                    return $existing;
                }
                $this->graph->assertUnattempted($root);
                if (Delivery::query()->active()->where(function (Builder $query) use ($worktree, $sourceKey): void {
                    $query->where('worktree_path', $worktree)->orWhere('external_issue_key', $sourceKey);
                })->exists()) {
                    throw new LogicException('An existing delivery already owns this source or worktree.');
                }
                if ($orbitProfile !== null) {
                    OrbitTaskProfile::assertNativeFlow($worktree, $orbitProfile);
                    $configuration['orbit_profile'] = $orbitProfile;
                }

                return TaskWorkspace::query()->create(['root_task_id' => $root->id, 'project_id' => $root->project_id,
                    'source_key' => $sourceKey, 'repository' => $configuration['repository'], 'worktree' => $worktree,
                    'base_sha' => $base, 'manifest_hash' => $manifest, 'configuration' => $configuration,
                    ...$orbitHerdrPlacement]);
            });
        };

        if ($root->project_id !== 'orbit' || TaskWorkspace::query()->where('root_task_id', $root->id)->exists()) {
            return $admit();
        }

        return $this->preparation->admission($root, $configuration['repository'], $worktree, $sourceKey, $expectedManifest, $admit);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{orbit_node_id: ?int, orbit_herdr_session_id: ?int, orbit_herdr_session: ?string, orbit_herdr_observer_origin: ?string}
     */
    private function orbitHerdrPlacement(array $configuration): array
    {
        $placement = [
            'orbit_node_id' => $configuration['orbit_node_id'] ?? null,
            'orbit_herdr_session_id' => $configuration['orbit_herdr_session_id'] ?? null,
            'orbit_herdr_session' => $configuration['orbit_herdr_session'] ?? null,
            'orbit_herdr_observer_origin' => $configuration['orbit_herdr_observer_origin'] ?? null,
        ];

        if (count(array_filter($placement, static fn (mixed $value): bool => $value !== null)) === 0) {
            return [
                'orbit_node_id' => null,
                'orbit_herdr_session_id' => null,
                'orbit_herdr_session' => null,
                'orbit_herdr_observer_origin' => null,
            ];
        }

        $observerOrigin = NetworkOrigin::canonical($placement['orbit_herdr_observer_origin'], 'wss');
        if (! is_int($placement['orbit_node_id']) || $placement['orbit_node_id'] < 1
            || ! is_int($placement['orbit_herdr_session_id']) || $placement['orbit_herdr_session_id'] < 1
            || ! is_string($placement['orbit_herdr_session']) || preg_match('/\A[A-Za-z0-9._-]+\z/D', $placement['orbit_herdr_session']) !== 1
            || $observerOrigin === null) {
            throw new LogicException('The Orbit Herdr placement must contain a valid node, session, and WSS observer origin.');
        }

        return [
            'orbit_node_id' => $placement['orbit_node_id'],
            'orbit_herdr_session_id' => $placement['orbit_herdr_session_id'],
            'orbit_herdr_session' => $placement['orbit_herdr_session'],
            'orbit_herdr_observer_origin' => $observerOrigin,
        ];
    }
}
