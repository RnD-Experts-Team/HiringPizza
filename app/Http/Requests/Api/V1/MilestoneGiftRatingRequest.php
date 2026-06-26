<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;

class MilestoneGiftRatingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->route('milestoneGiftRequest')?->store_id;

        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => [
                'required',
                'integer',
                Rule::exists('milestone_gift_questions', 'id')->where(function ($query) use ($storeId) {
                    $query->where('is_active', true)
                          ->where(function ($q) use ($storeId) {
                              $q->whereNull('store_id')->orWhere('store_id', $storeId);
                          });
                }),
            ],
            'answers.*.option_ids' => ['required'],
            'answers.*.option_ids.*' => ['integer', 'exists:milestone_gift_question_options,id'],
            'additional_comments' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $answers = $this->input('answers', []);

            foreach ($answers as $index => $answer) {
                $questionId = $answer['question_id'] ?? null;
                $optionIds = isset($answer['option_ids'])
                    ? (array) $answer['option_ids']
                    : [];

                if (!$questionId || empty($optionIds)) {
                    continue;
                }

                foreach ($optionIds as $optIdx => $optionId) {
                    if (!is_numeric($optionId)) {
                        continue;
                    }

                    $belongs = \DB::table('milestone_gift_question_options')
                        ->where('id', $optionId)
                        ->where('milestone_gift_question_id', $questionId)
                        ->exists();

                    if (!$belongs) {
                        $field = "answers.{$index}.option_ids.{$optIdx}";
                        $v->errors()->add(
                            $field,
                            "Option {$optionId} does not belong to question {$questionId}."
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'answers.*.question_id.exists' => 'The selected question is not available for this store or is inactive.',
            'answers.*.option_ids.required' => 'Each answer must include at least one option.',
            'answers.*.option_ids.*.exists' => 'One or more selected options do not exist.',
        ];
    }
}
