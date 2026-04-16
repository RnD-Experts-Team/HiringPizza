<?php

namespace Database\Factories;

use App\Models\HiringRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class HiringRequestFactory extends Factory
{
    protected $model = HiringRequest::class;

    public function definition(): array
    {
        return [
            'store_id' => 1,
            'user_id' => 1,
            'employees_needed' => $this->faker->numberBetween(1, 10),
            'desired_start_date' => $this->faker->dateTimeBetween('+1 day', '+30 days'),
            'final_notes' => $this->faker->optional()->text(),
        ];
    }
}
