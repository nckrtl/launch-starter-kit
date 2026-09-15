<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Tasks\TaskInput;
use Illuminate\Foundation\Http\FormRequest;

final class ReorderTaskChildrenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return TaskInput::reorder();
    }
}
