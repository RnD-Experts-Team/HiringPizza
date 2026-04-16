<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class HiringRequestDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number_hired' => ['required', 'integer', 'min:1'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'integer', 'exists:employees,id', 'distinct'],
        ];
    }

    public function prepare(): void
    {
        // Ensure employee_ids count matches number_hired
        $this->merge();
    }

    protected function passedValidation(): void
    {
        $numberHired = $this->input('number_hired');
        $employeeIds = (array) $this->input('employee_ids', []);

        if (count($employeeIds) !== $numberHired) {
            $this->validator->after(function ($validator) use ($numberHired) {
                $validator->errors()->add(
                    'employee_ids',
                    "The number of employee_ids must equal number_hired ({$numberHired})"
                );
            });
        }
    }
}
