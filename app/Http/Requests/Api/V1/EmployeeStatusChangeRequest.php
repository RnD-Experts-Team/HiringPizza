<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeStatusChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['hired', 'resigned', 'terminated', 'rehired', 'OJE'])],
            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
