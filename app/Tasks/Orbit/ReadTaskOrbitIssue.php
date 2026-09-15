<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Delivery\Contracts\OrbitIssueReader;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Tasks\Landing\TaskLandingData;
use LogicException;

final readonly class ReadTaskOrbitIssue
{
    public function __construct(private OrbitIssueReader $issues) {}

    public function read(string $issueId, string $issueKey): OrbitIssueSnapshot
    {
        $issue = $this->issues->read($issueId, $issueKey);
        $payload = $issue->payload;
        $state = TaskLandingData::object($payload['state'] ?? null);
        $team = TaskLandingData::object($payload['team'] ?? null);
        $assignee = $payload['assignee'] ?? null;
        $nick = config('commander.hermes.nick_linear_user_id');
        if ($issue->issueId !== $issueId || $issue->issueKey !== $issueKey
            || ($payload['id'] ?? null) !== $issueId || ($payload['identifier'] ?? null) !== $issueKey
            || ($team['id'] ?? null) !== config('commander.hermes.orbit_linear_team_id')
            || ! is_string($team['id'] ?? null) || $team['id'] === ''
            || ! array_key_exists('delegate', $payload) || $payload['delegate'] !== null
            || ! array_key_exists('assignee', $payload)
            || ($assignee !== null && (! is_string($nick) || $nick === ''
                || (TaskLandingData::object($assignee)['id'] ?? null) !== $nick))
            || ! in_array($state['name'] ?? null, ['In Progress', 'In Review'], true)
            || ($state['type'] ?? null) !== 'started') {
            throw new LogicException('The Tasks-owned Orbit Linear issue must remain active, undelegated, in the exact team, and without a foreign assignee.');
        }
        $labels = $this->collection($payload, 'labels');
        $this->collection($payload, 'attachments');
        $children = $this->collection($payload, 'children');
        $relations = $this->collection($payload, 'inverseRelations');
        if ($children !== [] || str_contains(TaskLandingData::text($payload, 'description'), '## Readiness')) {
            throw new LogicException('The Tasks Orbit issue must be a ready leaf without a readiness hold.');
        }
        foreach ($labels as $label) {
            $name = strtolower(TaskLandingData::text($label, 'name', 255));
            if (($name !== 'controller:tasks' && str_starts_with($name, 'controller:')) || $name === 'maintenance:monorepo') {
                throw new LogicException('A foreign controller or maintenance owner labels this Tasks Orbit issue.');
            }
        }
        foreach ($relations as $relation) {
            $type = TaskLandingData::text($relation, 'type');
            $related = TaskLandingData::object($relation['issue'] ?? null);
            $relatedState = TaskLandingData::object($related['state'] ?? null);
            if ($type === 'blocks' && ! in_array($relatedState['type'] ?? null, ['completed', 'canceled'], true)) {
                throw new LogicException('The Tasks Orbit issue has an unresolved blocker.');
            }
        }

        return $issue;
    }

    /** @param array<string,mixed> $payload
     * @return list<array<string,mixed>>
     */
    private function collection(array $payload, string $key): array
    {
        $collection = TaskLandingData::object($payload[$key] ?? null);
        $page = TaskLandingData::object($collection['pageInfo'] ?? null);
        $nodes = $collection['nodes'] ?? null;
        if (($page['hasNextPage'] ?? null) !== false || ! is_array($nodes) || ! array_is_list($nodes) || count($nodes) > 100) {
            throw new LogicException('The Tasks Orbit issue has an incomplete '.$key.' collection.');
        }

        return array_map(TaskLandingData::object(...), $nodes);
    }
}
