<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

use App\Delivery\Data\OrbitEligibleIssue;
use App\Delivery\Data\OrbitIssueSnapshot;
use App\Delivery\Exceptions\OrbitIssueProviderFailed;
use Illuminate\Support\Str;
use JsonException;

/**
 * @phpstan-type OrbitIssuePayload array{
 *     id: string,
 *     identifier: string,
 *     title: string,
 *     url: string,
 *     description: string|null,
 *     updatedAt: string,
 *     state: array{id: string, name: string, type: string},
 *     assignee: array{id: string}|null,
 *     delegate: array{id: string}|null,
 *     team: array{id: string, states: array{nodes: list<array{id: string, name: string}>}},
 *     labels: array{nodes: list<array{name: string}>, pageInfo: array{hasNextPage: bool}},
 *     attachments: array{nodes: list<array{title: string, url: string}>, pageInfo: array{hasNextPage: bool}},
 *     children: array{nodes: list<array{id: string}>, pageInfo: array{hasNextPage: bool}},
 *     inverseRelations: array{
 *         nodes: list<array{type: string, issue: array{identifier: string, state: array{type: string}}}>,
 *         pageInfo: array{hasNextPage: bool}
 *     }
 * }
 */
final readonly class OrbitIssueSnapshotFactory
{
    private const int MAX_COLLECTION_SIZE = 100;

    public function make(
        mixed $response,
        string $expectedIssueId,
        string $expectedIssueKey,
        string $expectedViewerId,
    ): OrbitIssueSnapshot {
        return $this->makeSnapshot(
            $response,
            $expectedIssueId,
            $expectedIssueKey,
            $expectedViewerId,
            null,
        );
    }

    public function makeResolved(
        mixed $response,
        string $expectedIssueKey,
        string $expectedViewerId,
    ): OrbitIssueSnapshot {
        return $this->makeSnapshot(
            $response,
            null,
            $expectedIssueKey,
            $expectedViewerId,
            null,
        );
    }

    public function makeActive(
        mixed $response,
        string $expectedIssueId,
        string $expectedIssueKey,
        string $expectedViewerId,
        string $expectedAssigneeId,
    ): OrbitIssueSnapshot {
        return $this->makeSnapshot(
            $response,
            $expectedIssueId,
            $expectedIssueKey,
            $expectedViewerId,
            $expectedAssigneeId,
            false,
        );
    }

    public function makeForCloseout(
        mixed $response,
        string $expectedIssueId,
        string $expectedIssueKey,
        string $expectedViewerId,
        string $expectedAssigneeId,
    ): OrbitIssueSnapshot {
        return $this->makeSnapshot(
            $response,
            $expectedIssueId,
            $expectedIssueKey,
            $expectedViewerId,
            $expectedAssigneeId,
            true,
        );
    }

    public function matchesExpectedContract(
        OrbitIssueSnapshot $snapshot,
        string $expectedContractHash,
        ?string $pullRequestUrl = null,
    ): bool {
        if (hash_equals($expectedContractHash, $snapshot->contractHash)) {
            return true;
        }

        if ($pullRequestUrl === null) {
            return false;
        }

        $payload = $snapshot->payload;
        $attachments = $payload['attachments'] ?? null;
        $nodes = is_array($attachments) ? ($attachments['nodes'] ?? null) : null;
        $title = $payload['title'] ?? null;

        if (! is_array($nodes) || ! is_string($title)) {
            return false;
        }

        $expectedTitle = $snapshot->issueKey.': '.$title;
        $matches = array_keys(array_filter(
            $nodes,
            static fn (mixed $attachment): bool => is_array($attachment)
                && ($attachment['title'] ?? null) === $expectedTitle
                && ($attachment['url'] ?? null) === $pullRequestUrl,
        ));

        if (count($matches) !== 1) {
            return false;
        }

        unset($nodes[$matches[0]]);
        $attachments['nodes'] = array_values($nodes);
        $payload['attachments'] = $attachments;

        return hash_equals($expectedContractHash, $this->contractHash($payload));
    }

    public function nextEligible(
        mixed $response,
        string $expectedViewerId,
        string $expectedTeamId,
    ): ?OrbitEligibleIssue {
        $root = $this->map($response);
        $data = $this->map($root['data'] ?? null);
        $viewer = $this->map($data['viewer'] ?? null);
        $team = $this->map($data['team'] ?? null);
        $issues = $this->map($team['issues'] ?? null);
        $pageInfo = $this->map($issues['pageInfo'] ?? null);
        $viewerId = $this->requiredUuid($viewer['id'] ?? null);
        $teamId = $this->requiredUuid($team['id'] ?? null);

        if (($root['errors'] ?? []) !== []
            || ! $this->isUuid($expectedViewerId)
            || ! $this->isUuid($expectedTeamId)
            || $viewerId !== $expectedViewerId
            || $teamId !== $expectedTeamId
            || $this->requiredBool($pageInfo['hasNextPage'] ?? null)) {
            throw new OrbitIssueProviderFailed('The Linear eligible issue response is incomplete or does not match Orbit.');
        }

        /** @var list<array{sort_order: float|int|null, issue_key: string, issue: OrbitEligibleIssue}> $candidates */
        $candidates = [];
        $identities = [];

        foreach ($this->limitedList($issues['nodes'] ?? null) as $value) {
            $rawIssue = $this->map($value);
            $rawTeam = $this->map($rawIssue['team'] ?? null);
            $rawTeam['states'] = $this->map($team['states'] ?? ($rawTeam['states'] ?? null));
            $rawIssue['team'] = $rawTeam;
            $sortOrder = $this->nullableNumber($rawIssue['sortOrder'] ?? null);
            $issue = $this->normalizeIssue($rawIssue);
            $identity = $issue['id'].'|'.$issue['identifier'];

            if (isset($identities[$issue['id']])
                || isset($identities[$issue['identifier']])
                || $issue['team']['id'] !== $expectedTeamId
                || $issue['state']['name'] !== 'Todo'
                || $issue['state']['type'] !== 'unstarted') {
                throw new OrbitIssueProviderFailed('The Linear eligible issue response is incomplete or does not match Orbit.');
            }

            $identities[$issue['id']] = $identity;
            $identities[$issue['identifier']] = $identity;

            $labelNames = array_column($issue['labels']['nodes'], 'name');

            if (($issue['delegate']['id'] ?? null) !== $expectedViewerId
                || $issue['assignee'] !== null
                || in_array('maintenance:monorepo', $labelNames, true)
                || $this->hasContractHold($issue)) {
                continue;
            }

            $snapshot = new OrbitIssueSnapshot(
                issueId: $issue['id'],
                issueKey: $issue['identifier'],
                payload: $issue,
                contractHash: $this->contractHash($issue),
            );
            $candidates[] = [
                'sort_order' => $sortOrder,
                'issue_key' => $issue['identifier'],
                'issue' => new OrbitEligibleIssue(
                    snapshot: $snapshot,
                    title: $issue['title'],
                    url: $issue['url'],
                    labels: $labelNames,
                ),
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => [
            $left['sort_order'] === null,
            $left['sort_order'] ?? 0,
            $left['issue_key'],
        ] <=> [
            $right['sort_order'] === null,
            $right['sort_order'] ?? 0,
            $right['issue_key'],
        ]);

        return $candidates[0]['issue'] ?? null;
    }

    private function makeSnapshot(
        mixed $response,
        ?string $expectedIssueId,
        string $expectedIssueKey,
        string $expectedViewerId,
        ?string $expectedAssigneeId,
        bool $closeout = false,
    ): OrbitIssueSnapshot {
        $root = $this->map($response);
        $data = $this->map($root['data'] ?? null);
        $viewer = $this->map($data['viewer'] ?? null);
        $issue = $this->normalizeIssue($this->map($data['issue'] ?? null));
        $viewerId = $this->requiredUuid($viewer['id'] ?? null);

        if (($root['errors'] ?? []) !== []
            || ($expectedIssueId !== null && ! $this->isUuid($expectedIssueId))
            || preg_match('/^ORB-[0-9]+$/', $expectedIssueKey) !== 1
            || ! $this->isUuid($expectedViewerId)
            || ($expectedAssigneeId !== null && ! $this->isUuid($expectedAssigneeId))
            || $viewerId !== $expectedViewerId
            || ($expectedIssueId !== null && $issue['id'] !== $expectedIssueId)
            || $issue['identifier'] !== $expectedIssueKey) {
            throw new OrbitIssueProviderFailed('The Linear issue response does not match the requested Orbit issue.');
        }

        $active = $expectedAssigneeId !== null && ! $closeout;
        $validAssignee = $issue['assignee'] === null
            || ($expectedAssigneeId !== null && $issue['assignee']['id'] === $expectedAssigneeId);
        $validState = match (true) {
            $closeout => ($issue['state']['name'] === 'In Review'
                    && $issue['state']['type'] === 'started'
                    && $issue['assignee'] === null
                    && ($issue['delegate']['id'] ?? null) === $expectedViewerId)
                || ($issue['state']['name'] === 'Done'
                    && $issue['state']['type'] === 'completed'
                    && $validAssignee
                    && in_array($issue['delegate']['id'] ?? null, [null, $expectedViewerId], true)),
            $active => in_array($issue['state']['name'], ['In Progress', 'In Review'], true)
                && $issue['state']['type'] === 'started',
            default => in_array($issue['state']['name'], ['Todo', 'In Progress'], true),
        };
        $validDelegate = $closeout
            ? $validState
            : ($issue['delegate']['id'] ?? null) === $expectedViewerId;

        if (! $validAssignee
            || ! $validDelegate
            || ! $validState
            || $this->hasContractHold($issue)) {
            throw new OrbitIssueProviderFailed(
                match (true) {
                    $closeout => 'The Orbit closeout issue must be either solely delegated to Tom in In Review or in Done with no unexpected owner or contract hold.',
                    $active => 'The active Orbit issue must remain delegated to Tom in In Progress or In Review with no unexpected assignee or contract hold.',
                    default => 'The Orbit issue is not eligible: it must be solely delegated to Tom and be in Todo or In Progress without readiness, children, or unfinished blockers.',
                },
            );
        }

        return new OrbitIssueSnapshot(
            issueId: $issue['id'],
            issueKey: $issue['identifier'],
            payload: $issue,
            contractHash: $this->contractHash($issue),
        );
    }

    /**
     * @param  array<mixed, mixed>  $issue
     * @return OrbitIssuePayload
     */
    private function normalizeIssue(array $issue): array
    {
        $state = $this->map($issue['state'] ?? null);
        $delegate = $this->nullableMap($issue['delegate'] ?? null);
        $assignee = $this->nullableMap($issue['assignee'] ?? null);
        $team = $this->map($issue['team'] ?? null);
        $teamStates = $this->map($team['states'] ?? null);
        $labels = $this->map($issue['labels'] ?? null);
        $attachments = $this->map($issue['attachments'] ?? null);
        $labelsPage = $this->map($labels['pageInfo'] ?? null);
        $attachmentsPage = $this->map($attachments['pageInfo'] ?? null);
        $children = $this->map($issue['children'] ?? null);
        $relations = $this->map($issue['inverseRelations'] ?? null);
        $childrenPage = $this->map($children['pageInfo'] ?? null);
        $relationsPage = $this->map($relations['pageInfo'] ?? null);

        return [
            'id' => $this->requiredUuid($issue['id'] ?? null),
            'identifier' => $this->requiredIssueKey($issue['identifier'] ?? null),
            'title' => $this->requiredText($issue['title'] ?? null, 1_000),
            'url' => $this->requiredText($issue['url'] ?? null, 2_048),
            'description' => $this->nullableText($issue['description'] ?? null, 100_000),
            'updatedAt' => $this->requiredText($issue['updatedAt'] ?? null, 100),
            'state' => [
                'id' => $this->requiredUuid($state['id'] ?? null),
                'name' => $this->requiredText($state['name'] ?? null, 100),
                'type' => $this->requiredText($state['type'] ?? null, 100),
            ],
            'assignee' => $assignee === null ? null : ['id' => $this->requiredUuid($assignee['id'] ?? null)],
            'delegate' => $delegate === null ? null : ['id' => $this->requiredUuid($delegate['id'] ?? null)],
            'team' => [
                'id' => $this->requiredUuid($team['id'] ?? null),
                'states' => ['nodes' => $this->normalizeStates($this->limitedList($teamStates['nodes'] ?? null))],
            ],
            'labels' => [
                'nodes' => $this->normalizeLabels($this->limitedList($labels['nodes'] ?? null)),
                'pageInfo' => ['hasNextPage' => $this->requiredBool($labelsPage['hasNextPage'] ?? null)],
            ],
            'attachments' => [
                'nodes' => $this->normalizeAttachments($this->limitedList($attachments['nodes'] ?? null)),
                'pageInfo' => ['hasNextPage' => $this->requiredBool($attachmentsPage['hasNextPage'] ?? null)],
            ],
            'children' => [
                'nodes' => $this->normalizeChildren($this->limitedList($children['nodes'] ?? null)),
                'pageInfo' => ['hasNextPage' => $this->requiredBool($childrenPage['hasNextPage'] ?? null)],
            ],
            'inverseRelations' => [
                'nodes' => $this->normalizeRelations($this->limitedList($relations['nodes'] ?? null)),
                'pageInfo' => ['hasNextPage' => $this->requiredBool($relationsPage['hasNextPage'] ?? null)],
            ],
        ];
    }

    /**
     * @param  list<mixed>  $states
     * @return list<array{id: string, name: string}>
     */
    private function normalizeStates(array $states): array
    {
        $normalized = array_map(fn (mixed $state): array => [
            'id' => $this->requiredUuid($this->map($state)['id'] ?? null),
            'name' => $this->requiredText($this->map($state)['name'] ?? null, 100),
        ], $states);

        usort($normalized, static fn (array $left, array $right): int => [$left['name'], $left['id']] <=> [$right['name'], $right['id']]);

        return $normalized;
    }

    /**
     * @param  list<mixed>  $labels
     * @return list<array{name: string}>
     */
    private function normalizeLabels(array $labels): array
    {
        $normalized = array_map(fn (mixed $label): array => [
            'name' => $this->requiredText($this->map($label)['name'] ?? null, 255),
        ], $labels);

        usort($normalized, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $normalized;
    }

    /**
     * @param  list<mixed>  $attachments
     * @return list<array{title: string, url: string}>
     */
    private function normalizeAttachments(array $attachments): array
    {
        $normalized = array_map(fn (mixed $attachment): array => [
            'title' => $this->requiredText($this->map($attachment)['title'] ?? null, 1_000),
            'url' => $this->requiredText($this->map($attachment)['url'] ?? null, 2_048),
        ], $attachments);

        usort($normalized, static fn (array $left, array $right): int => [$left['title'], $left['url']] <=> [$right['title'], $right['url']]);

        return $normalized;
    }

    /**
     * @param  list<mixed>  $children
     * @return list<array{id: string}>
     */
    private function normalizeChildren(array $children): array
    {
        $normalized = array_map(fn (mixed $child): array => [
            'id' => $this->requiredUuid($this->map($child)['id'] ?? null),
        ], $children);

        usort($normalized, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $normalized;
    }

    /**
     * @param  list<mixed>  $relations
     * @return list<array{type: string, issue: array{identifier: string, state: array{type: string}}}>
     */
    private function normalizeRelations(array $relations): array
    {
        $normalized = array_map(function (mixed $relation): array {
            $relation = $this->map($relation);
            $issue = $this->map($relation['issue'] ?? null);
            $state = $this->map($issue['state'] ?? null);

            return [
                'type' => $this->requiredText($relation['type'] ?? null, 100),
                'issue' => [
                    'identifier' => $this->requiredText($issue['identifier'] ?? null, 100),
                    'state' => ['type' => $this->requiredText($state['type'] ?? null, 100)],
                ],
            ];
        }, $relations);

        usort($normalized, static fn (array $left, array $right): int => [
            $left['type'], $left['issue']['identifier'], $left['issue']['state']['type'],
        ] <=> [
            $right['type'], $right['issue']['identifier'], $right['issue']['state']['type'],
        ]);

        return $normalized;
    }

    /** @param array<string, mixed> $issue */
    public function contractHash(array $issue): string
    {
        $labelsPayload = $this->map($issue['labels'] ?? null);
        $attachmentsPayload = $this->map($issue['attachments'] ?? null);
        $labels = array_map(
            fn (mixed $label): string => str_replace(
                'proof:incus',
                'incus',
                $this->requiredText($this->map($label)['name'] ?? null, 100),
            ),
            $this->limitedList($labelsPayload['nodes'] ?? null),
        );
        $labels = array_values(array_unique($labels));
        sort($labels);

        $attachments = array_map(
            fn (mixed $attachment): array => [
                $this->requiredText($this->map($attachment)['title'] ?? null, 1_000),
                $this->requiredText($this->map($attachment)['url'] ?? null, 2_048),
            ],
            $this->limitedList($attachmentsPayload['nodes'] ?? null),
        );
        sort($attachments);

        $contents = $this->pythonJson([
            'attachments' => $attachments,
            'description' => $this->nullableText($issue['description'] ?? null, 100_000),
            'id' => $this->requiredUuid($issue['id'] ?? null),
            'labels' => $labels,
            'title' => $this->requiredText($issue['title'] ?? null, 1_000),
        ]);

        return hash('sha256', $contents);
    }

    private function pythonJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(', ', array_map($this->pythonJson(...), $value)).']';
            }

            ksort($value);
            $items = [];

            foreach ($value as $key => $item) {
                $items[] = $this->pythonJson((string) $key).': '.$this->pythonJson($item);
            }

            return '{'.implode(', ', $items).'}';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new OrbitIssueProviderFailed('The Linear issue response could not be hashed.', 0, $exception);
        }
    }

    /**
     * @param  list<array{type: string, issue: array{identifier: string, state: array{type: string}}}>  $relations
     */
    private function hasUnfinishedBlocker(array $relations): bool
    {
        foreach ($relations as $relation) {
            if ($relation['type'] === 'blocks'
                && ! in_array($relation['issue']['state']['type'], ['completed', 'canceled'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param OrbitIssuePayload $issue */
    private function hasContractHold(array $issue): bool
    {
        return ($issue['description'] !== null && str_contains($issue['description'], '## Readiness'))
            || $issue['children']['nodes'] !== []
            || $this->hasIncompleteCollections($issue)
            || $this->hasUnfinishedBlocker($issue['inverseRelations']['nodes']);
    }

    /** @param OrbitIssuePayload $issue */
    private function hasIncompleteCollections(array $issue): bool
    {
        return $issue['labels']['pageInfo']['hasNextPage']
            || $issue['attachments']['pageInfo']['hasNextPage']
            || $issue['children']['pageInfo']['hasNextPage']
            || $issue['inverseRelations']['pageInfo']['hasNextPage'];
    }

    /** @return array<mixed, mixed> */
    private function map(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    /** @return array<mixed, mixed>|null */
    private function nullableMap(mixed $value): ?array
    {
        return $value === null ? null : $this->map($value);
    }

    /** @return list<mixed> */
    private function limitedList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_COLLECTION_SIZE) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function requiredText(mixed $value, int $maxLength): string
    {
        if (! is_string($value) || trim($value) === '' || Str::length($value) > $maxLength) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if ($value !== null && (! is_string($value) || Str::length($value) > $maxLength)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function requiredUuid(mixed $value): string
    {
        if (! is_string($value) || ! $this->isUuid($value)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function requiredIssueKey(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^ORB-[0-9]+$/', $value) !== 1) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function requiredBool(mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function nullableNumber(mixed $value): float|int|null
    {
        if ($value !== null && ! is_float($value) && ! is_int($value)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new OrbitIssueProviderFailed('The Linear issue response is malformed.');
        }

        return $value;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
