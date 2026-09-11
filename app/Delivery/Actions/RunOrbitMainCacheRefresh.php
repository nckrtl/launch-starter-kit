<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Contracts\OrbitMainCacheRefreshRequester;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\MaintenanceRunStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use App\Models\MaintenanceRun;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;

final readonly class RunOrbitMainCacheRefresh
{
    public function __construct(private OrbitMainCacheRefreshRequester $requests) {}

    public function handle(int $maintenanceRunId): void
    {
        $repository = DB::transaction(function () use ($maintenanceRunId): ?string {
            $run = $this->lockRunLedger($maintenanceRunId);

            if ($run->kind !== QueueOrbitMainCacheRefresh::KIND) {
                throw new OrbitRepositoryFailed('The maintenance run is not an Orbit main cache refresh request.');
            }

            if (in_array($run->status, [MaintenanceRunStatus::Completed, MaintenanceRunStatus::Failed], true)) {
                return null;
            }

            $repository = $this->repository($run);

            if ($run->status === MaintenanceRunStatus::Running) {
                $run->attempt++;
            } else {
                $run->status = MaintenanceRunStatus::Running;
                $run->started_at = now();
            }

            $run->failure_code = null;
            $run->failure_message = null;
            $run->save();

            return $repository;
        });

        if ($repository === null) {
            return;
        }

        $requested = $this->requests->request($repository);

        DB::transaction(function () use ($maintenanceRunId, $repository, $requested): void {
            $run = $this->lockRunLedger($maintenanceRunId);

            if ($run->status === MaintenanceRunStatus::Completed) {
                return;
            }

            if ($run->kind !== QueueOrbitMainCacheRefresh::KIND
                || $run->status !== MaintenanceRunStatus::Running
                || $this->repository($run) !== $repository
                || $requested->repository !== $repository) {
                throw new OrbitRepositoryFailed('The Orbit main cache refresh ledger changed while requesting maintenance.');
            }

            $run->status = MaintenanceRunStatus::Completed;
            $run->result = [
                'repository' => $requested->repository,
                'disposition' => $requested->disposition,
                'message' => $requested->message,
            ];
            $run->finished_at = now();
            $run->save();
        });
    }

    private function repository(MaintenanceRun $run): string
    {
        $input = $run->input;
        $repository = is_array($input) ? ($input['repository'] ?? null) : null;
        $candidateSha = is_array($input) ? ($input['candidate_sha'] ?? null) : null;
        $mergeSha = is_array($input) ? ($input['merge_commit_sha'] ?? null) : null;
        $preMergeMainSha = is_array($input) ? ($input['pre_merge_main_sha'] ?? null) : null;

        if (! is_array($input) || array_keys($input) !== [
            'schema',
            'landing_phase_run_id',
            'repository',
            'candidate_sha',
            'merge_commit_sha',
            'pre_merge_main_sha',
        ]
            || ($input['schema'] ?? null) !== QueueOrbitMainCacheRefresh::SCHEMA
            || ! is_int($input['landing_phase_run_id'] ?? null)
            || ! is_string($repository) || ! str_starts_with($repository, '/')
            || ! is_string($candidateSha) || ! $this->sha($candidateSha)
            || ! is_string($mergeSha) || ! $this->sha($mergeSha)
            || ! is_string($preMergeMainSha) || ! $this->sha($preMergeMainSha)) {
            throw new OrbitRepositoryFailed('The Orbit main cache refresh request is malformed.');
        }

        $delivery = is_int($run->delivery_id)
            ? Delivery::query()->whereKey($run->delivery_id)->lockForUpdate()->first()
            : null;
        $phase = PhaseRun::query()
            ->whereKey($input['landing_phase_run_id'])
            ->lockForUpdate()
            ->first();
        $output = $phase?->output;
        $merge = is_array($output) ? ($output['merge'] ?? null) : null;

        if ($delivery === null || $phase === null
            || $run->project_orchestration_id !== $delivery->project_orchestration_id
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || ! in_array($delivery->status, [
                DeliveryStatus::Landed,
                DeliveryStatus::Cleaning,
                DeliveryStatus::Completed,
            ], true)
            || $delivery->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::LANDING_PHASE
            || $phase->attempt !== 1
            || ! in_array($phase->status, [PhaseRunStatus::Running, PhaseRunStatus::Completed], true)
            || ($phase->status === PhaseRunStatus::Running
                && ! in_array($phase->current_block, [
                    'repository_reconciliation',
                    'workspace_shutdown',
                    'proof_closeout',
                    'worktree_cleanup',
                    'reservation_release',
                ], true))
            || ($phase->status === PhaseRunStatus::Completed
                && ($phase->current_block !== null || $phase->finished_at === null))
            || ! is_array($merge)
            || ($output['repository'] ?? null) !== $repository
            || ($output['main_sha'] ?? null) !== $preMergeMainSha
            || ($merge['candidate_sha'] ?? null) !== $candidateSha
            || ($merge['merge_commit_sha'] ?? null) !== $mergeSha
            || ! $this->matchesMergeVerification(
                $output,
                $candidateSha,
                $mergeSha,
            )) {
            throw new OrbitRepositoryFailed('The Orbit main cache refresh request no longer matches its landing ledger.');
        }

        return $repository;
    }

    /** @param array<string, mixed>|null $output */
    private function matchesMergeVerification(?array $output, string $candidateSha, string $mergeSha): bool
    {
        $verification = is_array($output) ? ($output['merge_verification'] ?? null) : null;

        return is_array($verification) && ! array_is_list($verification)
            && count($verification) === 4
            && in_array($verification['flow'] ?? null, ['discovery', 'proof'], true)
            && ($verification['candidate_sha'] ?? null) === $candidateSha
            && ($verification['merge_commit_sha'] ?? null) === $mergeSha
            && $this->sha($verification['tree_sha'] ?? null);
    }

    private function lockRunLedger(int $maintenanceRunId): MaintenanceRun
    {
        $snapshot = MaintenanceRun::query()->findOrFail($maintenanceRunId);
        $input = $snapshot->input;
        $phaseRunId = is_array($input) ? ($input['landing_phase_run_id'] ?? null) : null;

        if (is_int($snapshot->delivery_id)) {
            Delivery::query()->whereKey($snapshot->delivery_id)->lockForUpdate()->first();
        }

        if (is_int($phaseRunId)) {
            PhaseRun::query()->whereKey($phaseRunId)->lockForUpdate()->first();
        }

        return MaintenanceRun::query()->whereKey($maintenanceRunId)->lockForUpdate()->firstOrFail();
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{40}$/', $value) === 1;
    }
}
