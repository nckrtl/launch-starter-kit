<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\ProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Models\ProjectOrchestration;
use App\Projects\SharedKnowledgeProjectRepository;

final readonly class ConfigureProjectOrchestration
{
    public function __construct(
        private SharedKnowledgeProjectRepository $projects,
        private ProjectConfigRegistry $configs,
    ) {}

    /** @param array<string, mixed>|ProjectConfig $config */
    public function handle(string $projectId, array|ProjectConfig $config): ProjectOrchestration
    {
        $this->projects->find($projectId);
        $hydrated = $config instanceof ProjectConfig ? $this->configs->hydrate($config->toArray()) : $this->configs->hydrate($config);

        $project = ProjectOrchestration::query()->firstOrNew(['manifest_project_id' => $projectId]);
        $project->config = $hydrated;

        if (! $project->exists) {
            $project->state = ProjectOrchestrationState::Enabled;
        }

        $project->save();

        return $project;
    }
}
