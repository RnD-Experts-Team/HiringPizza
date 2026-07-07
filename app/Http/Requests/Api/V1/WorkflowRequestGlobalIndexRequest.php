<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkflowRequestGlobalIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeIds' => ['sometimes', 'array'],
            'storeIds.*' => ['required', 'string', 'max:50'],

            'q' => ['sometimes', 'string', 'max:120'],

            'request_type' => ['sometimes', Rule::in(['separation', 'hiring', 'milestone_gift'])],
            'request_types' => ['sometimes', 'array'],
            'request_types.*' => ['required', Rule::in(['separation', 'hiring', 'milestone_gift'])],

            'workflow_status' => ['sometimes', Rule::in(['pending', 'rejected', 'completed', 'created', 'rating', 'gift_decision', 'final_status', 'closed', 'cancelled'])],
            'workflow_statuses' => ['sometimes', 'array'],
            'workflow_statuses.*' => ['required', Rule::in(['pending', 'rejected', 'completed', 'created', 'rating', 'gift_decision', 'final_status', 'closed', 'cancelled'])],

            'decision' => ['sometimes', Rule::in(['rejected', 'completed'])],
            'decision_in' => ['sometimes', 'array'],
            'decision_in.*' => ['required', Rule::in(['rejected', 'completed'])],

            'separation_type' => ['sometimes', Rule::in(['termination', 'resignation'])],
            'shift_type' => ['sometimes', Rule::in(['AM', 'PM', 'OP'])],
            'availability_type' => ['sometimes', Rule::in(['weekday', 'weekend', 'open_availability'])],

            'milestone_gift_stage' => ['sometimes', Rule::in(['created', 'rating', 'gift_decision', 'final_status', 'closed', 'cancelled'])],
            'milestone' => ['sometimes', Rule::in(['8_days', '1_month', '2_months', '3_months', '4_months', '5_months', '6_months', '8_months', '1_year', 'other'])],

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
