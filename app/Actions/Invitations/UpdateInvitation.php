<?php

namespace App\Actions\Invitations;

use App\Enums\GuestRelationship;
use App\Enums\GuestSide;
use App\Enums\GuestStatus;
use App\Invitations\NameNormalizer;
use App\Models\Invitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateInvitation
{
    /**
     * @param  list<array{id?: string, first_name: string, last_name?: ?string, relationship?: GuestRelationship|string, side?: GuestSide|string, wedding_role_ids?: list<string>, custom_wedding_role_keys?: list<string>}>  $guests
     * @param  list<array{client_key: string, name: string}>  $customRoles
     */
    public function handle(Invitation $invitation, array $guests, ?string $customName, array $customRoles = [], array $deletedGuestIds = []): Invitation
    {
        try {
            return DB::transaction(function () use ($invitation, $guests, $customName, $customRoles, $deletedGuestIds): Invitation {
                $invitation = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                $current = $invitation->guests()->lockForUpdate()->get()->keyBy('id');
                $submittedIds = collect($guests)->pluck('id')->filter()->values();
                if ($submittedIds->unique()->count() !== $submittedIds->count()
                    || $submittedIds->contains(fn ($id) => ! $current->has($id))) {
                    throw ValidationException::withMessages(['guests' => 'An existing Guest does not belong to this Invitation.']);
                }
                $deletedIds = collect($deletedGuestIds);
                if ($deletedIds->unique()->count() !== $deletedIds->count()
                    || $deletedIds->contains(fn ($id) => ! $current->has($id))
                    || $deletedIds->intersect($submittedIds)->isNotEmpty()) {
                    throw ValidationException::withMessages(['deletedGuestIds' => 'A Guest selected for deletion is invalid.']);
                }
                if ($deletedIds->isNotEmpty() && ($current->whereIn('id', $deletedIds)->contains(fn ($guest) => $guest->rsvp_response !== null)
                    || DB::table('rsvp_submission_items')->whereIn('guest_id', $deletedIds)->exists())) {
                    throw ValidationException::withMessages(['deletedGuestIds' => 'A Guest with RSVP participation cannot be permanently deleted. Deactivate the Guest instead.']);
                }

                $finalStatuses = $current->reject(fn ($guest) => $deletedIds->contains($guest->id))
                    ->mapWithKeys(fn ($guest) => [$guest->id => $guest->status]);
                foreach ($guests as $guest) {
                    if (isset($guest['id'])) {
                        $finalStatuses[$guest['id']] = GuestStatus::from($guest['status'] ?? GuestStatus::Active->value);
                    } else {
                        $finalStatuses[] = GuestStatus::Active;
                    }
                }
                if ($finalStatuses->filter(fn ($status) => $status === GuestStatus::Active)->isEmpty()) {
                    throw ValidationException::withMessages(['guests' => 'An Invitation must contain at least one active Guest.']);
                }

                $identities = collect($guests)->map(fn ($guest) => [
                    NameNormalizer::identity($guest['first_name']),
                    NameNormalizer::nullableIdentity($guest['last_name'] ?? null),
                ]);
                if ($identities->map(fn ($parts) => json_encode($parts))->unique()->count() !== $identities->count()) {
                    throw ValidationException::withMessages(['guests' => 'Guest names must be unique within the Event.']);
                }
                foreach ($identities as [$first, $last]) {
                    if ($invitation->event->guests()->where('invitation_id', '!=', $invitation->id)
                        ->where('normalized_first_name', $first)->where('normalized_last_name', $last)->exists()) {
                        throw ValidationException::withMessages(['guests' => 'A Guest with this name already exists in the Event.']);
                    }
                }

                $referencedKeys = collect($guests)->flatMap(fn ($guest) => $guest['custom_wedding_role_keys'] ?? [])->unique()->values()->all();
                $draftRoles = app(ResolveWeddingRoles::class)->handle($invitation->event, $customRoles, $referencedKeys);
                $existingRoles = app(ResolveWeddingRoles::class)->existing(
                    $invitation->event,
                    collect($guests)->flatMap(fn ($guest) => $guest['wedding_role_ids'] ?? [])->all(),
                );

                $invitation->update(['custom_name' => $customName]);
                $invitation->guests()->whereIn('id', $deletedIds)->delete();
                foreach ($current->whereIn('id', $submittedIds)->keys() as $id) {
                    DB::table('guests')->where('id', $id)->update([
                        'normalized_first_name' => '__editing__'.$id,
                        'normalized_last_name' => '',
                    ]);
                }

                foreach ($guests as $attributes) {
                    $guest = isset($attributes['id']) ? $current[$attributes['id']] : $invitation->guests()->make();
                    $guest->event_id = $invitation->event_id;
                    $guest->first_name = $attributes['first_name'];
                    $guest->last_name = $attributes['last_name'] ?? null;
                    $guest->relationship = $attributes['relationship'] ?? GuestRelationship::GuestOther;
                    $guest->side = $attributes['side'] ?? GuestSide::Unspecified;
                    $guest->status = isset($attributes['id']) ? ($attributes['status'] ?? GuestStatus::Active) : GuestStatus::Active;
                    $guest->save();

                    $roleIds = collect($attributes['wedding_role_ids'] ?? [])->map(fn ($id) => $existingRoles[$id]->id)
                        ->merge(collect($attributes['custom_wedding_role_keys'] ?? [])->map(fn ($key) => $draftRoles[$key]->id))
                        ->unique()->values()->all();
                    $guest->weddingRoles()->sync($roleIds);
                }

                return $invitation->fresh(['guests.weddingRoles']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['guests' => 'A Guest with this name already exists in the Event.']);
        }
    }
}
