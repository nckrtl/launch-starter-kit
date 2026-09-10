<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\OrbitPlanningHandoff;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\Delivery;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class PrepareOrbitPlanningHandoff
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
    ) {}

    public function handle(int $deliveryId): OrbitPlanningHandoff
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $project = $delivery->projectOrchestration;

        if ($delivery->status->isTerminal()
            || in_array($delivery->status, [DeliveryStatus::Paused, DeliveryStatus::Blocked], true)
            || $project->state !== ProjectOrchestrationState::Enabled) {
            throw new OrbitPlanningHandoffFailed('The delivery is not active and enabled for Orbit planning preparation.');
        }

        try {
            $config = $this->configs->hydrate($project->config);
        } catch (InvalidArgumentException|ValidationException $exception) {
            throw new OrbitPlanningHandoffFailed('The delivery has invalid live project configuration.', 0, $exception);
        }

        if (! $config instanceof OrbitProjectConfig) {
            throw new OrbitPlanningHandoffFailed('The delivery does not use Orbit project configuration.');
        }

        [$worktree, $snapshot, $candidate] = $this->preparation($delivery);
        $reservation = $this->repository->reserveDelivery($config, $snapshot->issueKey);

        try {
            $verified = $this->repository->verifyPlanningHandoff($config, $worktree, $candidate, $snapshot);
            $current = $this->issues->fetch($snapshot->issueId, $snapshot->issueKey);
            $updatedAt = $current->payload['updatedAt'] ?? null;

            if ($current->issueId !== $snapshot->issueId
                || $current->issueKey !== $snapshot->issueKey
                || ! is_string($updatedAt)
                || ! hash_equals($snapshot->contractHash, $current->contractHash)) {
                throw new OrbitIssueContractChanged('The Orbit issue contract changed before planning preparation.');
            }

            return new OrbitPlanningHandoff(
                deliveryId: $delivery->id,
                provider: $snapshot->provider,
                issueId: $current->issueId,
                issueKey: $current->issueKey,
                issue: $current->payload,
                issueUpdatedAt: $updatedAt,
                worktreePath: $verified->worktreePath,
                branch: $verified->branch,
                candidateSha: $verified->candidateSha,
                treeSha: $verified->treeSha,
                qualityReceiptPath: $verified->qualityReceiptPath,
                snapshotPath: $verified->snapshotPath,
                snapshotContentsHash: $verified->snapshotContentsHash,
                contractSchema: $snapshot->contractSchema,
                contractHash: $snapshot->contractHash,
                verifiedAt: now()->toImmutable(),
            );
        } finally {
            $reservation->release();
        }
    }

    /** @return array{PreparedWorktree, PreparedIssueSnapshot, CandidateCheck} */
    private function preparation(Delivery $delivery): array
    {
        $phase = $delivery->phaseRuns()->oldest('id')->first();
        $input = $phase?->input;
        $snapshot = is_array($input) ? ($input['issue_snapshot'] ?? null) : null;
        $candidate = is_array($input) ? ($input['candidate_check'] ?? null) : null;
        $worktreePath = $delivery->worktree_path;
        $candidateSha = $delivery->candidate_sha;
        $issueId = $delivery->external_issue_id;
        $issueKey = $delivery->external_issue_key;

        if ($phase === null || $phase->phase_name !== 'herdr_test' || $phase->attempt !== 1
            || ! is_array($snapshot) || array_is_list($snapshot)
            || ! is_array($candidate) || array_is_list($candidate)
            || ! is_string($worktreePath) || ! is_string($candidateSha)
            || $delivery->workflow_type !== ShadowWorkflow::TYPE
            || $delivery->workflow_version !== ShadowWorkflow::VERSION
            || $delivery->external_issue_provider !== OrbitIssueSnapshot::PROVIDER
            || ! is_string($issueKey)) {
            throw new OrbitPlanningHandoffFailed('The delivery has no valid Orbit preparation record.');
        }

        $preparedSnapshot = new PreparedIssueSnapshot(
            schema: $this->integer($snapshot, 'schema'),
            provider: $this->string($snapshot, 'provider'),
            path: $this->string($snapshot, 'path'),
            contentsHash: $this->string($snapshot, 'contents_sha256'),
            contractSchema: $this->integer($snapshot, 'contract_schema'),
            contractHash: $this->string($snapshot, 'contract_sha256'),
            issueId: $this->string($snapshot, 'issue_id'),
            issueKey: $this->string($snapshot, 'issue_key'),
        );
        $candidateCheck = new CandidateCheck(
            receiptPath: $this->string($candidate, 'receipt_path'),
            candidateSha: $this->string($candidate, 'candidate_sha'),
            treeSha: $this->string($candidate, 'tree_sha'),
        );

        if ($preparedSnapshot->issueId !== $issueId
            || $preparedSnapshot->issueKey !== $issueKey
            || $preparedSnapshot->provider !== $delivery->external_issue_provider
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $issueId) !== 1
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || $preparedSnapshot->path !== rtrim($worktreePath, '/').'/.loop/issue.json'
            || $candidateCheck->candidateSha !== $candidateSha) {
            throw new OrbitPlanningHandoffFailed('The Orbit preparation record does not match its delivery.');
        }

        return [new PreparedWorktree($worktreePath, $candidateSha), $preparedSnapshot, $candidateCheck];
    }

    /** @param array<mixed, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            throw new OrbitPlanningHandoffFailed('The delivery has malformed Orbit preparation metadata.');
        }

        return $value;
    }

    /** @param array<mixed, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value)) {
            throw new OrbitPlanningHandoffFailed('The delivery has malformed Orbit preparation metadata.');
        }

        return $value;
    }
}
