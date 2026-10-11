<?php

namespace Database\Factories;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use App\Enums\GuestStatus;
use App\Models\Guest;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Guest> */
class GuestFactory extends Factory
{
    public function definition(): array
    {
        $invitation = Invitation::factory();

        return [
            'invitation_id' => $invitation,
            'event_id' => fn (array $attributes) => Invitation::query()->findOrFail($attributes['invitation_id'])->event_id,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->optional()->lastName(),
            'relationship' => GuestRelationship::GuestOther,
            'side' => GuestSide::Unspecified,
            'status' => GuestStatus::Active,
        ];
    }
}
