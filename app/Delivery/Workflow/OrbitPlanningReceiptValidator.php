<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Enums\ReceiptValidationStatus;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitPlanningReceiptValidator
{
    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
    ): bool {
        $payload = $receipt->payload;
        $allowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'artifact_sha', 'plan_sha256',
        ];
        $result = $payload['result'] ?? null;
        $artifactMatches = $result === 'blocked'
            ? ($payload['artifact_sha'] ?? null) === null && ($payload['plan_sha256'] ?? null) === null
            : is_string($payload['artifact_sha'] ?? null)
                && preg_match('/^[a-f0-9]{40}$/', $payload['artifact_sha']) === 1
                && is_string($payload['plan_sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', $payload['plan_sha256']) === 1;

        return array_diff(array_keys($payload), $allowed) === []
            && count($payload) === count($allowed)
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && hash_equals($receipt->payload_hash, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)))
            && $receipt->candidate_sha === ($payload['candidate_sha'] ?? null)
            && ($payload['kind'] ?? null) === 'orbit_planning'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === $phase->phase_name
            && ($payload['attempt'] ?? null) === $phase->attempt
            && in_array($result, ['ready', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && is_string($payload['candidate_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['candidate_sha']) === 1
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($payload['handoff'] ?? null)
            && trim($payload['handoff']) !== ''
            && $artifactMatches;
    }
}
