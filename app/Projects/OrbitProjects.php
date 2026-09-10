<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class OrbitProjects
{
    /** @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function get(array $project): array
    {
        try {
            $url = rtrim(ProjectDetails::text(config('commander.orbit.url')), '/');
            $ca = ProjectDetails::text(config('commander.orbit.ca'));
            $snapshot = Cache::remember('commander:orbit:v1:'.hash('sha256', $url.$ca), 30, function () use ($url, $ca): array {
                $responses = Http::pool(fn (Pool $pool): array => array_map(
                    fn (string $resource) => $pool->as($resource)->acceptJson()->connectTimeout(2)->timeout(5)
                        ->withOptions(['verify' => $ca !== '' ? $ca : true, 'allow_redirects' => false])->get($url.'/api/v1/'.$resource),
                    ['apps', 'instances', 'nodes'],
                ));
                $data = ['checked_at' => now()->toIso8601String()];
                foreach ($responses as $key => $response) {
                    if (! $response instanceof Response || ! $response->successful() || ! is_array($response->json('data'))) {
                        if ($key === 'nodes') {
                            $data[$key] = [];

                            continue;
                        }
                        throw new RuntimeException('Orbit unavailable.');
                    }
                    $data[$key] = ProjectDetails::rows($response->json('data'));
                }

                return $data;
            });
            $apps = ProjectDetails::rows($snapshot['apps'] ?? []);
            $selected = [];
            $unmatched = [];
            $mappings = ProjectDetails::rows($project['applications'] ?? []);
            if ($mappings === []) {
                $slugMatches = array_filter($apps, static fn (array $app): bool => ($app['slug'] ?? null) === ($project['id'] ?? null));
                $repositories = $project['repositories'] ?? [];
                $mappings = $slugMatches !== [] || ! is_array($repositories) || $repositories === []
                    ? [['orbit_app_slug' => $project['id'] ?? '']]
                    : array_map(static fn (mixed $repo): array => ['repository' => $repo], $repositories);
            }
            foreach ($mappings as $mapping) {
                $matches = array_values(array_filter($apps, static function (array $app) use ($mapping): bool {
                    if (is_int($mapping['orbit_app_id'] ?? null)) {
                        return ($app['id'] ?? null) === $mapping['orbit_app_id'];
                    }
                    $slug = ProjectDetails::text($mapping['orbit_app_slug'] ?? null);
                    if ($slug !== '') {
                        return ($app['slug'] ?? null) === $slug;
                    }
                    $repo = ProjectDetails::repository($mapping['repository'] ?? null);

                    return $repo !== null && strtolower(ProjectDetails::repository($app['repository_url'] ?? null) ?? '') === strtolower($repo);
                }));
                if (count($matches) === 1) {
                    $selected[] = $matches[0];
                } else {
                    $unmatched[] = ProjectDetails::text($mapping['name'] ?? null, ProjectDetails::text($project['name'] ?? null));
                }
            }
            $result = [];
            foreach ($selected as $app) {
                $instances = [];
                foreach (ProjectDetails::rows($snapshot['instances'] ?? []) as $instance) {
                    if (($instance['app_id'] ?? null) !== ($app['id'] ?? null)) {
                        continue;
                    }
                    $nodeName = 'Node '.(is_int($instance['node_id'] ?? null) ? $instance['node_id'] : '?');
                    foreach (ProjectDetails::rows($snapshot['nodes'] ?? []) as $node) {
                        if (($node['id'] ?? null) === ($instance['node_id'] ?? null)) {
                            $nodeName = ProjectDetails::text($node['name'] ?? null, $nodeName);
                        }
                    }
                    $instances[] = [
                        'name' => ProjectDetails::text($instance['name'] ?? null),
                        'node' => $nodeName,
                        'environment' => ProjectDetails::text($instance['environment'] ?? null),
                        'branch' => ProjectDetails::text($instance['selected_branch'] ?? null),
                        'status' => ProjectDetails::text($instance['status'] ?? null),
                        'url' => ProjectDetails::url($instance['url'] ?? null),
                    ];
                }
                $result[] = ['name' => ProjectDetails::text($app['name'] ?? null), 'instances' => $instances];
            }

            return ['status' => 'available', 'apps' => $result, 'unmatched' => $unmatched, 'checked_at' => $snapshot['checked_at']];
        } catch (Throwable $exception) {
            Log::warning('Project integration unavailable', ['source' => 'orbit', 'exception' => $exception::class]);

            return ['status' => 'unavailable', 'apps' => [], 'unmatched' => [], 'checked_at' => null];
        }
    }
}
