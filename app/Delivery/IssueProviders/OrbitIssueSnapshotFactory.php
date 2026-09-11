<?php

declare(strict_types=1);

namespace App\Delivery\IssueProviders;

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
        );
    }

    private function makeSnapshot(
        mixed $response,
        string $expectedIssueId,
        string $expectedIssueKey,
        string $expectedViewerId,
        ?string $expectedAssigneeId,
    ): OrbitIssueSnapshot {
        $root = $this->map($response);
        $data = $this->map($root['data'] ?? null);
        $viewer = $this->map($data['viewer'] ?? null);
        $issue = $this->normalizeIssue($this->map($data['issue'] ?? null));
        $viewerId = $this->requiredUuid($viewer['id'] ?? null);

        if (($root['errors'] ?? []) !== []
            || ! $this->isUuid($expectedIssueId)
            || preg_match('/^ORB-[0-9]+$/', $expectedIssueKey) !== 1
            || ! $this->isUuid($expectedViewerId)
            || ($expectedAssigneeId !== null && ! $this->isUuid($expectedAssigneeId))
            || $viewerId !== $expectedViewerId
            || $issue['id'] !== $expectedIssueId
            || $issue['identifier'] !== $expectedIssueKey) {
            throw new OrbitIssueProviderFailed('The Linear issue response does not match the requested Orbit issue.');
        }

        $active = $expectedAssigneeId !== null;
        $validAssignee = $issue['assignee'] === null
            || ($active && $issue['assignee']['id'] === $expectedAssigneeId);
        $validState = $active
            ? in_array($issue['state']['name'], ['In Progress', 'In Review'], true)
                && $issue['state']['type'] === 'started'
            : in_array($issue['state']['name'], ['Todo', 'In Progress'], true);

        if (! $validAssignee
            || ($issue['delegate']['id'] ?? null) !== $expectedViewerId
            || ! $validState
            || ($issue['description'] !== null && str_contains($issue['description'], '## Readiness'))
            || $issue['children']['nodes'] !== []
            || $issue['labels']['pageInfo']['hasNextPage']
            || $issue['attachments']['pageInfo']['hasNextPage']
            || $issue['children']['pageInfo']['hasNextPage']
            || $issue['inverseRelations']['pageInfo']['hasNextPage']
            || $this->hasUnfinishedBlocker($issue['inverseRelations']['nodes'])) {
            throw new OrbitIssueProviderFailed(
                $active
                    ? 'The active Orbit issue must remain delegated to Tom in In Progress or In Review with no unexpected assignee or contract hold.'
                    : 'The Orbit issue is not eligible: it must be solely delegated to Tom and be in Todo or In Progress without readiness, children, or unfinished blockers.',
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

    /** @param OrbitIssuePayload $issue */
    private function contractHash(array $issue): string
    {
        $labels = array_map(
            static fn (array $label): string => str_replace('proof:incus', 'incus', $label['name']),
            $issue['labels']['nodes'],
        );
        $labels = array_values(array_unique($labels));
        sort($labels);

        $attachments = array_map(
            static fn (array $attachment): array => [$attachment['title'], $attachment['url']],
            $issue['attachments']['nodes'],
        );
        sort($attachments);

        $contents = $this->pythonJson([
            'attachments' => $attachments,
            'description' => $issue['description'],
            'id' => $issue['id'],
            'labels' => $labels,
            'title' => $issue['title'],
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

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }
}
