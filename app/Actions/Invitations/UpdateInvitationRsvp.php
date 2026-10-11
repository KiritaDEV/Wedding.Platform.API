<?php

namespace App\Actions\Invitations;

use App\Enums\GuestStatus;
use App\Enums\InvitationStatus;
use App\Enums\RsvpActorType;
use App\Models\Guest;
use App\Models\Invitation;
use App\Models\RsvpSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateInvitationRsvp
{
    public function handle(Invitation $invitation, User $actor, array $responses, ?string $note): array
    {
        return DB::transaction(function () use ($invitation, $actor, $responses, $note): array {
            $invitation = Invitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($invitation->status === InvitationStatus::Inactive) {
                throw ValidationException::withMessages(['invitation' => 'Reactivate this Invitation before changing RSVP responses.']);
            }
            $active = $invitation->guests()->where('status', GuestStatus::Active->value)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $desired = collect($responses)->keyBy('guest_id');
            if ($desired->count() !== count($responses) || $desired->keys()->sort()->values()->all() !== $active->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['responses' => 'Responses must contain every current active Guest exactly once.']);
            }

            $changed = $active->contains(fn (Guest $guest): bool => $guest->rsvp_response?->value !== $desired[$guest->id]['response']);
            if (! $changed) {
                return ['changed' => false, 'invitation' => $this->fresh($invitation), 'submission' => null];
            }

            foreach ($active as $guest) {
                $guest->update(['rsvp_response' => $desired[$guest->id]['response']]);
            }
            $submission = RsvpSubmission::query()->create([
                'event_id' => $invitation->event_id,
                'invitation_id' => $invitation->id,
                'actor_type' => RsvpActorType::ManagementUser,
                'actor_user_id' => $actor->id,
                'actor_name_snapshot' => $actor->name,
                'note' => $note,
            ]);
            foreach ($active->values() as $order => $guest) {
                $submission->items()->create([
                    'guest_id' => $guest->id,
                    'guest_name_snapshot' => $guest->fullName(),
                    'rsvp_response' => $desired[$guest->id]['response'],
                    'snapshot_order' => $order,
                ]);
            }

            return ['changed' => true, 'invitation' => $this->fresh($invitation), 'submission' => $submission->load('items')];
        });
    }

    private function fresh(Invitation $invitation): Invitation
    {
        return $invitation->fresh(['guests' => fn ($query) => $query->withExists('rsvpSubmissionItems')])
            ->loadMax('rsvpSubmissions', 'created_at');
    }
}
