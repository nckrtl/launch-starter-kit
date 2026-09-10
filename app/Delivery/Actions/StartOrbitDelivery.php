<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\VerifiedIssueSnapshot;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\ProjectOrchestration;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class StartOrbitDelivery
{
    public function __construct(private ProjectConfigRegistry $configs) {}

    public function handle(
        ProjectOrchestration $project,
        VerifiedIssueSnapshot $verifiedIssue,
        string $worktreePath,
        CandidateCheck $candidateCheck,
    ): Delivery {
        $issueSnapshot = $verifiedIssue->snapshot;

        try {
            $config = $this->configs->hydrate($project->config);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw new InvalidArgumentException('The Orbit delivery project configuration is invalid.', 0, $exception);
        }

        $expectedWorktree = $config instanceof OrbitProjectConfig
            ? rtrim($config->worktreeRoot, '/').'/'.strtolower($issueSnapshot->issueKey)
            : null;
        $expectedReceiptDirectory = $config instanceof OrbitProjectConfig
            ? rtrim($config->repository, '/').'/.git/orbit-checks/'.$candidateCheck->candidateSha.'/'
            : null;

        if ($project->state !== ProjectOrchestrationState::Enabled
            || ! $config instanceof OrbitProjectConfig
            || $config->defaultFlow !== 'discovery'
            || $issueSnapshot->schema !== OrbitIssueSnapshot::SCHEMA
            || $issueSnapshot->provider !== OrbitIssueSnapshot::PROVIDER
            || $issueSnapshot->contractSchema !== OrbitIssueSnapshot::CONTRACT_SCHEMA
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $issueSnapshot->issueId) !== 1
            || preg_match('/^ORB-[0-9]+$/', $issueSnapshot->issueKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $issueSnapshot->contentsHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $issueSnapshot->contractHash) !== 1
            || ! str_starts_with($worktreePath, '/')
            || $worktreePath !== $expectedWorktree
            || $issueSnapshot->path !== rtrim($worktreePath, '/').'/.loop/issue.json'
            || ! is_string($expectedReceiptDirectory)
            || ! str_starts_with($candidateCheck->receiptPath, $expectedReceiptDirectory)
            || preg_match('/^[a-f0-9]{40}$/', $candidateCheck->candidateSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $candidateCheck->treeSha) !== 1) {
            throw new InvalidArgumentException('The prepared Orbit delivery inputs are inconsistent.');
        }

        return DB::transaction(function () use ($project, $verifiedIssue, $issueSnapshot, $worktreePath, $candidateCheck): Delivery {
            $delivery = Delivery::query()->create([
                'project_orchestration_id' => $project->getKey(),
                'external_issue_provider' => $issueSnapshot->provider,
                'external_issue_id' => $issueSnapshot->issueId,
                'external_issue_key' => $issueSnapshot->issueKey,
                'workflow_type' => OrbitFeatureWorkflow::TYPE,
                'workflow_version' => OrbitFeatureWorkflow::VERSION,
                'status' => DeliveryStatus::Preparing,
                'current_phase' => OrbitFeatureWorkflow::INITIAL_PHASE,
                'branch' => strtolower($issueSnapshot->issueKey),
                'worktree_path' => $worktreePath,
                'candidate_sha' => $candidateCheck->candidateSha,
            ]);

            PhaseRun::query()->create([
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::INITIAL_PHASE,
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
                        'verified_at' => $verifiedIssue->verifiedAt->toISOString(),
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
