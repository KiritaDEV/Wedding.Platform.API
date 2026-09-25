<?php

namespace App\Enums;

enum InvitationAccessInvalidationReason: string
{
    case InvitationInactive = 'invitation_inactive';
    case TrustedAccessReset = 'trusted_access_reset';
    case PrivateLinkRotated = 'private_link_rotated';
}
