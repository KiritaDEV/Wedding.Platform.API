<?php

namespace App\Invitations;

use App\Enums\InvitationStatus;
use App\Enums\RsvpResponse;
use App\Models\Invitation;
use Carbon\CarbonImmutable;

final class PrivateInvitationRsvpView
{
    /** @return array<string, mixed> */
    public function make(Invitation $invitation): array
    {
        $invitation->load(['activeGuests'])->loadMax('rsvpSubmissions', 'created_at');
        $guests = $invitation->activeGuests;
        $attending = $guests->where('rsvp_response', RsvpResponse::Attending)->count();
        $declined = $guests->where('rsvp_response', RsvpResponse::Declined)->count();
        $pending = $guests->count() - $attending - $declined;

        return [
            'status' => $pending === $guests->count() ? 'pending' : ($pending > 0 ? 'partial' : 'complete'),
            'attendingCount' => $attending,
            'declinedCount' => $declined,
            'pendingCount' => $pending,
            'lastUpdated' => $invitation->rsvp_submissions_max_created_at === null
                ? null
                : CarbonImmutable::parse($invitation->rsvp_submissions_max_created_at)->toISOString(),
            'availability' => $this->availability($invitation),
            'guests' => $guests->map(fn ($guest): array => [
                'id' => $guest->id,
                'name' => $guest->fullName(),
                'response' => $guest->rsvp_response?->value,
            ])->values()->all(),
        ];
    }

    public function availability(Invitation $invitation): string
    {
        if ($invitation->status === InvitationStatus::Inactive) {
            return 'invitation_inactive';
        }
        if (! $invitation->event->rsvp_is_open) {
            return 'event_closed';
        }
        if ($invitation->event->hasRsvpDeadlineExpired()) {
            return 'deadline_passed';
        }

        return 'open';
    }
}
