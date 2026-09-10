<?php

declare(strict_types=1);

namespace App\Delivery\Config;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\ProjectConfig;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class ProjectConfigRegistry
{
    /** @var array<string, ProjectConfigDefinition> */
    private array $definitions;

    /** @param list<ProjectConfigDefinition>|null $definitions */
    public function __construct(?array $definitions = null)
    {
        $definitions ??= [new ProjectConfigDefinition(
            type: OrbitProjectConfig::TYPE,
            currentVersion: OrbitProjectConfig::VERSION,
            dataClass: OrbitProjectConfig::class,
            allowedKeys: ['type', 'version', 'repository', 'worktreeRoot', 'herdrSession', 'concurrency', 'defaultFlow'],
            upcasters: [0 => new OrbitConfigV0ToV1],
        )];

        $indexed = [];

        foreach ($definitions as $definition) {
            $indexed[$definition->type] = $definition;
        }

        $this->definitions = $indexed;
    }

    /** @param array<string, mixed> $config */
    public function hydrate(array $config): ProjectConfig
    {
        $type = $config['type'] ?? null;
        $version = $config['version'] ?? null;

        if (! is_string($type) || $type === '') {
            throw ValidationException::withMessages(['type' => 'The type field must be a non-empty string.']);
        }

        if (! is_int($version)) {
            throw ValidationException::withMessages(['version' => 'The version field must be an integer.']);
        }

        $definition = $this->definitions[$type] ?? throw new InvalidArgumentException("Unknown project config type [{$type}].");

        if ($version > $definition->currentVersion) {
            throw new InvalidArgumentException("Project config [{$type}] version {$version} is newer than supported version {$definition->currentVersion}.");
        }

        while ($version < $definition->currentVersion) {
            $upcaster = $definition->upcasters[$version] ?? throw new InvalidArgumentException("No upcaster exists for project config [{$type}] version {$version}.");
            $config = $upcaster->upcast($config);
            $version++;
            $config['type'] = $type;
            $config['version'] = $version;
        }

        $extra = array_values(array_diff(array_keys($config), $definition->allowedKeys));

        if ($extra !== []) {
            throw ValidationException::withMessages(['config' => 'Unknown config fields: '.implode(', ', $extra).'.']);
        }

        return $definition->dataClass::validateAndCreate($config);
    }
}
