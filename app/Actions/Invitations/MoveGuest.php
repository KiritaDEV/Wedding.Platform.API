<?php

namespace App\Actions\Invitations;

use App\Models\Guest;
use App\Models\Invitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MoveGuest
{
    public function handle(Invitation $source, Guest $guest, Invitation $destination): Guest
    {
        if ($source->is($destination)) {
            throw ValidationException::withMessages(['destinationInvitationId' => 'Choose a different destination Invitation.']);
        }

        return DB::transaction(function () use ($source, $guest, $destination): Guest {
            $locked = Invitation::query()->whereIn('id', [$source->id, $destination->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id] ?? throw ValidationException::withMessages(['guest' => 'Source Invitation is unavailable.']);
            $destination = $locked[$destination->id] ?? throw ValidationException::withMessages(['destinationInvitationId' => 'Destination Invitation is unavailable.']);
            if ($source->event_id !== $destination->event_id || $guest->event_id !== $source->event_id) {
                throw ValidationException::withMessages(['destinationInvitationId' => 'The destination must belong to the same Event.']);
            }

            $guest = $source->guests()->whereKey($guest->id)->lockForUpdate()->first();
            if ($guest === null) {
                throw ValidationException::withMessages(['guest' => 'The Guest does not belong to the source Invitation.']);
            }
            if ($source->guests()->lockForUpdate()->count() <= 1) {
                throw ValidationException::withMessages(['guest' => 'The final Guest cannot be moved out of an Invitation.']);
            }

            $guest->invitation_id = $destination->id;
            $guest->save();

            return $guest->fresh(['weddingRoles']);
        });
    }
}
