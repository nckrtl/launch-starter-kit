<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Contracts\OrbitIssueOwnershipClaimer;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueOwnershipFailed;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitIssueOwnershipClaimer implements OrbitIssueOwnershipClaimer
{
    private const string OWNERSHIP_LABEL = 'controller:commander';

    private const string MAINTENANCE_LABEL = 'maintenance:monorepo';

    private const string MUTATION = <<<'GRAPHQL'
mutation LoopOwnership($id: String!, $labelId: String!) {
  issueAddLabel(id: $id, labelId: $labelId) { success }
}
GRAPHQL;

    public function __construct(
        private OrbitIssueProvider $issues,
        private OrbitIssueSnapshotFactory $snapshots,
    ) {}

    public function claim(OrbitEligibleIssue $issue): OrbitIssueSnapshot
    {
        [$target, $profile, $controllerLabelId, $alreadyOwned] = $this->configuration($issue);
        $expectedPayload = $alreadyOwned
            ? $issue->snapshot->payload
            : $this->payloadWithOwnership($issue->snapshot->payload);

        $expectedContractHash = $this->snapshots->contractHash($expectedPayload);
        $mutationFailure = null;

        if (! $alreadyOwned) {
            try {
                $this->mutate(
                    $target,
                    $profile,
                    $issue->snapshot->issueId,
                    $controllerLabelId,
                );
            } catch (OrbitIssueOwnershipFailed $exception) {
                $mutationFailure = $exception;
            }
        }

        try {
            $readBack = $this->issues->fetch(
                $issue->snapshot->issueId,
                $issue->snapshot->issueKey,
            );
        } catch (OrbitIssueProviderFailed $exception) {
            throw new OrbitIssueOwnershipFailed(
                'The Orbit ownership handoff could not be verified by Linear read-back.',
                ambiguous: ! $alreadyOwned,
                mutationFailure: $mutationFailure,
                previous: $exception,
            );
        }

        if ($this->isVerifiedReadBack($readBack, $issue, $expectedContractHash)) {
            return $readBack;
        }

        throw new OrbitIssueOwnershipFailed(
            'The Linear read-back did not confirm the exact Commander ownership handoff.',
            ambiguous: ! $alreadyOwned,
            mutationFailure: $mutationFailure,
        );
    }

    /** @return array{string, string, string, bool} */
    private function configuration(OrbitEligibleIssue $issue): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $controllerLabelId = config('commander.delivery.orbit_controller_label_id');
        $snapshot = $issue->snapshot;
        $payload = $snapshot->payload;
        $state = $payload['state'] ?? null;
        $delegate = $payload['delegate'] ?? null;
        $labels = $issue->labels;
        $alreadyOwned = in_array(self::OWNERSHIP_LABEL, $issue->labels, true);

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! is_string($controllerLabelId) || ! $this->isUuid($controllerLabelId)
            || ! $this->isUuid($snapshot->issueId)
            || preg_match('/^ORB-[0-9]+$/', $snapshot->issueKey) !== 1
            || ($payload['id'] ?? null) !== $snapshot->issueId
            || ($payload['identifier'] ?? null) !== $snapshot->issueKey
            || ! is_array($state) || ($state['name'] ?? null) !== 'Todo' || ($state['type'] ?? null) !== 'unstarted'
            || ! is_array($delegate) || ($delegate['id'] ?? null) !== $viewerId
            || ! array_key_exists('assignee', $payload) || $payload['assignee'] !== null
            || in_array(self::MAINTENANCE_LABEL, $labels, true)
            || ! hash_equals($snapshot->contractHash, $this->snapshots->contractHash($payload))) {
            throw new OrbitIssueOwnershipFailed('The Orbit ownership handoff input or Hermes configuration is invalid.');
        }

        return [$target, $profile, $controllerLabelId, $alreadyOwned];
    }

    private function mutate(string $target, string $profile, string $issueId, string $labelId): void
    {
        try {
            $input = json_encode([
                'service' => 'linear',
                'document' => self::MUTATION,
                'variables' => ['id' => $issueId, 'labelId' => $labelId],
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);

            if ($result->failed()) {
                throw new RuntimeException('The Hermes Linear ownership mutation process failed.');
            }

            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitIssueOwnershipFailed(
                'The Hermes Orbit ownership handoff returned an uncertain result.',
                ambiguous: true,
                previous: $exception,
            );
        }

        $data = is_array($response) ? ($response['data'] ?? null) : null;
        $updated = is_array($data) ? ($data['issueAddLabel'] ?? null) : null;

        if (! is_array($response) || ! empty($response['errors'])
            || ! is_array($updated) || ($updated['success'] ?? null) !== true) {
            throw new OrbitIssueOwnershipFailed(
                'Linear did not confirm the Orbit ownership handoff mutation.',
                ambiguous: true,
            );
        }
    }

    private function isVerifiedReadBack(
        OrbitIssueSnapshot $readBack,
        OrbitEligibleIssue $selected,
        string $expectedContractHash,
    ): bool {
        $payload = $readBack->payload;
        $state = $payload['state'] ?? null;
        $selectedState = $selected->snapshot->payload['state'] ?? null;
        $labels = is_array($payload['labels'] ?? null) ? ($payload['labels']['nodes'] ?? null) : null;
        $names = is_array($labels) && array_is_list($labels)
            ? array_column($labels, 'name')
            : [];

        return $readBack->issueId === $selected->snapshot->issueId
            && $readBack->issueKey === $selected->snapshot->issueKey
            && hash_equals($expectedContractHash, $readBack->contractHash)
            && is_array($state)
            && is_array($selectedState)
            && ($state['id'] ?? null) === ($selectedState['id'] ?? null)
            && ($state['name'] ?? null) === 'Todo'
            && ($state['type'] ?? null) === 'unstarted'
            && ($payload['delegate'] ?? null) === ($selected->snapshot->payload['delegate'] ?? null)
            && array_key_exists('assignee', $payload)
            && $payload['assignee'] === null
            && in_array(self::OWNERSHIP_LABEL, $names, true)
            && ! in_array(self::MAINTENANCE_LABEL, $names, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payloadWithOwnership(array $payload): array
    {
        $labels = $payload['labels'] ?? null;
        $nodes = is_array($labels) ? ($labels['nodes'] ?? null) : null;

        if (! is_array($labels) || ! is_array($nodes) || ! array_is_list($nodes)) {
            throw new OrbitIssueOwnershipFailed('The Orbit ownership handoff label data is invalid.');
        }

        $labels['nodes'] = [...$nodes, ['name' => self::OWNERSHIP_LABEL]];
        $payload['labels'] = $labels;

        return $payload;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
