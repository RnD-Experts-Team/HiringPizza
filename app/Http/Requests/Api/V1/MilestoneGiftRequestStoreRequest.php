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
            'milestone' => ['required', Rule::in(['30_days', '90_days', '6_months', '1_year', '2_years', 'other'])],
            'milestone_other' => ['required_if:milestone,other', 'nullable', 'string', 'max:255'],
        ];
    }
}
