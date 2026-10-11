<?php

namespace App\Actions\Invitations;

use App\Enums\PrivateInvitationLinkStatus;
use App\Invitations\PrivateInvitationLinkResolution;
use App\Invitations\PrivateInvitationToken;
use App\Models\InvitationPrivateLink;

final class ResolvePrivateInvitationLink
{
    public function handle(string $token): PrivateInvitationLinkResolution
    {
        $link = InvitationPrivateLink::query()
            ->where('token_hash', PrivateInvitationToken::hash($token))
            ->with('invitation.event')
            ->first();

        if ($link === null) {
            return new PrivateInvitationLinkResolution(PrivateInvitationLinkStatus::Unknown);
        }

        return new PrivateInvitationLinkResolution(
            $link->current_slot === 'current' ? PrivateInvitationLinkStatus::Current : PrivateInvitationLinkStatus::Historical,
            $link,
        );
    }
}
