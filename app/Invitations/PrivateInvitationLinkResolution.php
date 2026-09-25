<?php

namespace App\Invitations;

use App\Enums\PrivateInvitationLinkStatus;
use App\Models\InvitationPrivateLink;

final readonly class PrivateInvitationLinkResolution
{
    public function __construct(
        public PrivateInvitationLinkStatus $status,
        public ?InvitationPrivateLink $link = null,
    ) {}
}
