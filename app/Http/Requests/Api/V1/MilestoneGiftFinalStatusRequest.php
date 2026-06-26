<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MilestoneGiftFinalStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                'delivered_to_employee',
                'sent_to_store_awaiting_pickup',
                'not_delivered_no_longer_with_company',
                'not_delivered_other_reason',
            ])],
            'status_other_reason' => ['required_if:status,not_delivered_other_reason', 'nullable', 'string', 'max:500'],
            'confirmation_date' => ['required', 'date'],
            'close' => ['sometimes', 'boolean'],
            'closing_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
