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

        return Cache::remember('commander:github:v1:'.hash('sha256', $binary.implode(',', $repositories)), 120, function () use ($repositories, $binary): array {
            $data = [];
            try {
                $fields = [];
                foreach ($repositories as $index => $repository) {
                    [$owner, $name] = explode('/', $repository, 2);
                    $fields[] = 'r'.$index.': repository(owner: "'.$owner.'", name: "'.$name.'") { '
                        .'pullRequests(first: 20, states: OPEN, orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title isDraft } } '
                        .'issues(first: 20, states: OPEN, orderBy: {field: UPDATED_AT, direction: DESC}) { totalCount nodes { number title } } }';
                }
                $response = Process::timeout(15)->run([$binary, 'api', '--hostname', 'github.com', 'graphql', '-f', 'query={ '.implode(' ', $fields).' }']);
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
                    'pull_requests' => $this->items(data_get($row, 'pullRequests.nodes'), $repository, 'pull'),
                    'issues' => $this->items(data_get($row, 'issues.nodes'), $repository, 'issues'),
                    'pull_request_count' => data_get($row, 'pullRequests.totalCount', 0),
                    'issue_count' => data_get($row, 'issues.totalCount', 0),
                    'checked_at' => now()->toIso8601String(),
                ];
            }

            return $result;
        });
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
                'url' => 'https://github.com/'.$repository.'/'.$type.'/'.$row['number'],
            ];
        }

        return $items;
    }
}
