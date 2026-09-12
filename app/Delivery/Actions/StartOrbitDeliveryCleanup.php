<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitDeliveryCleanupFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Jobs\AdvanceDelivery;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class StartOrbitDeliveryCleanup
{
    public function __construct(private ProjectConfigRegistry $configs) {}

    public function handle(int $deliveryId): PhaseRun
    {
        $phase = DB::transaction(function () use ($deliveryId): PhaseRun {
            $delivery = Delivery::query()
                ->with('projectOrchestration')
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                throw new OrbitDeliveryCleanupFailed("Delivery [{$deliveryId}] does not exist.");
            }

            if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
                || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION) {
                throw new OrbitDeliveryCleanupFailed('The delivery is not an Orbit feature workflow.');
            }

            $existing = $delivery->phaseRuns()
                ->where('phase_name', OrbitFeatureWorkflow::CLEANUP_PHASE)
                ->where('attempt', 1)
                ->lockForUpdate()
                ->first();

            if ($delivery->status === DeliveryStatus::Cleaning
                && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
                && $existing !== null
                && $this->matchesActiveCleanup($delivery, $existing)) {
                return $existing;
            }

            if ($delivery->status !== DeliveryStatus::Blocked
                || $delivery->current_phase === OrbitFeatureWorkflow::LANDING_PHASE
                || $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
                || $existing !== null
                || $delivery->completed_at !== null
                || $delivery->failed_at !== null) {
                throw new OrbitDeliveryCleanupFailed(
                    'Only a blocked pre-merge Orbit delivery can start cleanup.',
                );
            }

            try {
                $config = $this->configs->hydrate($delivery->projectOrchestration->config);
            } catch (InvalidArgumentException|ValidationException $exception) {
                throw new OrbitDeliveryCleanupFailed(
                    'The blocked Orbit delivery project configuration is invalid.',
                    previous: $exception,
                );
            }
            $issueKey = $delivery->external_issue_key;
            $sourceId = $delivery->failure_details['phase_run_id'] ?? null;
            $source = is_int($sourceId)
                ? $delivery->phaseRuns()->whereKey($sourceId)->lockForUpdate()->first()
                : null;
            $abortable = $source !== null
                && $source->status === PhaseRunStatus::Failed
                && $source->finished_at !== null;
            $restartable = $source !== null
                && $delivery->current_phase === OrbitFeatureWorkflow::RESOLUTION_PHASE
                && ($delivery->failure_details['code'] ?? null) === 'planning_resolution_cleanup_ready'
                && $source->status === PhaseRunStatus::Completed
                && $source->current_block === null
                && is_array($source->output)
                && ($source->output['planning_resolution_correction'] ?? null)
                    === ($delivery->failure_details['correction'] ?? null)
                && $source->finished_at !== null;

            if (! $config instanceof OrbitProjectConfig
                || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
                || ! is_string($issueKey) || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
                || $delivery->branch !== strtolower($issueKey)
                || $delivery->worktree_path !== rtrim($config->worktreeRoot, '/').'/'.strtolower($issueKey)
                || ! is_string($delivery->candidate_sha)
                || preg_match('/^[a-f0-9]{40}$/', $delivery->candidate_sha) !== 1
                || ! is_array($delivery->failure_details)
                || $source === null
                || $source->phase_name !== $delivery->current_phase
                || (! $abortable && ! $restartable)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The blocked Orbit delivery does not have a complete pre-merge cleanup ledger.',
                );
            }

            $phase = PhaseRun::query()->create([
                'delivery_id' => $delivery->id,
                'phase_name' => OrbitFeatureWorkflow::CLEANUP_PHASE,
                'attempt' => 1,
                'status' => PhaseRunStatus::Pending,
                'input' => $this->cleanupInput($delivery, $source, $config),
            ]);

            $delivery->status = DeliveryStatus::Cleaning;
            $delivery->current_phase = OrbitFeatureWorkflow::CLEANUP_PHASE;
            $delivery->save();

            return $phase;
        });

        AdvanceDelivery::dispatch($deliveryId)->afterCommit();

        return $phase;
    }

    /** @return array<string, mixed> */
    private function cleanupInput(
        Delivery $delivery,
        PhaseRun $source,
        OrbitProjectConfig $config,
    ): array {
        return [
            'repository' => $config->repository,
            'issue_key' => $delivery->external_issue_key,
            'worktree' => $delivery->worktree_path,
            'branch' => $delivery->branch,
            'candidate_sha' => $delivery->candidate_sha,
            'source' => [
                'delivery_status' => DeliveryStatus::Blocked->value,
                'current_phase' => $source->phase_name,
                'delivery_failure_details' => $delivery->failure_details,
                'phase_run_id' => $source->id,
                'phase_name' => $source->phase_name,
                'attempt' => $source->attempt,
                'status' => $source->status->value,
                'current_block' => $source->current_block,
                'output' => $source->output,
                'failure_code' => $source->failure_code,
                'failure_message' => $source->failure_message,
                'failure_details' => $source->failure_details,
            ],
        ];
    }

    private function matchesActiveCleanup(Delivery $delivery, PhaseRun $phase): bool
    {
        $input = $phase->input;
        $validState = match ($phase->status) {
            PhaseRunStatus::Pending => $phase->current_block === null
                && $phase->output === null
                && $phase->started_at === null
                && $phase->finished_at === null,
            PhaseRunStatus::Running => in_array(
                $phase->current_block,
                ['workspace_shutdown', 'worktree_cleanup'],
                true,
            )
                && is_array($phase->output)
                && ($phase->output['repository'] ?? null) === ($input['repository'] ?? null)
                && $phase->started_at !== null
                && $phase->finished_at === null,
            default => false,
        };

        return $phase->delivery_id === $delivery->id
            && $validState
            && is_array($input)
            && ($input['issue_key'] ?? null) === $delivery->external_issue_key
            && ($input['worktree'] ?? null) === $delivery->worktree_path
            && ($input['branch'] ?? null) === $delivery->branch
            && ($input['candidate_sha'] ?? null) === $delivery->candidate_sha
            && is_array($input['source'] ?? null);
    }
}
