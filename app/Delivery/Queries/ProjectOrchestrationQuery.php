<?php

declare(strict_types=1);

namespace App\Delivery\Queries;

use App\Models\ProjectOrchestration;
use App\Projects\SharedKnowledgeProjectRepository;

final readonly class ProjectOrchestrationQuery
{
    public function __construct(private SharedKnowledgeProjectRepository $projects) {}

    /** @return array{project_id: string, configured: bool, state: string|null, config: array<string, mixed>|null} */
    public function config(string $projectId): array
    {
        $this->projects->find($projectId);
        $orchestration = $this->find($projectId);

        return [
            'project_id' => $projectId,
            'configured' => $orchestration !== null,
            'state' => $orchestration?->state->value,
            'config' => $orchestration?->config,
        ];
    }

    /** @return array{project: array{id: string, name: string, manifest_status: string}, orchestration: array{configured: bool, state: string|null, config_type: string|null}} */
    public function status(string $projectId): array
    {
        $manifest = $this->projects->find($projectId);
        $orchestration = $this->find($projectId);
        $config = $orchestration?->config;

        return [
            'project' => [
                'id' => $projectId,
                'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : $projectId,
                'manifest_status' => is_string($manifest['status'] ?? null) ? $manifest['status'] : 'unknown',
            ],
            'orchestration' => [
                'configured' => $orchestration !== null,
                'state' => $orchestration?->state->value,
                'config_type' => is_string($config['type'] ?? null) ? $config['type'] : null,
            ],
        ];
    }

    private function find(string $projectId): ?ProjectOrchestration
    {
        return ProjectOrchestration::query()->where('manifest_project_id', $projectId)->first();
    }
}
