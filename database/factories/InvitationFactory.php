<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'custom_name' => fake()->optional()->words(2, true),
            'status' => InvitationStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => InvitationStatus::Inactive]);
    }
}
