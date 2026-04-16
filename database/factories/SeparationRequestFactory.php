<?php

namespace Database\Factories;

use App\Models\SeparationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeparationRequestFactory extends Factory
{
    protected $model = SeparationRequest::class;

    public function definition(): array
    {
        return [
            'store_id' => 1,
            'user_id' => 1,
            'employee_id' => 1,
            'separation_type' => $this->faker->randomElement(['termination', 'resignation']),
            'final_working_day' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'resignation_reason' => null,
            'resignation_reason_details' => null,
            'additional_notes' => $this->faker->optional()->text(),
        ];
    }

    public function resignation(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'separation_type' => 'resignation',
                'resignation_reason' => $this->faker->randomElement([
                    'found_another_job',
                    'school_schedule_conflict',
                    'relocation',
                    'personal_reasons',
                    'health_family_reasons',
                    'cognito_form',
                ]),
            ];
        });
    }
}
