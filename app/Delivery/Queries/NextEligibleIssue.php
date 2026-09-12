<?php

declare(strict_types=1);

namespace App\Delivery\Queries;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitEligibleIssueProvider;
use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;
use App\Projects\SharedKnowledgeProjectRepository;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class NextEligibleIssue
{
    public function __construct(
        private SharedKnowledgeProjectRepository $projects,
        private ProjectConfigRegistry $configs,
        private OrbitEligibleIssueProvider $issues,
    ) {}

    /**
     * @return array{
     *     project_id: string,
     *     capacity: array{active: int, limit: int, available: bool},
     *     issue: array{id: string, key: string, title: string, url: string, labels: list<string>, controller_owned: bool, contract_sha256: string}|null
     * }
     */
    public function get(string $projectId): array
    {
        [$config, $active] = $this->context($projectId);
        $available = $active < $config->concurrency;
        $issue = $available ? $this->issues->next() : null;

        return [
            'project_id' => $projectId,
            'capacity' => [
                'active' => $active,
                'limit' => $config->concurrency,
                'available' => $available,
            ],
            'issue' => $issue === null ? null : [
                'id' => $issue->snapshot->issueId,
                'key' => $issue->snapshot->issueKey,
                'title' => $issue->title,
                'url' => $issue->url,
                'labels' => $issue->labels,
                'controller_owned' => in_array('controller:commander', $issue->labels, true),
                'contract_sha256' => $issue->snapshot->contractHash,
            ],
        ];
    }

    public function candidate(string $projectId): ?OrbitEligibleIssue
    {
        [$config, $active] = $this->context($projectId);

        return $active < $config->concurrency ? $this->issues->next() : null;
    }

    /** @return array{OrbitProjectConfig, int} */
    private function context(string $projectId): array
    {
        $this->projects->find($projectId);
        $project = ProjectOrchestration::query()
            ->where('manifest_project_id', $projectId)
            ->first();

        if ($project === null || $project->state !== ProjectOrchestrationState::Enabled) {
            throw new InvalidArgumentException("Project [{$projectId}] is not configured and enabled.");
        }

        try {
            $config = $this->configs->hydrate($project->config);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw new InvalidArgumentException('The project has invalid orchestration config.', 0, $exception);
        }

        if (! $config instanceof OrbitProjectConfig || $config->defaultFlow !== 'discovery') {
            throw new InvalidArgumentException("Project [{$projectId}] does not support Orbit discovery selection.");
        }

        $active = Delivery::query()
            ->whereBelongsTo($project)
            ->active()
            ->count();

        return [$config, $active];
    }
}
