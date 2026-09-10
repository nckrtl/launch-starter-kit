<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use Illuminate\Support\Facades\DB;

final readonly class StartShadowDelivery
{
    public function handle(
        ProjectOrchestration $project,
        string $issueId,
        string $issueKey,
        string $worktreePath,
        CandidateCheck $candidateCheck,
    ): Delivery {
        return DB::transaction(function () use ($project, $issueId, $issueKey, $worktreePath, $candidateCheck): Delivery {
            $delivery = Delivery::query()->create([
                'project_orchestration_id' => $project->getKey(),
                'external_issue_provider' => 'linear',
                'external_issue_id' => $issueId,
                'external_issue_key' => $issueKey,
                'workflow_type' => ShadowWorkflow::TYPE,
                'workflow_version' => ShadowWorkflow::VERSION,
                'status' => DeliveryStatus::Queued,
                'current_phase' => 'herdr_test',
                'worktree_path' => $worktreePath,
                'candidate_sha' => $candidateCheck->candidateSha,
            ]);

            PhaseRun::query()->create([
                'delivery_id' => $delivery->id,
                'phase_name' => 'herdr_test',
                'attempt' => 1,
                'status' => PhaseRunStatus::Pending,
                'input' => [
                    'candidate_check' => [
                        'receipt_path' => $candidateCheck->receiptPath,
                        'candidate_sha' => $candidateCheck->candidateSha,
                        'tree_sha' => $candidateCheck->treeSha,
                    ],
                ],
            ]);

            return $delivery;
        });
    }
}
