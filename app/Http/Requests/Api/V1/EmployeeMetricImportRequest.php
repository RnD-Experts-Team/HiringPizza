<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeMetricImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:51200'],
            'id_type_id' => ['sometimes', 'integer', 'exists:id_types,id'],
        ];
    }
}
