<?php

declare(strict_types=1);

namespace App\Tasks;

final class TaskInput
{
    /** @return array<string, list<string>> */
    public static function brief(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'acceptance_criteria' => ['nullable', 'string', 'max:50000'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function create(): array
    {
        return [
            ...self::brief(),
            'kind' => ['required', 'string', 'in:group,executable'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'creation_key' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function update(): array
    {
        return [...self::brief(), 'expected_version' => ['required', 'string', 'size:64']];
    }

    /** @return array<string, list<string>> */
    public static function reorder(): array
    {
        return [
            'ordered_ids' => ['present', 'array', 'list'],
            'ordered_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'expected_ids' => ['present', 'array', 'list'],
            'expected_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
