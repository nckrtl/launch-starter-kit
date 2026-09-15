<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Contracts\OrbitActiveIssueProvider;
use App\Delivery\Contracts\OrbitCloseoutIssueProvider;
use App\Delivery\Contracts\OrbitEligibleIssueProvider;
use App\Delivery\Contracts\OrbitIssueProvider;
use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Contracts\OrbitIssueResolver;
use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

final readonly class SshOrbitIssueProvider implements OrbitActiveIssueProvider, OrbitCloseoutIssueProvider, OrbitEligibleIssueProvider, OrbitIssueProvider, OrbitIssueReader, OrbitIssueResolver
{
    private const string QUERY = <<<'GRAPHQL'
query LoopIssue($id: String!) {
  viewer { id }
  issue(id: $id) {
    id identifier title url description updatedAt
    state { id name type } assignee { id } delegate { id }
    team { id states { nodes { id name } } }
    labels(first: 100) { nodes { name } pageInfo { hasNextPage } }
    attachments(first: 100) { nodes { title url } pageInfo { hasNextPage } }
    children(first: 100) { nodes { id } pageInfo { hasNextPage } }
    inverseRelations(first: 100) {
      nodes { type issue { identifier state { type } } } pageInfo { hasNextPage }
    }
  }
}
GRAPHQL;

    private const string QUEUE_QUERY = <<<'GRAPHQL'
query OrbitEligibleIssues($teamId: String!) {
  viewer { id }
  team(id: $teamId) {
    id
    states { nodes { id name } }
    issues(first: 100, filter: {state: {name: {eq: "Todo"}}}) {
      pageInfo { hasNextPage }
      nodes {
        id identifier title url description updatedAt sortOrder
        state { id name type } assignee { id } delegate { id }
        team { id }
        labels(first: 100) { nodes { name } pageInfo { hasNextPage } }
        attachments(first: 100) { nodes { title url } pageInfo { hasNextPage } }
        children(first: 10) { nodes { id } pageInfo { hasNextPage } }
        inverseRelations(first: 10) {
          nodes { type issue { identifier state { type } } } pageInfo { hasNextPage }
        }
      }
    }
  }
}
GRAPHQL;

    public function __construct(private OrbitIssueSnapshotFactory $snapshots) {}

    public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        [$response, $viewerId] = $this->request($issueId, $issueKey);

        return $this->snapshots->read($response, $issueId, $issueKey, $viewerId);
    }

    public function fetch(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        [$response, $viewerId] = $this->request($issueId, $issueKey);

        return $this->snapshots->make($response, $issueId, $issueKey, $viewerId);
    }

    public function resolve(string $issueKey): OrbitIssueSnapshot
    {
        [$response, $viewerId] = $this->request($issueKey, $issueKey);

        return $this->snapshots->makeResolved($response, $issueKey, $viewerId);
    }

    public function fetchActive(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        [$response, $viewerId] = $this->request($issueId, $issueKey);
        $assigneeId = config('commander.hermes.nick_linear_user_id');

        if (! is_string($assigneeId) || ! $this->isUuid($assigneeId)) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit active issue provider is not configured.');
        }

        return $this->snapshots->makeActive($response, $issueId, $issueKey, $viewerId, $assigneeId);
    }

    public function fetchForCloseout(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        [$response, $viewerId] = $this->request($issueId, $issueKey);
        $assigneeId = config('commander.hermes.nick_linear_user_id');

        if (! is_string($assigneeId) || ! $this->isUuid($assigneeId)) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit closeout issue provider is not configured.');
        }

        return $this->snapshots->makeForCloseout($response, $issueId, $issueKey, $viewerId, $assigneeId);
    }

    public function next(): ?OrbitEligibleIssue
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');
        $teamId = config('commander.hermes.orbit_linear_team_id');

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || ! is_string($teamId) || ! $this->isUuid($teamId)) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit eligible issue provider is not configured.');
        }

        $response = $this->runRequest($target, $profile, self::QUEUE_QUERY, ['teamId' => $teamId]);

        return $this->snapshots->nextEligible($response, $viewerId, $teamId);
    }

    /** @return array{array<mixed, mixed>, string} */
    private function request(string $identifier, string $issueKey): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        $viewerId = config('commander.hermes.tom_linear_viewer_id');

        if (! is_string($target) || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $target) !== 1
            || ! is_string($profile) || preg_match('/^\/[A-Za-z0-9._\/-]+$/', $profile) !== 1
            || ! is_string($viewerId) || ! $this->isUuid($viewerId)
            || preg_match('/^ORB-[0-9]+$/', $issueKey) !== 1
            || (! $this->isUuid($identifier) && $identifier !== $issueKey)) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider is not configured.');
        }

        $response = $this->runRequest($target, $profile, self::QUERY, ['id' => $identifier]);

        return [$response, $viewerId];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<mixed, mixed>
     */
    private function runRequest(string $target, string $profile, string $document, array $variables): array
    {
        try {
            $input = json_encode([
                'service' => 'linear',
                'document' => $document,
                'variables' => $variables,
            ], JSON_THROW_ON_ERROR);
            $result = Process::input($input)
                ->timeout(30)
                ->run([
                    'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $target,
                    escapeshellarg($profile.'/scripts/orbit_delivery_loop.py').' --rpc',
                ]);
        } catch (JsonException|RuntimeException $exception) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider could not run.', 0, $exception);
        }

        if ($result->failed()) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider failed.');
        }

        try {
            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider returned invalid JSON.', 0, $exception);
        }

        if (! is_array($response)) {
            throw new OrbitIssueProviderFailed('The Hermes Orbit issue provider returned invalid JSON.');
        }

        return $response;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
