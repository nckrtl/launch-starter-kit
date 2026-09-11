<?php

declare(strict_types=1);

namespace App\Delivery\Actions;

use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitPlanningHandoff;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitIssueContractChanged;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
use App\Models\Delivery;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class PrepareOrbitPlanningHandoff
{
    public function __construct(
        private ProjectConfigRegistry $configs,
        private OrbitRepository $repository,
        private OrbitIssueProvider $issues,
        private ResolveOrbitDeliveryPreparation $preparations,
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

        $preparation = $this->preparations->handle($delivery);
        $worktree = $preparation->worktree;
        $snapshot = $preparation->snapshot;
        $candidate = $preparation->candidate;
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
}
