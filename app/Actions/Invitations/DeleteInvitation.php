<?php

namespace App\Actions\Invitations;

use App\Models\Invitation;

final class DeleteInvitation
{
    public function handle(Invitation $invitation): void
    {
        $invitation->delete();
    }
}
