<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SeparationRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'separation_type' => ['required', Rule::in(['termination', 'resignation'])],
            'final_working_day' => ['required', 'date', 'after_or_equal:today'],
            'termination_letter' => ['required_if:separation_type,termination', 'nullable', 'string', 'max:10000'],
            'termination_reason' => [
                'required_if:separation_type,termination',
                'nullable',
                Rule::in([
                    'performance_issues',
                    'policy_violation_misconduct',
                    'attendance_issues',
                    'no_call_no_show_more_than_2_times_job_abandonment',
                    'end_of_trial_period',
                    'reach_the_limits_of_caps_needed',
                    'other',
                ]),
            ],
            'termination_reason_details' => ['required_if:termination_reason,other', 'nullable', 'string', 'max:1000'],
            'resignation_reason' => [
                'required_if:separation_type,resignation',
                'nullable',
                Rule::in([
                    'found_another_job',
                    'school_schedule_conflict',
                    'relocation',
                    'personal_reasons',
                    'health_family_reasons',
                    'cognito_form',
                    'other'
                ])
            ],
            'resignation_reason_details' => ['required_if:resignation_reason,other', 'nullable', 'string', 'max:1000'],
            'attachments' => ['sometimes', 'array'],
            'attachments.*' => ['file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
