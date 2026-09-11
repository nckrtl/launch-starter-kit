<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Exceptions\OrbitPlanningReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Models\AgentDispatch;
use App\Models\PhaseRun;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;

final readonly class CaptureOrbitPlanningReceipt
{
    /** @param array<string, mixed> $payload */
    public function handle(PhaseRun $phaseRun, AgentDispatch $dispatch, array $payload): Receipt
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $receipt = DB::transaction(function () use ($phaseRun, $dispatch, $payload, $hash): Receipt {
            $lockedPhase = PhaseRun::query()->with('delivery')->lockForUpdate()->find($phaseRun->id);
            $lockedDispatch = AgentDispatch::query()
                ->whereKey($dispatch->id)
                ->where('phase_run_id', $phaseRun->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPhase === null || $lockedDispatch === null
                || $lockedPhase->delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $lockedPhase->delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
                || $lockedPhase->delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $lockedPhase->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
                || $lockedPhase->status !== PhaseRunStatus::Running
                || (! ($lockedDispatch->status === AgentDispatchStatus::Starting
                    && $lockedDispatch->error_code === 'herdr_prompt_attempted')
                    && ! in_array($lockedDispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))
                || ! $this->matchesLedger($lockedPhase, $lockedDispatch, $payload)) {
                throw new OrbitPlanningReceiptFailed('The planning receipt no longer matches the active dispatch.');
            }

            $existing = Receipt::query()
                ->where('phase_run_id', $lockedPhase->id)
                ->where('kind', 'orbit_planning')
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    throw new OrbitPlanningReceiptFailed('A different planning receipt was already captured for this phase.');
                }

                return $existing;
            }

            return Receipt::query()->create([
                'phase_run_id' => $lockedPhase->id,
                'kind' => 'orbit_planning',
                'schema_version' => 1,
                'payload' => $payload,
                'payload_hash' => $hash,
                'candidate_sha' => $payload['candidate_sha'],
                'validation_status' => ReceiptValidationStatus::Valid,
                'validation_errors' => null,
                'captured_at' => now(),
                'validated_at' => now(),
            ]);
        });

        AdvanceDelivery::dispatch($phaseRun->delivery_id)->afterCommit();

        return $receipt;
    }

    /** @param array<string, mixed> $payload */
    private function matchesLedger(PhaseRun $phaseRun, AgentDispatch $dispatch, array $payload): bool
    {
        $delivery = $phaseRun->delivery;
        $allowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'artifact_sha', 'plan_sha256',
        ];

        return array_diff(array_keys($payload), $allowed) === []
            && count($payload) === count($allowed)
            && ($payload['kind'] ?? null) === 'orbit_planning'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === $phaseRun->phase_name
            && ($payload['attempt'] ?? null) === $phaseRun->attempt
            && in_array($payload['result'] ?? null, ['ready', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && is_string($payload['candidate_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['candidate_sha']) === 1
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($payload['handoff'] ?? null)
            && trim($payload['handoff']) !== ''
            && $this->matchesArtifact($payload);
    }

    /** @param array<string, mixed> $payload */
    private function matchesArtifact(array $payload): bool
    {
        if (($payload['result'] ?? null) === 'blocked') {
            return ($payload['artifact_sha'] ?? null) === null
                && ($payload['plan_sha256'] ?? null) === null;
        }

        return is_string($payload['artifact_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $payload['artifact_sha']) === 1
            && is_string($payload['plan_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $payload['plan_sha256']) === 1;
    }
}
