<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HiringRequestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $totalNeeded = $this->input('employees_needed', 0);
        $candidatesCount = count((array) $this->input('candidates', []));

        return [
            'employees_needed' => ['required', 'integer', 'min:1'],
            'desired_start_date' => ['required', 'date', 'after_or_equal:today'],
            'final_notes' => ['nullable', 'string', 'max:2000'],

            'candidates' => ['sometimes', 'array', "max:{$totalNeeded}"],
            'candidates.*.name' => ['required', 'string', 'max:255'],
            'candidates.*.phone' => ['required', 'string', 'max:20'],
            'candidates.*.email' => ['required', 'email', 'max:255'],

            'positions' => [
                'required',
                'array',
                'min:1',
                function ($attribute, $value, $fail) use ($totalNeeded, $candidatesCount) {
                    if (count($value) !== ($totalNeeded - $candidatesCount)) {
                        $fail("Positions count must equal employees_needed ({$totalNeeded}) minus candidates count ({$candidatesCount})");
                    }
                }
            ],
            'positions.*.shift_type' => ['required', Rule::in(['AM', 'PM', 'OP'])],
            'positions.*.availability_type' => ['required', Rule::in(['weekday', 'weekend', 'open_availability'])],
            'positions.*.notes' => ['required', 'string', 'max:1000'],
        ];
    }
}
