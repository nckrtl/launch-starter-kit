<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueTransitioner;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use App\Delivery\Exceptions\OrbitIssueTransitionFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitIssueTransitioner implements OrbitIssueTransitioner
{
    private const string MUTATION = <<<'GRAPHQL'
mutation LoopState($id: String!, $input: IssueUpdateInput!) {
  issueUpdate(id: $id, input: $input) { success }
}
GRAPHQL;

    public function __construct(private OrbitIssueProvider $issues) {}

    public function transitionToInProgress(
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
    ): OrbitIssueSnapshot {
        [$target, $profile] = $this->configuration($current, $expectedContractHash);
        $targetStateId = $this->targetStateId($current);
        $state = $current->payload['state'] ?? null;
        $attemptedMutation = is_array($state) && ($state['id'] ?? null) !== $targetStateId;
        $mutationFailure = null;

        if ($attemptedMutation) {
            try {
                $this->mutate($target, $profile, $current->issueId, $targetStateId);
            } catch (OrbitIssueTransitionFailed $exception) {
                $mutationFailure = $exception;
            }
        }

        try {
            $readBack = $this->issues->fetch($current->issueId, $current->issueKey);
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Orbit issue transition could not be verified by Linear read-back.',
                ambiguous: $attemptedMutation,
                mutationFailure: $mutationFailure,
                previous: $exception,
            );
        }

        if ($this->isVerifiedReadBack($readBack, $current, $expectedContractHash, $targetStateId)) {
            return $readBack;
        }

        throw new OrbitIssueTransitionFailed(
            'The Orbit issue transition Linear read-back did not confirm the exact In Progress state and contract.',
            ambiguous: $attemptedMutation,
            mutationFailure: $mutationFailure,
        );
    }

    /** @return array{string, string} */
    private function configuration(OrbitIssueSnapshot $current, string $expectedContractHash): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $payload = $current->payload;
        $delegate = $payload['delegate'] ?? null;
        $assigneePresent = array_key_exists('assignee', $payload);

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! $this->isUuid($current->issueId) || preg_match('/^ORB-[0-9]+$/', $current->issueKey) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedContractHash) !== 1
            || ! hash_equals($expectedContractHash, $current->contractHash)
            || ($payload['id'] ?? null) !== $current->issueId
            || ($payload['identifier'] ?? null) !== $current->issueKey
            || ! $assigneePresent || $payload['assignee'] !== null
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId) {
            throw new OrbitIssueTransitionFailed('The Orbit issue transition input or Hermes configuration is invalid.');
        }

        return [$target, $profile];
    }

    private function targetStateId(OrbitIssueSnapshot $current): string
    {
        $team = $current->payload['team'] ?? null;
        $states = is_array($team) ? ($team['states'] ?? null) : null;
        $nodes = is_array($states) ? ($states['nodes'] ?? null) : null;
        $currentState = $current->payload['state'] ?? null;

        if (! is_array($nodes) || ! is_array($currentState)
            || ! $this->isUuid($currentState['id'] ?? null)
            || ! in_array($currentState['name'] ?? null, ['Todo', 'In Progress'], true)) {
            throw new OrbitIssueTransitionFailed('The Orbit issue has invalid workflow state metadata.');
        }

        $matches = array_values(array_filter(
            $nodes,
            static fn (mixed $state): bool => is_array($state) && ($state['name'] ?? null) === 'In Progress',
        ));
        $targetStateId = count($matches) === 1 ? ($matches[0]['id'] ?? null) : null;

        if (! is_string($targetStateId) || ! $this->isUuid($targetStateId)) {
            throw new OrbitIssueTransitionFailed('Linear did not provide exactly one valid In Progress state for the Orbit team.');
        }

        return $targetStateId;
    }

    private function mutate(string $target, string $profile, string $issueId, string $stateId): void
    {
        try {
            $input = json_encode([
                'service' => 'linear',
                'document' => self::MUTATION,
                'variables' => ['id' => $issueId, 'input' => ['stateId' => $stateId]],
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);

            if ($result->failed()) {
                throw new RuntimeException('The Hermes Linear mutation process failed.');
            }

            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitIssueTransitionFailed(
                'The Hermes Orbit issue transition returned an uncertain result.',
                ambiguous: true,
                previous: $exception,
            );
        }

        if (! is_array($response)) {
            throw new OrbitIssueTransitionFailed(
                'The Hermes Orbit issue transition returned an uncertain result.',
                ambiguous: true,
            );
        }

        $data = $response['data'] ?? null;
        $updated = is_array($data) ? ($data['issueUpdate'] ?? null) : null;

        if (! empty($response['errors']) || ! is_array($updated) || ($updated['success'] ?? null) !== true) {
            throw new OrbitIssueTransitionFailed(
                'Linear did not confirm the Orbit issue transition mutation.',
                ambiguous: true,
            );
        }
    }

    private function isVerifiedReadBack(
        OrbitIssueSnapshot $readBack,
        OrbitIssueSnapshot $current,
        string $expectedContractHash,
        string $targetStateId,
    ): bool {
        $state = $readBack->payload['state'] ?? null;

        return $readBack->issueId === $current->issueId
            && $readBack->issueKey === $current->issueKey
            && hash_equals($expectedContractHash, $readBack->contractHash)
            && is_array($state)
            && ($state['id'] ?? null) === $targetStateId
            && ($state['name'] ?? null) === 'In Progress'
            && ($state['type'] ?? null) === 'started'
            && ($readBack->payload['delegate'] ?? null) === ($current->payload['delegate'] ?? null)
            && array_key_exists('assignee', $readBack->payload)
            && $readBack->payload['assignee'] === $current->payload['assignee'];
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
