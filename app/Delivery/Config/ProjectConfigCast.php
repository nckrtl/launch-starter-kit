<?php

declare(strict_types=1);

namespace App\Delivery\Config;

use App\Delivery\Data\ProjectConfig;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/** @implements CastsAttributes<ProjectConfig, ProjectConfig|array<string, mixed>> */
final class ProjectConfigCast implements CastsAttributes, ComparesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ProjectConfig
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$key} attribute must contain JSON.");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return $this->registry()->hydrate($decoded);
    }

    /** @return array<string, string> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = $value instanceof ProjectConfig ? $value->toArray() : $value;

        if (! is_array($payload) || ! Arr::isAssoc($payload)) {
            throw new InvalidArgumentException("The {$key} attribute must be a project config or associative array.");
        }

        /** @var array<string, mixed> $payload */
        $config = $this->registry()->hydrate($payload);

        return [$key => $config->toJson()];
    }

    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        return $this->get($model, $key, $firstValue, [])->toArray()
            === $this->get($model, $key, $secondValue, [])->toArray();
    }

    private function registry(): ProjectConfigRegistry
    {
        // Eloquent constructs custom casts directly, so this narrow adapter resolves its registry from the container.
        return Container::getInstance()->make(ProjectConfigRegistry::class);
    }
}
