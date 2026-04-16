<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'string', 'max:120'],
            'gender' => ['sometimes', Rule::in(['male', 'female'])],
            'employment_type' => ['sometimes', Rule::in(['W2', '1099'])],

            'status' => ['sometimes', Rule::in(['hired', 'resigned', 'terminated', 'rehired', 'OJE'])],
            'status_in' => ['sometimes', 'array'],
            'status_in.*' => ['required', Rule::in(['hired', 'resigned', 'terminated', 'rehired', 'OJE'])],

            'position_id' => ['sometimes', 'integer', 'exists:positions,id'],
            'position_ids' => ['sometimes', 'array'],
            'position_ids.*' => ['required', 'integer', 'exists:positions,id'],
            'marital_id' => ['sometimes', 'integer', 'exists:marital_statuses,id'],
            'id_type_id' => ['sometimes', 'integer', 'exists:id_types,id'],
            'attachment_type_id' => ['sometimes', 'integer', 'exists:attachment_types,id'],

            'day_of_week' => ['sometimes', Rule::in(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])],
            'shift_type' => ['sometimes', Rule::in(['PM', 'AM', 'OP'])],

            'base_pay_min' => ['sometimes', 'numeric', 'min:0'],
            'base_pay_max' => ['sometimes', 'numeric', 'min:0'],
            'performance_pay_min' => ['sometimes', 'numeric', 'min:0'],
            'performance_pay_max' => ['sometimes', 'numeric', 'min:0'],
            'effective_pay_from' => ['sometimes', 'date'],
            'effective_pay_to' => ['sometimes', 'date'],

            'birth_from' => ['sometimes', 'date'],
            'birth_to' => ['sometimes', 'date'],
            'race' => ['sometimes', Rule::in(['Caucasian', 'African American', 'Hispanic', 'Asian', 'Native American', 'Other'])],
            'religion' => ['sometimes', Rule::in(['Christianity', 'Islam', 'Judaism', 'Buddhism', 'Hinduism', 'Other'])],
            'account_type' => ['sometimes', Rule::in(['checking', 'savings'])],

            'has_primary_email' => ['sometimes', 'boolean'],
            'has_primary_phone' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],

            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
            'updated_from' => ['sometimes', 'date'],
            'updated_to' => ['sometimes', 'date'],

            'sort_by' => ['sometimes', Rule::in(['id', 'first_name', 'last_name', 'created_at', 'updated_at', 'employment_type', 'gender'])],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
