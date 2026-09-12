<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitAbandonedWorktreeCleaner;
use App\Delivery\Data\CleanedOrbitAbandonedWorktree;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedOrbitAbandonedWorktreeCleanup;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitDeliveryCleanupFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\Delivery;
use App\Models\PhaseRun;
use Illuminate\Support\Facades\DB;

final readonly class AdvanceOrbitCleanup
{
    public const int RETRY_SECONDS = 5;

    public function __construct(
        private ProjectConfigRegistry $configs,
        private ShutdownOrbitHerdrWorkspace $workspaceShutdown,
        private OrbitAbandonedWorktreeCleaner $worktreeCleaner,
    ) {}

    /** Return a delay when owned Herdr agents are still exiting. */
    public function handle(int $deliveryId, ?int $expectedPhaseId = null): ?int
    {
        [$delivery, $phase] = $this->cleanupIntent($deliveryId, $expectedPhaseId);

        if ($delivery->status === DeliveryStatus::Failed) {
            return null;
        }

        $config = $this->configs->hydrate($delivery->projectOrchestration->config);
        $input = $phase->input;

        if (! $config instanceof OrbitProjectConfig
            || $delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || ! is_array($input)
            || ($input['repository'] ?? null) !== $config->repository) {
            throw new OrbitDeliveryCleanupFailed(
                'The Orbit project is not enabled with valid cleanup configuration.',
            );
        }

        if ($phase->status === PhaseRunStatus::Pending) {
            $this->startCleanup($delivery->id, $phase->id, $config);
            $phase = PhaseRun::query()->findOrFail($phase->id);
        }

        if ($phase->current_block === 'workspace_shutdown') {
            if (! $this->workspaceShutdown->handle($config, $delivery->id, $phase->id)) {
                return self::RETRY_SECONDS;
            }

            $this->recordWorkspaceShutdown($delivery->id, $phase->id);
            $phase = PhaseRun::query()->findOrFail($phase->id);
        }

        if ($phase->current_block === 'worktree_cleanup') {
            $this->cleanupWorktree($config, $delivery->id, $phase->id);
        }

        $this->terminalize($delivery->id, $phase->id);

        return null;
    }

    /** @return array{Delivery, PhaseRun} */
    private function cleanupIntent(int $deliveryId, ?int $expectedPhaseId): array
    {
        $delivery = Delivery::query()->with('projectOrchestration')->findOrFail($deliveryId);
        $phase = $delivery->phaseRuns()
            ->where('phase_name', OrbitFeatureWorkflow::CLEANUP_PHASE)
            ->where('attempt', 1)
            ->first();

        if ($delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phase === null
            || ($expectedPhaseId !== null && $phase->id !== $expectedPhaseId)
            || ! $this->matchesCleanupLedger($delivery, $phase)) {
            throw new OrbitDeliveryCleanupFailed(
                'The retained Orbit delivery cleanup intent is inconsistent.',
            );
        }

        return [$delivery, $phase];
    }

    private function matchesCleanupLedger(Delivery $delivery, PhaseRun $phase): bool
    {
        $input = $phase->input;
        $source = is_array($input) ? ($input['source'] ?? null) : null;
        $sourcePhaseId = is_array($source) ? ($source['phase_run_id'] ?? null) : null;
        $sourcePhase = is_int($sourcePhaseId) ? PhaseRun::query()->find($sourcePhaseId) : null;
        $legacySourceKeys = [
            'delivery_status', 'current_phase', 'delivery_failure_details',
            'phase_run_id', 'phase_name', 'attempt', 'status', 'failure_code',
            'failure_message', 'failure_details',
        ];
        $sourceKeys = [
            'delivery_status', 'current_phase', 'delivery_failure_details',
            'phase_run_id', 'phase_name', 'attempt', 'status', 'current_block', 'output', 'failure_code',
            'failure_message', 'failure_details',
        ];
        $retainsSourceState = is_array($source) && array_keys($source) === $sourceKeys;

        if (! is_array($input) || array_keys($input) !== [
            'repository', 'issue_key', 'worktree', 'branch', 'candidate_sha', 'source',
        ]
            || ! is_array($source)
            || (! $retainsSourceState && array_keys($source) !== $legacySourceKeys)
            || ($input['issue_key'] ?? null) !== $delivery->external_issue_key
            || ($input['worktree'] ?? null) !== $delivery->worktree_path
            || ($input['branch'] ?? null) !== $delivery->branch
            || ($input['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || ($source['delivery_status'] ?? null) !== DeliveryStatus::Blocked->value
            || $sourcePhase === null || $sourcePhase->delivery_id !== $delivery->id
            || $sourcePhase->phase_name !== ($source['phase_name'] ?? null)
            || $sourcePhase->phase_name !== ($source['current_phase'] ?? null)
            || $sourcePhase->attempt !== ($source['attempt'] ?? null)
            || $sourcePhase->status->value !== ($source['status'] ?? null)
            || ($retainsSourceState && $sourcePhase->current_block !== ($source['current_block'] ?? null))
            || ($retainsSourceState && $sourcePhase->output !== ($source['output'] ?? null))
            || $sourcePhase->failure_code !== ($source['failure_code'] ?? null)
            || $sourcePhase->failure_message !== ($source['failure_message'] ?? null)
            || $sourcePhase->failure_details !== ($source['failure_details'] ?? null)
            || $sourcePhase->finished_at === null) {
            return false;
        }

        $sourceFailure = $source['delivery_failure_details'];

        if (! is_array($sourceFailure)) {
            return false;
        }

        $abortable = $sourcePhase->status === PhaseRunStatus::Failed;
        $restartable = $retainsSourceState
            && $sourcePhase->status === PhaseRunStatus::Completed
            && $source['current_phase'] === OrbitFeatureWorkflow::RESOLUTION_PHASE
            && ($sourceFailure['code'] ?? null) === 'planning_resolution_cleanup_ready'
            && $sourcePhase->current_block === null
            && is_array($sourcePhase->output)
            && ($sourcePhase->output['planning_resolution_correction'] ?? null)
                === ($sourceFailure['correction'] ?? null);

        if (! $abortable && ! $restartable) {
            return false;
        }

        if ($delivery->status === DeliveryStatus::Cleaning
            && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE) {
            if ($phase->status === PhaseRunStatus::Pending) {
                return $phase->current_block === null && $phase->output === null
                    && $phase->started_at === null && $phase->finished_at === null;
            }

            return $phase->status === PhaseRunStatus::Running
                && in_array($phase->current_block, ['workspace_shutdown', 'worktree_cleanup'], true)
                && is_array($phase->output) && $phase->started_at !== null
                && $phase->finished_at === null;
        }

        return $delivery->status === DeliveryStatus::Failed
            && $delivery->current_phase === OrbitFeatureWorkflow::CLEANUP_PHASE
            && $phase->status === PhaseRunStatus::Completed
            && $phase->current_block === null
            && is_array($phase->output)
            && $phase->started_at !== null
            && $phase->finished_at !== null
            && ($delivery->failure_details['code'] ?? null) === (
                $restartable ? 'planning_resolution_restarted' : 'delivery_aborted'
            )
            && ($delivery->failure_details['cleanup_phase_run_id'] ?? null) === $phase->id;
    }

    private function startCleanup(int $deliveryId, int $phaseId, OrbitProjectConfig $config): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId, $config): void {
            [$delivery, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);

            if ($phase->status === PhaseRunStatus::Running) {
                return;
            }

            if ($phase->status !== PhaseRunStatus::Pending
                || $phase->current_block !== null || $phase->output !== null
                || $phase->started_at !== null || $phase->finished_at !== null) {
                throw new OrbitDeliveryCleanupFailed(
                    'The Orbit delivery cleanup could not be started from its retained intent.',
                );
            }

            $phase->status = PhaseRunStatus::Running;
            $phase->current_block = 'workspace_shutdown';
            $phase->output = ['repository' => $config->repository];
            $phase->started_at = now();
            $phase->save();
            $delivery->save();
        });
    }

    private function recordWorkspaceShutdown(int $deliveryId, int $phaseId): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId): void {
            [, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);
            $output = $phase->output;
            $shutdown = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;
            $closed = is_array($shutdown) ? ($shutdown['closed'] ?? null) : null;

            if ($phase->current_block === 'worktree_cleanup' && is_array($closed)) {
                return;
            }

            if ($phase->current_block !== 'workspace_shutdown'
                || ! is_array($output) || ! is_array($shutdown) || ! is_array($closed)
                || ($closed['workspace_id'] ?? null) !== ($shutdown['workspace_id'] ?? null)
                || ($closed['worktree_path'] ?? null) !== ($shutdown['worktree_path'] ?? null)
                || ! is_string($closed['verified_at'] ?? null)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The verified Orbit workspace cleanup no longer matches its ledger.',
                );
            }

            $phase->current_block = 'worktree_cleanup';
            $phase->save();
        });
    }

    private function cleanupWorktree(
        OrbitProjectConfig $config,
        int $deliveryId,
        int $phaseId,
    ): void {
        [$delivery, $phase] = $this->cleanupIntent($deliveryId, $phaseId);
        $output = $phase->output;
        $intentValue = is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null;

        if ($intentValue === null) {
            $intent = $this->recordWorktreeIntent($deliveryId, $phaseId, $config);
        } else {
            $intent = $intentValue;
        }

        $intent = $this->validatedWorktreeIntent($intent, $delivery, $config->repository);

        $authorizationValue = $phase->fresh()->output['worktree_cleanup_authorization'] ?? null;
        $resume = $authorizationValue !== null;

        if ($authorizationValue === null) {
            $authorization = $this->worktreeCleaner->prepareAbandonedWorktreeCleanup(
                $config,
                $this->requiredString($intent, 'issue_key'),
                $this->requiredString($intent, 'worktree'),
                $this->requiredString($intent, 'branch'),
                $this->requiredSha($intent, 'candidate_sha'),
                $this->requiredAttemptId($intent),
            );
            $this->recordWorktreeAuthorization($deliveryId, $phaseId, $intent, $authorization);
        } elseif (($authorizationArray = $this->associativeArray($authorizationValue)) !== null) {
            try {
                $authorization = PreparedOrbitAbandonedWorktreeCleanup::fromArray($authorizationArray);
            } catch (\InvalidArgumentException $exception) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup authorization is malformed.',
                    previous: $exception,
                );
            }
        } else {
            throw new OrbitDeliveryCleanupFailed(
                'The abandoned worktree cleanup authorization is malformed.',
            );
        }

        $this->assertAuthorizationMatchesIntent($intent, $authorization);

        $latestOutput = PhaseRun::query()->findOrFail($phase->id)->output;

        if (is_array($latestOutput) && array_key_exists('worktree_cleanup', $latestOutput)) {
            $cleanedValue = $this->associativeArray($latestOutput['worktree_cleanup']);

            if ($cleanedValue === null) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup evidence is malformed.',
                );
            }

            try {
                $cleaned = CleanedOrbitAbandonedWorktree::fromArray($cleanedValue);
            } catch (\InvalidArgumentException $exception) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup evidence is malformed.',
                    previous: $exception,
                );
            }

            $this->assertCleanupMatchesAuthorization($authorization, $cleaned);

            return;
        }

        $cleaned = $this->worktreeCleaner->cleanupAbandonedWorktree(
            $config,
            $this->requiredString($intent, 'issue_key'),
            $this->requiredString($intent, 'worktree'),
            $this->requiredString($intent, 'branch'),
            $this->requiredSha($intent, 'candidate_sha'),
            $this->requiredAttemptId($intent),
            $authorization,
            $resume,
        );
        $this->recordWorktreeCleanup($deliveryId, $phaseId, $intent, $authorization, $cleaned);
    }

    /** @return array<string, mixed> */
    private function recordWorktreeIntent(
        int $deliveryId,
        int $phaseId,
        OrbitProjectConfig $config,
    ): array {
        return DB::transaction(function () use ($deliveryId, $phaseId, $config): array {
            [$delivery, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);
            $output = $phase->output;
            $existing = is_array($output) ? ($output['worktree_cleanup_intent'] ?? null) : null;

            if (is_array($existing)) {
                return $existing;
            }

            $intent = [
                'schema' => 1,
                'attempt_id' => bin2hex(random_bytes(16)),
                'repository' => $config->repository,
                'issue_key' => $delivery->external_issue_key,
                'worktree' => $delivery->worktree_path,
                'branch' => $delivery->branch,
                'candidate_sha' => $delivery->candidate_sha,
                'started_at' => now()->toISOString(),
            ];

            if ($phase->current_block !== 'worktree_cleanup'
                || ! is_array($output)
                || array_key_exists('worktree_cleanup_authorization', $output)
                || array_key_exists('worktree_cleanup', $output)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup intent could not be recorded.',
                );
            }

            $phase->output = [...$output, 'worktree_cleanup_intent' => $intent];
            $phase->save();

            return $intent;
        });
    }

    /** @param array<string, mixed> $intent */
    private function recordWorktreeAuthorization(
        int $deliveryId,
        int $phaseId,
        array $intent,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $intent, $authorization): void {
            [, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);
            $output = $phase->output;

            if ($phase->current_block !== 'worktree_cleanup'
                || ! is_array($output)
                || ($output['worktree_cleanup_intent'] ?? null) !== $intent
                || array_key_exists('worktree_cleanup_authorization', $output)
                || array_key_exists('worktree_cleanup', $output)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup authorization could not be retained.',
                );
            }

            $phase->output = [
                ...$output,
                'worktree_cleanup_authorization' => $authorization->toArray(),
            ];
            $phase->save();
        });
    }

    /** @param array<string, mixed> $intent */
    private function recordWorktreeCleanup(
        int $deliveryId,
        int $phaseId,
        array $intent,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
        CleanedOrbitAbandonedWorktree $cleaned,
    ): void {
        DB::transaction(function () use ($deliveryId, $phaseId, $intent, $authorization, $cleaned): void {
            [, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);
            $output = $phase->output;

            $this->assertCleanupMatchesAuthorization($authorization, $cleaned);

            if ($phase->current_block !== 'worktree_cleanup'
                || ! is_array($output)
                || ($output['worktree_cleanup_intent'] ?? null) !== $intent
                || ($output['worktree_cleanup_authorization'] ?? null) !== $authorization->toArray()
                || array_key_exists('worktree_cleanup', $output)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The abandoned worktree cleanup evidence does not match its authorization.',
                );
            }

            $phase->output = [...$output, 'worktree_cleanup' => $cleaned->toArray()];
            $phase->save();
        });
    }

    private function assertCleanupMatchesAuthorization(
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
        CleanedOrbitAbandonedWorktree $cleaned,
    ): void {
        if ($cleaned->repository !== $authorization->repository
            || $cleaned->worktree !== $authorization->worktree
            || $cleaned->issueKey !== $authorization->issueKey
            || $cleaned->branch !== $authorization->branch
            || $cleaned->candidateSha !== $authorization->candidateSha
            || $cleaned->cleanupAttemptId !== $authorization->cleanupAttemptId
            || ($authorization->disposition === 'already_absent'
                && $cleaned->disposition !== 'already_absent')) {
            throw new OrbitDeliveryCleanupFailed(
                'The abandoned worktree cleanup evidence does not match its authorization.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function assertAuthorizationMatchesIntent(
        array $intent,
        PreparedOrbitAbandonedWorktreeCleanup $authorization,
    ): void {
        if ($authorization->repository !== $intent['repository']
            || $authorization->worktree !== $intent['worktree']
            || $authorization->issueKey !== $intent['issue_key']
            || $authorization->branch !== $intent['branch']
            || $authorization->candidateSha !== $intent['candidate_sha']
            || $authorization->cleanupAttemptId !== $intent['attempt_id']) {
            throw new OrbitDeliveryCleanupFailed(
                'The abandoned worktree cleanup authorization does not match its intent.',
            );
        }
    }

    private function terminalize(int $deliveryId, int $phaseId): void
    {
        DB::transaction(function () use ($deliveryId, $phaseId): void {
            [$delivery, $phase] = $this->lockedCleanupIntent($deliveryId, $phaseId);
            $output = $phase->output;
            $source = $phase->input['source'] ?? null;
            $cleaned = is_array($output) ? ($output['worktree_cleanup'] ?? null) : null;
            $shutdown = is_array($output) ? ($output['workspace_shutdown'] ?? null) : null;
            $closed = is_array($shutdown) ? ($shutdown['closed'] ?? null) : null;

            if ($delivery->status === DeliveryStatus::Failed
                && $phase->status === PhaseRunStatus::Completed) {
                return;
            }

            if ($phase->current_block !== 'worktree_cleanup'
                || ! is_array($source) || ! is_array($output) || ! is_array($closed)) {
                throw new OrbitDeliveryCleanupFailed(
                    'The Orbit delivery cleanup cannot terminalize without complete evidence.',
                );
            }

            $intent = $this->validatedWorktreeIntent(
                $output['worktree_cleanup_intent'] ?? null,
                $delivery,
                is_string($phase->input['repository'] ?? null) ? $phase->input['repository'] : '',
            );
            $authorizationValue = $this->associativeArray(
                $output['worktree_cleanup_authorization'] ?? null,
            );
            $cleanedValue = $this->associativeArray($cleaned);

            if ($authorizationValue === null || $cleanedValue === null) {
                throw new OrbitDeliveryCleanupFailed(
                    'The Orbit delivery cleanup cannot terminalize without valid evidence.',
                );
            }

            try {
                $authorization = PreparedOrbitAbandonedWorktreeCleanup::fromArray($authorizationValue);
                $cleanedEvidence = CleanedOrbitAbandonedWorktree::fromArray($cleanedValue);
            } catch (\InvalidArgumentException $exception) {
                throw new OrbitDeliveryCleanupFailed(
                    'The Orbit delivery cleanup cannot terminalize with malformed evidence.',
                    previous: $exception,
                );
            }

            $this->assertAuthorizationMatchesIntent($intent, $authorization);
            $this->assertCleanupMatchesAuthorization($authorization, $cleanedEvidence);

            $phase->status = PhaseRunStatus::Completed;
            $phase->current_block = null;
            $phase->finished_at = now();
            $phase->save();

            $sourceFailure = $source['delivery_failure_details'] ?? null;
            $restarted = is_array($sourceFailure)
                && ($sourceFailure['code'] ?? null) === 'planning_resolution_cleanup_ready';
            $delivery->status = DeliveryStatus::Failed;
            $delivery->failed_at = now();
            $delivery->failure_details = [
                'code' => $restarted ? 'planning_resolution_restarted' : 'delivery_aborted',
                'cleanup_phase_run_id' => $phase->id,
                'original' => $source,
                'cleanup' => [
                    'workspace' => ($closed['disposition'] ?? null) === 'already_absent'
                        ? 'already_absent'
                        : 'closed',
                    'worktree' => $cleanedEvidence->disposition,
                ],
            ];
            $delivery->save();
        });
    }

    /** @return array{Delivery, PhaseRun} */
    private function lockedCleanupIntent(int $deliveryId, int $phaseId): array
    {
        $delivery = Delivery::query()
            ->with('projectOrchestration')
            ->whereKey($deliveryId)
            ->lockForUpdate()
            ->firstOrFail();
        $phase = PhaseRun::query()->whereKey($phaseId)->lockForUpdate()->firstOrFail();

        if ($phase->delivery_id !== $delivery->id
            || $phase->phase_name !== OrbitFeatureWorkflow::CLEANUP_PHASE
            || $phase->attempt !== 1
            || ! $this->matchesCleanupLedger($delivery, $phase)) {
            throw new OrbitDeliveryCleanupFailed('The Orbit delivery cleanup ledger changed.');
        }

        return [$delivery, $phase];
    }

    /** @return array<string, mixed> */
    private function validatedWorktreeIntent(
        mixed $value,
        Delivery $delivery,
        string $repository,
    ): array {
        $intent = $this->associativeArray($value);

        if ($intent === null
            || array_keys($intent) !== [
                'schema', 'attempt_id', 'repository', 'issue_key', 'worktree', 'branch',
                'candidate_sha', 'started_at',
            ]
            || ($intent['schema'] ?? null) !== 1
            || ! is_string($intent['attempt_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/', $intent['attempt_id']) !== 1
            || ($intent['repository'] ?? null) !== $repository
            || ($intent['issue_key'] ?? null) !== $delivery->external_issue_key
            || ($intent['worktree'] ?? null) !== $delivery->worktree_path
            || ($intent['branch'] ?? null) !== $delivery->branch
            || ($intent['candidate_sha'] ?? null) !== $delivery->candidate_sha
            || ! is_string($intent['started_at'] ?? null)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/',
                $intent['started_at'],
            ) !== 1) {
            throw new OrbitDeliveryCleanupFailed(
                'The abandoned worktree cleanup intent is malformed.',
            );
        }

        return $intent;
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new OrbitDeliveryCleanupFailed('The abandoned worktree cleanup intent is malformed.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredSha(array $values, string $key): string
    {
        $value = $this->requiredString($values, $key);

        if (preg_match('/^[a-f0-9]{40}$/', $value) !== 1) {
            throw new OrbitDeliveryCleanupFailed('The abandoned worktree cleanup intent is malformed.');
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredAttemptId(array $values): string
    {
        $value = $this->requiredString($values, 'attempt_id');

        if (preg_match('/^[a-f0-9]{32}$/', $value) !== 1) {
            throw new OrbitDeliveryCleanupFailed('The abandoned worktree cleanup intent is malformed.');
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function associativeArray(mixed $value): ?array
    {
        if (! is_array($value) || array_is_list($value)) {
            return null;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $result[$key] = $item;
        }

        return $result;
    }
}
