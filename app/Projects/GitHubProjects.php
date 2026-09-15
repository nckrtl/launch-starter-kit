<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

final class GitHubProjects
{
    /** @param list<string> $repositories
     * @return list<array<string, mixed>>
     */
    public function get(array $repositories): array
    {
        $repositories = array_values(array_filter($repositories, fn (string $repo): bool => ProjectDetails::repository($repo) === $repo));
        if ($repositories === []) {
            return [];
        }

        $binary = ProjectDetails::text(config('commander.github_binary'));

        return Cache::remember('commander:github:v2:'.hash('sha256', $binary.implode(',', $repositories)), 120, function () use ($repositories, $binary): array {
            $data = [];
            try {
                $fields = [];
                foreach ($repositories as $index => $repository) {
                    [$owner, $name] = explode('/', $repository, 2);
                    $fields[] = 'r'.$index.': repository(owner: "'.$owner.'", name: "'.$name.'") { '
                        .'pullRequestsAll: pullRequests(first: 20, states: [OPEN, CLOSED, MERGED], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title isDraft state } } '
                        .'pullRequestsOpen: pullRequests(first: 20, states: [OPEN], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title isDraft state } } '
                        .'pullRequestsClosed: pullRequests(first: 20, states: [CLOSED, MERGED], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title isDraft state } } '
                        .'issuesAll: issues(first: 20, states: [OPEN, CLOSED], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title state } } '
                        .'issuesOpen: issues(first: 20, states: [OPEN], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title state } } '
                        .'issuesClosed: issues(first: 20, states: [CLOSED], orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title state } } }';
                }
                $response = Process::timeout(15)
                    ->run([$binary, 'api', '--hostname', 'github.com', 'graphql', '-f', 'query={ '.implode(' ', $fields).' }'])
                    ->throw();
                $decoded = json_decode($response->output(), true, flags: JSON_THROW_ON_ERROR);
                $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
            } catch (Throwable $exception) {
                Log::warning('Project integration unavailable', ['source' => 'github', 'exception' => $exception::class]);
            }

            $result = [];
            foreach ($repositories as $index => $repository) {
                $row = $data['r'.$index] ?? null;
                $result[] = [
                    'name' => $repository,
                    'url' => 'https://github.com/'.$repository,
                    'status' => is_array($row) ? 'available' : 'unavailable',
                    'pull_requests' => $this->activity($row, $repository, 'pull', 'pullRequests'),
                    'issues' => $this->activity($row, $repository, 'issues', 'issues'),
                    'checked_at' => now()->toIso8601String(),
                ];
            }

            return $result;
        });
    }

    /** @return array{all: array{items: list<array<string, mixed>>, count: int}, open: array{items: list<array<string, mixed>>, count: int}, closed: array{items: list<array<string, mixed>>, count: int}} */
    private function activity(mixed $row, string $repository, string $type, string $field): array
    {
        return [
            'all' => $this->bucket(data_get($row, $field.'All'), $repository, $type),
            'open' => $this->bucket(data_get($row, $field.'Open'), $repository, $type),
            'closed' => $this->bucket(data_get($row, $field.'Closed'), $repository, $type),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, count: int} */
    private function bucket(mixed $connection, string $repository, string $type): array
    {
        $count = data_get($connection, 'totalCount', 0);

        return [
            'items' => $this->items(data_get($connection, 'nodes'), $repository, $type),
            'count' => is_int($count) ? $count : 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(mixed $rows, string $repository, string $type): array
    {
        $items = [];
        foreach (ProjectDetails::rows($rows) as $row) {
            if (! is_int($row['number'] ?? null)) {
                continue;
            }
            $items[] = [
                'number' => $row['number'],
                'title' => ProjectDetails::text($row['title'] ?? null),
                'draft' => ($row['isDraft'] ?? false) === true,
                'state' => match ($row['state'] ?? null) {
                    'OPEN' => 'open',
                    'CLOSED' => 'closed',
                    'MERGED' => 'merged',
                    default => null,
                },
                'url' => 'https://github.com/'.$repository.'/'.$type.'/'.$row['number'],
            ];
        }

        return $items;
    }
}
