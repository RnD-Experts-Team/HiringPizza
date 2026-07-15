<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeOperationalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // When true the operational metrics are returned as a paginator,
            // otherwise the full history (start of time -> now) is returned as an array.
            'paginated' => ['sometimes', 'boolean'],

            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],

            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
