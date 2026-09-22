<?php

namespace App\Actions\Invitations;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use App\Enums\InvitationStatus;
use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\Invitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateInvitation
{
    /**
     * @param  list<array{first_name: string, last_name?: ?string, relationship?: GuestRelationship|string, side?: GuestSide|string, wedding_role_ids?: list<string>, custom_wedding_role_keys?: list<string>}>  $guests
     * @param  list<array{client_key: string, name: string}>  $customRoles
     */
    public function handle(Event $event, array $guests, ?string $customName = null, array $customRoles = []): Invitation
    {
        if ($guests === []) {
            throw new InvalidArgumentException('An Invitation must contain at least one Guest.');
        }

        try {
            return DB::transaction(function () use ($event, $guests, $customName, $customRoles): Invitation {
                $this->validateGuestIdentities($event, $guests);
                $referencedKeys = collect($guests)->flatMap(fn ($guest) => $guest['custom_wedding_role_keys'] ?? [])->unique()->values()->all();
                $draftRoles = app(ResolveWeddingRoles::class)->handle($event, $customRoles, $referencedKeys);
                $existingRoles = app(ResolveWeddingRoles::class)->existing(
                    $event,
                    collect($guests)->flatMap(fn ($guest) => $guest['wedding_role_ids'] ?? [])->all(),
                );

                $invitation = $event->invitations()->create([
                    'custom_name' => $customName,
                    'status' => InvitationStatus::Active,
                ]);

                foreach ($guests as $attributes) {
                    $guest = $invitation->guests()->create([
                        'event_id' => $event->getKey(),
                        'first_name' => $attributes['first_name'],
                        'last_name' => $attributes['last_name'] ?? null,
                        'relationship' => $attributes['relationship'] ?? GuestRelationship::GuestOther,
                        'side' => $attributes['side'] ?? GuestSide::Unspecified,
                    ]);
                    $ids = collect($attributes['wedding_role_ids'] ?? [])->map(fn ($id) => $existingRoles[$id]->id)
                        ->merge(collect($attributes['custom_wedding_role_keys'] ?? [])->map(fn ($key) => $draftRoles[$key]->id))
                        ->unique()->values()->all();
                    $guest->weddingRoles()->sync($ids);
                }

                return $invitation->load('guests.weddingRoles');
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['guests' => 'A Guest with this name already exists in the Event.']);
        }
    }

    private function validateGuestIdentities(Event $event, array $guests): void
    {
        $identities = collect($guests)->map(fn ($guest) => [
            NameNormalizer::identity($guest['first_name']),
            NameNormalizer::nullableIdentity($guest['last_name'] ?? null),
        ]);
        if ($identities->map(fn ($parts) => json_encode($parts))->unique()->count() !== $identities->count()) {
            throw ValidationException::withMessages(['guests' => 'Guest names must be unique within the Event.']);
        }

        foreach ($identities as [$first, $last]) {
            if ($event->guests()->where('normalized_first_name', $first)->where('normalized_last_name', $last)->exists()) {
                throw ValidationException::withMessages(['guests' => 'A Guest with this name already exists in the Event.']);
            }
        }
    }
}
