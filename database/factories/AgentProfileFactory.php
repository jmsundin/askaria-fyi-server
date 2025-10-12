<?php

namespace Database\Factories;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentProfile>
 */
class AgentProfileFactory extends Factory
{
    protected $model = AgentProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'business_phone_number' => fake()->unique()->e164PhoneNumber(),
            'business_overview' => fake()->paragraph(),
            'core_services' => [],
            'faq' => [],
        ];
    }
}


