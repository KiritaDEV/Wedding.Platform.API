<?php

namespace App\Actions\Invitations;

use App\Enums\InvitationStatus;
use App\Models\Invitation;

final class SetInvitationStatus
{
    public function handle(Invitation $invitation, InvitationStatus $status): Invitation
    {
        if ($invitation->status !== $status) {
            $invitation->update(['status' => $status]);
        }

        return $invitation->fresh(['guests.weddingRoles']);
    }
}
