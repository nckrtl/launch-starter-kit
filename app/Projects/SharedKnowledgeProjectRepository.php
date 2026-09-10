<?php

declare(strict_types=1);

namespace App\Projects;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class SharedKnowledgeProjectRepository
{
    public function __construct(private Filesystem $files) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $root = $this->root();

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        /** @var list<array{id: string, name: string, status: string, locations: list<mixed>, board: string|null}> $projects */
        $projects = [];

        foreach ($this->files->directories($root) as $directory) {
            $id = basename($directory);

            if ($id === 'archived' || str_starts_with($id, '.') || str_starts_with($id, '_')) {
                continue;
            }

            $project = $this->readManifest($id);

            if ($project !== null) {
                $projects[] = $this->summary($project);
            }
        }

        usort($projects, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $projects;
    }

    /** @return array<string, mixed> */
    public function find(string $id): array
    {
        return $this->readManifest($this->validId($id))
            ?? throw new InvalidArgumentException("Project [{$id}] does not exist.");
    }

    /** @param array{name: string, status: string} $attributes
     * @return array<string, mixed>
     */
    public function create(string $id, array $attributes): array
    {
        $id = $this->validId($id);
        $directory = $this->projectDirectory($id);

        if ($this->files->exists($directory)) {
            throw new InvalidArgumentException("Project [{$id}] already exists.");
        }

        if (! $this->files->makeDirectory($directory, 0755, true)) {
            throw new RuntimeException("Could not create project [{$id}].");
        }

        $project = [
            'id' => $id,
            'name' => trim($attributes['name']),
            'status' => $attributes['status'],
            'slack' => ['channels' => []],
            'locations' => [],
        ];

        $this->writeManifest($id, $project);

        return $project;
    }

    /** @param array{name?: string, status?: string} $attributes
     * @return array<string, mixed>
     */
    public function update(string $id, array $attributes): array
    {
        $id = $this->validId($id);
        $project = $this->find($id);

        foreach (['name', 'status'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $project[$key] = trim($attributes[$key]);
            }
        }

        $this->writeManifest($id, $project);

        return $project;
    }

    private function root(): string
    {
        $root = config('commander.projects_path');

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('Commander project path is not configured.');
        }

        return rtrim($root, '/');
    }

    private function validId(string $id): string
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) || strlen($id) > 80) {
            throw new InvalidArgumentException('Project IDs must be lowercase slugs of at most 80 characters.');
        }

        return $id;
    }

    private function projectDirectory(string $id): string
    {
        return $this->root().'/'.$this->validId($id);
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $id): ?array
    {
        $path = $this->projectDirectory($id).'/project.yaml';

        if (! $this->files->isFile($path) || is_link(dirname($path)) || is_link($path)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        $manifest = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $manifest[$key] = $value;
            }
        }

        $manifest['id'] = $id;

        return $manifest;
    }

    /** @param array<string, mixed> $project
     * @return array{id: string, name: string, status: string, locations: list<mixed>, board: string|null}
     */
    private function summary(array $project): array
    {
        return [
            'id' => $this->string($project['id'] ?? null),
            'name' => $this->string($project['name'] ?? null, $this->string($project['id'] ?? null, 'Unnamed project')),
            'status' => $this->string($project['status'] ?? null, 'unknown'),
            'locations' => array_values(is_array($project['locations'] ?? null) ? $project['locations'] : []),
            'board' => $this->board($project['orchestration'] ?? null),
        ];
    }

    private function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function board(mixed $orchestration): ?string
    {
        if (! is_array($orchestration)) {
            return null;
        }

        $board = $orchestration['board'] ?? null;

        return is_string($board) ? $board : null;
    }

    /** @param array<string, mixed> $project */
    private function writeManifest(string $id, array $project): void
    {
        $path = $this->projectDirectory($id).'/project.yaml';
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        $yaml = Yaml::dump($project, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)."\n";

        $handle = fopen($path.'.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException("Could not lock project [{$id}].");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("Could not lock project [{$id}].");
            }

            $this->files->put($temporary, $yaml, true);

            if (! rename($temporary, $path)) {
                throw new RuntimeException("Could not save project [{$id}].");
            }
        } finally {
            if ($this->files->exists($temporary)) {
                $this->files->delete($temporary);
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
