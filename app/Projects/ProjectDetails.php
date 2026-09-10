<?php

declare(strict_types=1);

namespace App\Projects;

final class ProjectDetails
{
    public static function text(mixed $value, string $fallback = ''): string
    {
        return is_string($value) ? $value : $fallback;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $fields = [];
            foreach ($row as $key => $field) {
                if (is_string($key)) {
                    $fields[$key] = $field;
                }
            }
            $rows[] = $fields;
        }

        return $rows;
    }

    public static function url(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(parse_url($value, PHP_URL_SCHEME), ['https', 'http'], true)
            && ! parse_url($value, PHP_URL_USER) ? $value : null;
    }

    public static function repository(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('#^(https://github\.com/|git@github\.com:)#', '', $value) ?? '';
        $value = preg_replace('#\.git$#', '', rtrim($value, '/')) ?? '';

        return preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#D', $value) ? $value : null;
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function get(array $manifest): array
    {
        $repositories = [];
        $configured = data_get($manifest, 'routing.github.repositories', []);
        foreach (is_array($configured) ? $configured : [] as $repository) {
            if ($slug = self::repository($repository)) {
                $repositories[strtolower($slug)] = $slug;
            }
        }
        $applications = [];
        foreach (self::rows($manifest['applications'] ?? []) as $app) {
            if ($slug = self::repository($app['repository'] ?? null)) {
                $repositories[strtolower($slug)] = $slug;
            }
            $applications[] = [
                'name' => self::text($app['name'] ?? $app['id'] ?? null, 'Application'),
                'orbit_app_id' => is_int($app['orbit_app_id'] ?? null) ? $app['orbit_app_id'] : null,
                'orbit_app_slug' => self::text($app['orbit_app_slug'] ?? null),
                'repository' => $slug,
                'url' => self::url($app['development_url'] ?? null) ?? self::url($app['runtime_url'] ?? null),
            ];
        }
        $channels = [];
        foreach (self::rows(data_get($manifest, 'slack.channels')) as $channel) {
            $id = self::text($channel['id'] ?? null);
            if (preg_match('/^[CG][A-Z0-9]+$/D', $id)) {
                $channels[] = ['name' => self::text($channel['name'] ?? null, $id), 'url' => 'https://slack.com/app_redirect?channel='.$id];
            }
        }

        return [
            'id' => self::text($manifest['id'] ?? null),
            'name' => self::text($manifest['name'] ?? null),
            'status' => self::text($manifest['status'] ?? null, 'active'),
            'repositories' => array_values($repositories),
            'applications' => $applications,
            'channels' => $channels,
            'locations' => array_map(static fn (array $row): array => [
                'machine' => self::text($row['machine'] ?? null),
                'path' => self::text($row['path'] ?? null),
            ], self::rows($manifest['locations'] ?? [])),
        ];
    }
}
