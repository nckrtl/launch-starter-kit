<?php

declare(strict_types=1);

namespace App\Tasks\Runtime;

use App\Models\TaskWorkspace;
use App\Support\NetworkOrigin;
use App\Tasks\TaskMutation;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final readonly class MigrateTaskWorkspaceHerdrPlacement
{
    public function __construct(private TaskMutation $mutation) {}

    /**
     * @return array{project: string, source_workspace_id: int, migrated_workspace_ids: list<int>, orbit_node_id: int, orbit_herdr_session_id: int, orbit_herdr_session: string, orbit_herdr_observer_origin: string}
     */
    public function handle(string $projectId, int $sourceWorkspaceId, int $expectedCount, bool $exclusive): array
    {
        if (config('task-runtime.enabled') !== true || ! $exclusive || $projectId === '' || $expectedCount < 0) {
            throw new LogicException('Enable the task runtime, provide a valid project and count, and confirm exclusive migration ownership.');
        }

        return $this->mutation->handle($projectId, function () use ($projectId, $sourceWorkspaceId, $expectedCount): array {
            $workspaces = TaskWorkspace::query()
                ->where('project_id', $projectId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $source = $workspaces->firstWhere('id', $sourceWorkspaceId);

            if (! $source instanceof TaskWorkspace) {
                throw new LogicException('The placement source must be a workspace in the selected project.');
            }

            $placement = $this->placement($source);
            $sourceSocket = $source->configuration['socket'] ?? null;
            if (! is_string($sourceSocket) || $sourceSocket === '') {
                throw new LogicException('The placement source has no recorded Herdr socket.');
            }

            $legacy = $this->validate($workspaces, $placement, $sourceSocket);
            if ($legacy->count() !== $expectedCount) {
                throw new LogicException("Expected {$expectedCount} legacy workspaces, found {$legacy->count()}.");
            }

            $migrated = [];
            foreach ($legacy as $workspace) {
                $updated = TaskWorkspace::query()
                    ->whereKey($workspace->id)
                    ->whereNull('orbit_node_id')
                    ->whereNull('orbit_herdr_session_id')
                    ->whereNull('orbit_herdr_session')
                    ->whereNull('orbit_herdr_observer_origin')
                    ->toBase()
                    ->update($placement);
                if ($updated !== 1) {
                    throw new LogicException('A task workspace placement changed during migration.');
                }

                $migrated[] = $workspace->id;
            }

            return [
                'project' => $projectId,
                'source_workspace_id' => $sourceWorkspaceId,
                'migrated_workspace_ids' => $migrated,
                ...$placement,
            ];
        });
    }

    /**
     * @param  Collection<int, TaskWorkspace>  $workspaces
     * @param  array{orbit_node_id: int, orbit_herdr_session_id: int, orbit_herdr_session: string, orbit_herdr_observer_origin: string}  $placement
     * @return Collection<int, TaskWorkspace>
     */
    private function validate(Collection $workspaces, array $placement, string $sourceSocket): Collection
    {
        return $workspaces->filter(function (TaskWorkspace $workspace) use ($placement, $sourceSocket): bool {
            $values = [
                $workspace->orbit_node_id,
                $workspace->orbit_herdr_session_id,
                $workspace->orbit_herdr_session,
                $workspace->orbit_herdr_observer_origin,
            ];
            $present = count(array_filter($values, static fn (mixed $value): bool => $value !== null));

            if ($present === 0) {
                if (($workspace->configuration['socket'] ?? null) !== $sourceSocket) {
                    throw new LogicException("Workspace {$workspace->id} belongs to a different recorded Herdr socket.");
                }

                return true;
            }

            if ($present !== count($values) || $this->placement($workspace) !== $placement) {
                throw new LogicException("Workspace {$workspace->id} has a conflicting Orbit Herdr placement.");
            }

            return false;
        })->values();
    }

    /** @return array{orbit_node_id: int, orbit_herdr_session_id: int, orbit_herdr_session: string, orbit_herdr_observer_origin: string} */
    private function placement(TaskWorkspace $workspace): array
    {
        $origin = NetworkOrigin::canonical($workspace->orbit_herdr_observer_origin, 'wss');
        if (! is_int($workspace->orbit_node_id) || $workspace->orbit_node_id < 1
            || ! is_int($workspace->orbit_herdr_session_id) || $workspace->orbit_herdr_session_id < 1
            || ! is_string($workspace->orbit_herdr_session)
            || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/D', $workspace->orbit_herdr_session) !== 1
            || $origin === null) {
            throw new LogicException('The placement source must have a complete canonical Orbit Herdr placement.');
        }

        return [
            'orbit_node_id' => $workspace->orbit_node_id,
            'orbit_herdr_session_id' => $workspace->orbit_herdr_session_id,
            'orbit_herdr_session' => $workspace->orbit_herdr_session,
            'orbit_herdr_observer_origin' => $origin,
        ];
    }
}
