<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'type' => EventType::Wedding,
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('#####'),
            'event_date' => fake()->optional()->dateTimeBetween('now', '+2 years'),
            'time_zone' => 'UTC',
            'status' => EventStatus::Active,
        ];
    }
}
