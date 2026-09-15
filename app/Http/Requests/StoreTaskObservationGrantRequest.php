<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTaskObservationGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cols' => ['sometimes', 'integer', 'between:40,240'],
            'rows' => ['sometimes', 'integer', 'between:12,100'],
        ];
    }

    public function columns(): int
    {
        return $this->integer('cols', 120);
    }

    public function rows(): int
    {
        return $this->integer('rows', 36);
    }
}
