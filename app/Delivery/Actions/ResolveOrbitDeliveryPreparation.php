<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Data\CandidateCheck;
use App\Delivery\Data\OrbitDeliveryPreparation;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Data\PreparedIssueSnapshot;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\Delivery;

final readonly class ResolveOrbitDeliveryPreparation
{
    public function handle(Delivery $delivery): OrbitDeliveryPreparation
    {
        $preparation = $this->startup($delivery);

        if ($preparation->candidate->candidateSha !== $delivery->candidate_sha) {
            throw new OrbitPlanningHandoffFailed('The Orbit preparation record does not match its delivery.');
        }

        return $preparation;
    }

    public function startup(Delivery $delivery): OrbitDeliveryPreparation
    {
        $phase = $delivery->phaseRuns()->oldest('id')->first();
        $input = $phase?->input;
        $snapshot = is_array($input) ? ($input['issue_snapshot'] ?? null) : null;
        $candidate = is_array($input) ? ($input['candidate_check'] ?? null) : null;
        $worktreePath = $delivery->worktree_path;
        $candidateSha = $delivery->candidate_sha;
        $issueId = $delivery->external_issue_id;
        $issueKey = $delivery->external_issue_key;
        $validWorkflow = $phase !== null && (
            ($delivery->workflow_type === ShadowWorkflow::TYPE
                && $delivery->workflow_version === ShadowWorkflow::VERSION
                && $phase->phase_name === 'herdr_test')
            || ($delivery->workflow_type === OrbitFeatureWorkflow::TYPE
                && $delivery->workflow_version === OrbitFeatureWorkflow::VERSION
                && $delivery->branch === strtolower((string) $issueKey)
                && $phase->phase_name === OrbitFeatureWorkflow::INITIAL_PHASE)
        );

        if ($phase === null || ! $validWorkflow || $phase->attempt !== 1
            || ! is_array($snapshot) || array_is_list($snapshot)
            || ! is_array($candidate) || array_is_list($candidate)
            || ! is_string($worktreePath) || ! is_string($candidateSha)
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
            || $preparedSnapshot->path !== rtrim($worktreePath, '/').'/.loop/issue.json') {
            throw new OrbitPlanningHandoffFailed('The Orbit preparation record does not match its delivery.');
        }

        return new OrbitDeliveryPreparation(
            new PreparedWorktree($worktreePath, $candidateCheck->candidateSha),
            $preparedSnapshot,
            $candidateCheck,
        );
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
