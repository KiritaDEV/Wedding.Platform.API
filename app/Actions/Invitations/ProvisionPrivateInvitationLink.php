<?php

namespace App\Actions\Invitations;

use App\Invitations\PrivateInvitationToken;
use App\Models\Invitation;
use App\Models\InvitationPrivateLink;
use Illuminate\Database\UniqueConstraintViolationException;

class ProvisionPrivateInvitationLink
{
    public function handle(Invitation $invitation): InvitationPrivateLink
    {
        if ($current = $invitation->currentPrivateLink()->first()) {
            return $current;
        }

        do {
            $token = PrivateInvitationToken::generate();

            try {
                $link = $invitation->privateLinks()->forceCreate([
                    'token_hash' => PrivateInvitationToken::hash($token),
                    'encrypted_token' => $token,
                    'current_slot' => 'current',
                ]);

                $invitation->setRelation('currentPrivateLink', $link);

                return $link;
            } catch (UniqueConstraintViolationException) {
                $current = $invitation->currentPrivateLink()->first();
                if ($current !== null) {
                    return $current;
                }
            }
        } while (true);
    }
}
