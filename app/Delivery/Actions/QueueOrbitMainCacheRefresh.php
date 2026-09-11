<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\MaintenanceRunStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitLandingAdvancementFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\RunOrbitMainCacheRefresh;
use App\Models\Delivery;
use App\Models\MaintenanceRun;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;

final readonly class QueueOrbitMainCacheRefresh
{
    public const string KIND = 'orbit_main_cache_refresh_enqueue';

    public const int SCHEMA = 1;

    public function handle(int $deliveryId, int $phaseRunId): MaintenanceRun
    {
        return DB::transaction(function () use ($deliveryId, $phaseRunId): MaintenanceRun {
            $delivery = Delivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            $phase = PhaseRun::query()->whereKey($phaseRunId)->lockForUpdate()->firstOrFail();
            $input = $this->input($delivery, $phase);
            $idempotencyKey = hash('sha256', implode('|', [
                self::KIND,
                $delivery->id,
                $phase->id,
                $input['merge_commit_sha'],
            ]));
            $identity = [
                'project_orchestration_id' => $delivery->project_orchestration_id,
                'delivery_id' => $delivery->id,
                'kind' => self::KIND,
                'input' => $input,
            ];
            $run = MaintenanceRun::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    ...$identity,
                    'status' => MaintenanceRunStatus::Pending,
                    'attempt' => 1,
                ],
            );

            if ($run->only(array_keys($identity)) !== $identity) {
                throw new OrbitLandingAdvancementFailed(
                    'The retained Orbit main cache refresh request is inconsistent.',
                );
            }

            if ($run->wasRecentlyCreated) {
                RunOrbitMainCacheRefresh::dispatch($run->id)->afterCommit();
            }

            return $run;
        });
    }

    /** @return array{schema: int, landing_phase_run_id: int, repository: string, candidate_sha: string, merge_commit_sha: string, pre_merge_main_sha: string} */
    private function input(Delivery $delivery, PhaseRun $phase): array
    {
        $output = $phase->output;
        $merge = is_array($output) ? ($output['merge'] ?? null) : null;
        $repository = is_array($output) ? ($output['repository'] ?? null) : null;
        $mainSha = is_array($output) ? ($output['main_sha'] ?? null) : null;
        $candidateSha = is_array($merge) ? ($merge['candidate_sha'] ?? null) : null;
        $mergeSha = is_array($merge) ? ($merge['merge_commit_sha'] ?? null) : null;
        $validStatus = in_array($delivery->status, [
            DeliveryStatus::Landed,
            DeliveryStatus::Cleaning,
            DeliveryStatus::Completed,
        ], true);

        if (! $validStatus
            || $delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $delivery->current_phase !== OrbitFeatureWorkflow::LANDING_PHASE
            || $phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::LANDING_PHASE
            || $phase->attempt !== 1
            || ($phase->status !== PhaseRunStatus::Running
                && $phase->status !== PhaseRunStatus::Completed)
            || ($phase->status === PhaseRunStatus::Running
                && ! in_array($phase->current_block, [
                    'repository_reconciliation',
                    'workspace_shutdown',
                    'proof_closeout',
                    'reservation_release',
                ], true))
            || ($phase->status === PhaseRunStatus::Completed
                && ($phase->current_block !== null || $phase->finished_at === null))
            || ! is_string($repository) || ! str_starts_with($repository, '/')
            || ! is_string($mainSha) || preg_match('/^[a-f0-9]{40}$/', $mainSha) !== 1
            || ! is_string($candidateSha) || $candidateSha !== $delivery->candidate_sha
            || preg_match('/^[a-f0-9]{40}$/', $candidateSha) !== 1
            || ! is_string($mergeSha) || preg_match('/^[a-f0-9]{40}$/', $mergeSha) !== 1
            || ! $this->matchesMergeVerification($output, $candidateSha, $mergeSha)) {
            throw new OrbitLandingAdvancementFailed(
                'The confirmed Orbit merge cannot queue main cache maintenance.',
            );
        }

        return [
            'schema' => self::SCHEMA,
            'landing_phase_run_id' => $phase->id,
            'repository' => $repository,
            'candidate_sha' => $candidateSha,
            'merge_commit_sha' => $mergeSha,
            'pre_merge_main_sha' => $mainSha,
        ];
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
            && is_string($verification['tree_sha'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $verification['tree_sha']) === 1;
    }
}
