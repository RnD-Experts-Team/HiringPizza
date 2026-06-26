<?php

namespace Database\Seeders;

use App\Models\MilestoneGiftQuestion;
use Illuminate\Database\Seeder;

class MilestoneGiftQuestionsSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            [
                'question_text' => 'How would you rate this employee\'s attendance?',
                'question_type' => 'single_select',
                'sort_order' => 1,
                'options' => [
                    ['option_text' => 'Never calls off', 'sort_order' => 1],
                    ['option_text' => 'Rarely calls off', 'sort_order' => 2],
                    ['option_text' => 'Occasionally calls off', 'sort_order' => 3],
                    ['option_text' => 'Frequently calls off', 'sort_order' => 4],
                ],
            ],
            [
                'question_text' => 'Do you foresee this employee remaining with the company for the next 2 months?',
                'question_type' => 'single_select',
                'sort_order' => 2,
                'options' => [
                    ['option_text' => 'Definitely Yes', 'sort_order' => 1],
                    ['option_text' => 'Probably Yes', 'sort_order' => 2],
                    ['option_text' => 'Unsure', 'sort_order' => 3],
                    ['option_text' => 'Probably No', 'sort_order' => 4],
                    ['option_text' => 'Definitely No', 'sort_order' => 5],
                ],
            ],
            [
                'question_text' => 'Select all stations where this employee consistently performs at a high level:',
                'question_type' => 'multi_select',
                'sort_order' => 3,
                'options' => [
                    ['option_text' => 'Make Station', 'sort_order' => 1],
                    ['option_text' => 'Landing Station', 'sort_order' => 2],
                    ['option_text' => 'Register', 'sort_order' => 3],
                    ['option_text' => 'Dough', 'sort_order' => 4],
                    ['option_text' => 'Drive-Thru (if applicable)', 'sort_order' => 5],
                    ['option_text' => 'Training Others', 'sort_order' => 6],
                    ['option_text' => 'Cleaning & Organization', 'sort_order' => 7],
                    ['option_text' => 'Customer Service', 'sort_order' => 8],
                ],
            ],
        ];

        foreach ($questions as $questionData) {
            $options = $questionData['options'];
            unset($questionData['options']);

            $existingQuestion = MilestoneGiftQuestion::query()
                ->whereNull('store_id')
                ->where('question_text', $questionData['question_text'])
                ->first();

            if ($existingQuestion) {
                continue;
            }

            $question = MilestoneGiftQuestion::query()->create(array_merge($questionData, [
                'store_id' => null,
                'is_active' => true,
            ]));

            foreach ($options as $option) {
                $question->options()->create($option);
            }
        }
    }
}
