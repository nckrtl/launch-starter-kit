<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\Delivery;
use App\Models\ProjectOrchestration;

final readonly class StartShadowDelivery
{
    public function handle(
        ProjectOrchestration $project,
        string $issueId,
        string $issueKey,
        string $worktreePath,
        string $candidateSha,
    ): Delivery {
        return Delivery::query()->create([
            'project_orchestration_id' => $project->getKey(),
            'external_issue_provider' => 'linear',
            'external_issue_id' => $issueId,
            'external_issue_key' => $issueKey,
            'workflow_type' => ShadowWorkflow::TYPE,
            'workflow_version' => ShadowWorkflow::VERSION,
            'config_snapshot' => $project->config->toArray(),
            'status' => DeliveryStatus::Queued,
            'current_phase' => 'herdr_test',
            'worktree_path' => $worktreePath,
            'candidate_sha' => $candidateSha,
        ]);
    }
}
