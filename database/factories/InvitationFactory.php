<?php

namespace Database\Factories;

use App\Actions\Invitations\ProvisionPrivateInvitationLink;
use App\Enums\InvitationStatus;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(
            fn (Invitation $invitation) => app(ProvisionPrivateInvitationLink::class)->handle($invitation),
        );
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }

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
