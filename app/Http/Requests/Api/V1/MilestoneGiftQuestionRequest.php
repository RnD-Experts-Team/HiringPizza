<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MilestoneGiftQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route()->getName();

        // Option sub-resource routes
        if (str_contains((string) $route, 'options')) {
            return [
                'option_text' => ['required', 'string', 'max:255'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            ];
        }

        // Question create/update routes
        return [
            'question_text' => ['required', 'string', 'max:500'],
            'question_type' => ['required', Rule::in(['single_select', 'multi_select'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'store_id' => ['sometimes', 'nullable', 'integer', 'exists:stores,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
