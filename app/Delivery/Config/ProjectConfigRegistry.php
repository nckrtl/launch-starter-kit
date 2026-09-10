<?php

declare(strict_types=1);

namespace App\Delivery\Config;

use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\ProjectConfig;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class ProjectConfigRegistry
{
    /** @param array<string, mixed> $config */
    public function hydrate(array $config): ProjectConfig
    {
        $type = $config['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw ValidationException::withMessages(['type' => 'The type field must be a non-empty string.']);
        }

        $dataClass = match ($type) {
            OrbitProjectConfig::TYPE => OrbitProjectConfig::class,
            default => throw new InvalidArgumentException("Unknown project config type [{$type}]."),
        };

        $extra = array_values(array_diff(array_keys($config), OrbitProjectConfig::KEYS));

        if ($extra !== []) {
            throw ValidationException::withMessages(['config' => 'Unknown config fields: '.implode(', ', $extra).'.']);
        }

        return $dataClass::validateAndCreate($config);
    }
}
