<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\TShirtSize;
use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filling in the shirt form for a milestone the generator already opened.
 */
class ShirtMilestoneEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shirt_color_id' => [
                'required',
                'integer',
                Rule::exists('shirt_colors', 'id')->where('is_active', true),
            ],
            // The fallback when the first colour is not available. Required on
            // every new entry; it must be a different colour or it would
            // not be a fallback at all.
            'secondary_shirt_color_id' => [
                'required',
                'integer',
                'different:shirt_color_id',
                Rule::exists('shirt_colors', 'id')->where('is_active', true),
            ],
            'shirt_logo_id' => [
                'required',
                'integer',
                Rule::exists('shirt_logos', 'id')->where('is_active', true),
            ],
            'shirt_template_id' => [
                'nullable',
                'integer',
                Rule::exists('shirt_templates', 'id')->where('is_active', true),
            ],
            // Conditionally required — see withValidator(). It depends on
            // whether this employee already has a size on file, which no
            // declarative rule can see.
            't_shirt_size' => ['nullable', Rule::in(array_column(TShirtSize::cases(), 'value'))],
            'entry_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->filled('t_shirt_size')) {
                return;
            }

            $employee = $this->resolveEmployee();

            if ($employee === null) {
                return;
            }

            if ($employee->obsession?->t_shirt === null) {
                $v->errors()->add(
                    't_shirt_size',
                    'This employee has no shirt size on file, so a size is required.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'shirt_color_id.exists' => 'The selected shirt colour is not available.',
            'secondary_shirt_color_id.required' => 'Pick a secondary colour, in case the first one is not available.',
            'secondary_shirt_color_id.different' => 'The secondary colour must be different from the shirt colour.',
            'secondary_shirt_color_id.exists' => 'The selected secondary colour is not available.',
            'shirt_logo_id.exists' => 'The selected logo is not available.',
            'shirt_template_id.exists' => 'The selected shirt template is not available.',
        ];
    }

    protected function resolveEmployee(): ?Employee
    {
        $milestone = $this->route('shirtMilestone');

        if (!$milestone instanceof EmployeeShirtMilestone) {
            return null;
        }

        return $milestone->employee()->with('obsession')->first();
    }
}
