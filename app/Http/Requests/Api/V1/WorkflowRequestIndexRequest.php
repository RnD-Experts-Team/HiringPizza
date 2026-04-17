<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkflowRequestIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:120'],

            'request_type' => ['sometimes', Rule::in(['separation', 'hiring'])],
            'request_types' => ['sometimes', 'array'],
            'request_types.*' => ['required', Rule::in(['separation', 'hiring'])],

            'workflow_status' => ['sometimes', Rule::in(['pending', 'rejected', 'completed'])],
            'workflow_statuses' => ['sometimes', 'array'],
            'workflow_statuses.*' => ['required', Rule::in(['pending', 'rejected', 'completed'])],

            'decision' => ['sometimes', Rule::in(['rejected', 'completed'])],
            'decision_in' => ['sometimes', 'array'],
            'decision_in.*' => ['required', Rule::in(['rejected', 'completed'])],

            'separation_type' => ['sometimes', Rule::in(['termination', 'resignation'])],
            'shift_type' => ['sometimes', Rule::in(['AM', 'PM', 'OP'])],
            'availability_type' => ['sometimes', Rule::in(['weekday', 'weekend', 'open_availability'])],

            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
            'requested_by_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'decision_by_user_id' => ['sometimes', 'integer', 'exists:users,id'],

            'desired_start_from' => ['sometimes', 'date'],
            'desired_start_to' => ['sometimes', 'date'],
            'final_working_from' => ['sometimes', 'date'],
            'final_working_to' => ['sometimes', 'date'],

            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date'],
            'decided_from' => ['sometimes', 'date'],
            'decided_to' => ['sometimes', 'date'],

            'sort_by' => ['sometimes', Rule::in(['requested_at', 'id', 'final_working_day', 'desired_start_date', 'latest_decided_at'])],
            'sort_dir' => ['sometimes', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
