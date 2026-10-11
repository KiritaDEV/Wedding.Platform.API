<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;
use Illuminate\Validation\ValidationException;

final class DeleteInvitation
{
    public function handle(Invitation $invitation): void
    {
        $invitation->load(['guests' => fn ($query) => $query->withExists('rsvpSubmissionItems')])->loadExists('rsvpSubmissions');
        if (! $invitation->canPermanentlyDelete()) {
            throw ValidationException::withMessages(['invitation' => 'An Invitation with RSVP participation cannot be permanently deleted. Deactivate it instead.']);
        }
        $invitation->delete();
    }
}
