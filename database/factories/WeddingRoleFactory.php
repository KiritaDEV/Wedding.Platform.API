<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\WeddingRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WeddingRole> */
class WeddingRoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'key' => null,
            'name' => fake()->unique()->jobTitle(),
            'is_builtin' => false,
        ];
    }
}
