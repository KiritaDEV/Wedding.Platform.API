<?php

namespace App\Http\Requests;

class StoreInvitationRequest extends InvitationStateRequest
{
    protected function allowsExistingGuests(): bool
    {
        return false;
    }
}
