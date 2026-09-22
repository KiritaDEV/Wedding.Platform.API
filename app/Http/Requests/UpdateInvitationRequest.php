<?php

namespace App\Http\Requests;

class UpdateInvitationRequest extends InvitationStateRequest
{
    protected function allowsExistingGuests(): bool
    {
        return true;
    }
}
