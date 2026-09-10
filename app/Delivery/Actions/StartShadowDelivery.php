<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class StartShadowDelivery
{
    public function handle(
        ProjectOrchestration $project,
        PreparedIssueSnapshot $issueSnapshot,
        string $worktreePath,
        CandidateCheck $candidateCheck,
    ): Delivery {
        if ($issueSnapshot->schema !== OrbitIssueSnapshot::SCHEMA
            || $issueSnapshot->provider !== OrbitIssueSnapshot::PROVIDER
            || $issueSnapshot->contractSchema !== OrbitIssueSnapshot::CONTRACT_SCHEMA
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $issueSnapshot->issueId) !== 1
            || preg_match('/^ORB-[0-9]+$/', $issueSnapshot->issueKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $issueSnapshot->contentsHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $issueSnapshot->contractHash) !== 1
            || ! str_starts_with($worktreePath, '/')
            || $issueSnapshot->path !== rtrim($worktreePath, '/').'/.loop/issue.json'
            || preg_match('/^[a-f0-9]{40}$/', $candidateCheck->candidateSha) !== 1) {
            throw new InvalidArgumentException('The prepared shadow delivery inputs are inconsistent.');
        }

        return DB::transaction(function () use ($project, $issueSnapshot, $worktreePath, $candidateCheck): Delivery {
            $delivery = Delivery::query()->create([
                'project_orchestration_id' => $project->getKey(),
                'external_issue_provider' => $issueSnapshot->provider,
                'external_issue_id' => $issueSnapshot->issueId,
                'external_issue_key' => $issueSnapshot->issueKey,
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
                    'issue_snapshot' => [
                        'schema' => $issueSnapshot->schema,
                        'provider' => $issueSnapshot->provider,
                        'issue_id' => $issueSnapshot->issueId,
                        'issue_key' => $issueSnapshot->issueKey,
                        'path' => $issueSnapshot->path,
                        'contents_sha256' => $issueSnapshot->contentsHash,
                        'contract_schema' => $issueSnapshot->contractSchema,
                        'contract_sha256' => $issueSnapshot->contractHash,
                    ],
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
