<?php

namespace Database\Factories;

use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventMembership> */
class EventMembershipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => User::factory(),
            'role' => EventMembershipRole::Admin,
        ];
    }
}
