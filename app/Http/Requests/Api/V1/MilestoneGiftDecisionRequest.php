<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MilestoneGiftDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_cancelled' => ['required', 'boolean'],
            'cancellation_reason' => ['required_if:is_cancelled,true', 'nullable', 'string', 'max:1000'],
            'gift_description' => ['required_if:is_cancelled,false', 'nullable', 'string', 'max:500'],
            'gift_cost' => ['required_if:is_cancelled,false', 'nullable', 'numeric', 'min:0', 'max:99999.99'],
            'delivery_date' => ['required_if:is_cancelled,false', 'nullable', 'date'],
            'sent_to_store' => ['nullable', 'boolean'],
        ];
    }
}
