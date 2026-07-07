<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MilestoneGiftRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'milestone' => ['required', Rule::in(['8_days', '1_month', '2_months', '3_months', '4_months', '5_months', '6_months', '8_months', '1_year', 'other'])],
            'milestone_other' => ['required_if:milestone,other', 'nullable', 'string', 'max:255'],
        ];
    }
}
