<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    protected function prepareForValidation(): void
    {
        $employeeIds = $this->input('employee_ids', []);

        if (is_string($employeeIds)) {
            $employeeIds = array_filter(array_map('trim', explode(',', $employeeIds)), fn(string $value) => $value !== '');
        }

        if (!is_array($employeeIds)) {
            $employeeIds = [$employeeIds];
        }

        $this->merge([
            'number_hired' => is_numeric($this->input('number_hired'))
                ? (int) $this->input('number_hired')
                : $this->input('number_hired'),
            'employee_ids' => array_values($employeeIds),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $numberHired = (int) $this->input('number_hired');
            $employeeIds = (array) $this->input('employee_ids', []);

            if (count($employeeIds) !== $numberHired) {
                $validator->errors()->add(
                    'employee_ids',
                    "The number of employee_ids must equal number_hired ({$numberHired})"
                );
            }
        });
    }
}
