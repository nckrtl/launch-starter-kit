<?php

declare(strict_types=1);

namespace App\Tasks;

final readonly class TaskPayload
{
    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public function matches(array $left, array $right): bool
    {
        return $this->canonicalize(json_decode(json_encode($left, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))
            === $this->canonicalize(json_decode(json_encode($right, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
