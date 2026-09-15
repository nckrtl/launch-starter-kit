<?php

declare(strict_types=1);

namespace App\Tasks\Completion;

use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\IssueProviders\OrbitIssueSnapshotFactory;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class NativeTaskCompletionIssue implements TaskCompletionIssue
{
    private const string QUERY = <<<'GRAPHQL'
query TasksCompletion($id: String!) {
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

    private const string MUTATION = <<<'GRAPHQL'
mutation TasksDone($id: String!, $input: IssueUpdateInput!) {
  issueUpdate(id: $id, input: $input) { success }
}
GRAPHQL;

    public function __construct(private OrbitIssueSnapshotFactory $snapshots) {}

    public function identityHash(): string
    {
        return TaskLandingData::hash($this->configuration());
    }

    public function read(TaskLanding $landing): OrbitIssueSnapshot
    {
        $configuration = $this->configuration();
        $response = $this->rpc(['service' => 'linear', 'document' => self::QUERY, 'variables' => ['id' => $landing->issue_id]]);
        $issue = TaskLandingData::object(TaskLandingData::object($response['data'] ?? null)['issue'] ?? null);
        foreach (['labels', 'attachments', 'children', 'inverseRelations'] as $key) {
            $collection = TaskLandingData::object($issue[$key] ?? null);
            $page = TaskLandingData::object($collection['pageInfo'] ?? null);
            if (! is_array($collection['nodes'] ?? null) || ! array_is_list($collection['nodes'])
                || count($collection['nodes']) > 100 || ($page['hasNextPage'] ?? null) !== false) {
                throw new LogicException('The completion issue has an incomplete collection.');
            }
        }

        return $this->snapshots->read($response, $landing->issue_id,
            TaskLandingData::text(TaskLandingData::object($landing->inputs['issue'] ?? null), 'identifier'), $configuration['viewer']);
    }

    public function complete(TaskLanding $landing, string $stateId): void
    {
        $this->uuid($landing->issue_id);
        $this->uuid($stateId);
        $response = $this->rpc(['service' => 'linear', 'document' => self::MUTATION,
            'variables' => ['id' => $landing->issue_id, 'input' => ['stateId' => $stateId]]]);
        if (($response['errors'] ?? []) !== []
            || (TaskLandingData::object(TaskLandingData::object($response['data'] ?? null)['issueUpdate'] ?? null)['success'] ?? null) !== true) {
            throw new LogicException('The exact Linear completion response is unresolved; inspect its state before continuing.');
        }
    }

    public function reservation(TaskLanding $landing, array $pr): array
    {
        $status = $this->rpc(['service' => 'reservation', 'action' => 'status']);
        if (($status['version'] ?? null) !== 1
            || array_diff(['status', 'issue_id', 'pr_url', 'reserved_at'], array_keys($status)) !== []) {
            throw new LogicException('The current Orbit reservation response is incomplete.');
        }
        if ($status['status'] === 'idle' && $status['issue_id'] === null && $status['pr_url'] === null && $status['reserved_at'] === null) {
            return ['status' => 'idle', 'issue_id' => null, 'url' => null, 'reserved_at' => null];
        }
        $issue = TaskLandingData::text($status, 'issue_id', 36);
        $this->uuid($issue);
        $url = TaskLandingData::text($status, 'pr_url', 500);
        $reserved = TaskLandingData::text($status, 'reserved_at', 100);
        if ($status['status'] !== 'active' || preg_match('#\\Ahttps://github\\.com/nckrtl/orbit/pull/[1-9][0-9]*\\z#', $url) !== 1) {
            throw new LogicException('The current Orbit reservation response is malformed.');
        }
        $ownIssue = $issue === $landing->issue_id;
        $ownPr = $url === ($pr['url'] ?? null);
        if ($ownIssue !== $ownPr) {
            throw new LogicException('The reservation issue and pull request ownership disagree.');
        }

        return ['status' => $ownIssue ? 'owned' : 'foreign', 'issue_id' => $issue, 'url' => $url, 'reserved_at' => $reserved];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function rpc(array $payload): array
    {
        $configuration = $this->configuration();
        $result = Process::env(TaskProcessEnvironment::isolated())->input(TaskLandingData::json($payload))->timeout(30)->run([
            'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', $configuration['target'],
            escapeshellarg($configuration['profile'].'/scripts/orbit_delivery_loop.py').' --rpc',
        ]);
        if ($result->failed()) {
            throw new LogicException('The authenticated completion RPC failed or returned an uncertain response.');
        }

        return TaskLandingData::object(json_decode($result->output(), true, 64, JSON_THROW_ON_ERROR));
    }

    /** @return array{target:string,profile:string,viewer:string,team:string,nick:string} */
    private function configuration(): array
    {
        $target = config('commander.hermes.ssh_target');
        $profile = config('commander.hermes.profiles.tom');
        if (! is_string($target) || preg_match('/\\A[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+\\z/', $target) !== 1
            || ! is_string($profile) || preg_match('#\\A/[A-Za-z0-9._/-]+\\z#', $profile) !== 1) {
            throw new LogicException('The existing authenticated Orbit completion RPC is not configured.');
        }
        $identities = [];
        foreach (['viewer' => 'tom_linear_viewer_id', 'team' => 'orbit_linear_team_id', 'nick' => 'nick_linear_user_id'] as $key => $option) {
            $value = config('commander.hermes.'.$option);
            if (! is_string($value)) {
                throw new LogicException('The completion RPC identity is missing.');
            }
            $this->uuid($value);
            $identities[$key] = $value;
        }

        return ['target' => $target, 'profile' => $profile, ...$identities];
    }

    private function uuid(string $value): void
    {
        if (preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\z/', $value) !== 1) {
            throw new LogicException('The exact completion UUID is invalid.');
        }
    }
}
