<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeMetricIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'emp_id_value' => ['sometimes', 'string', 'max:255'],
            'id_type_id' => ['sometimes', 'integer', 'exists:id_types,id'],
            'store_number' => ['sometimes', 'string', 'max:50'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],

            'column_key' => ['sometimes', 'string', 'max:120'],
            'column_label' => ['sometimes', 'string', 'max:255'],
            'value' => ['sometimes', 'string', 'max:255'],
            'value_min' => ['sometimes', 'numeric'],
            'value_max' => ['sometimes', 'numeric'],

            'sort_by' => ['sometimes', Rule::in(['metric_date', 'created_at', 'updated_at', 'employee_id', 'store_number'])],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
