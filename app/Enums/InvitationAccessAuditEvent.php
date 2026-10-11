<?php

namespace App\Enums;

enum InvitationAccessAuditEvent: string
{
    case TrustedAccessClaimed = 'trusted_access_claimed';
    case AccessTransferRequested = 'access_transfer_requested';
    case AccessTransferApproved = 'access_transfer_approved';
    case AccessTransferRejected = 'access_transfer_rejected';
    case AccessTransferExpired = 'access_transfer_expired';
    case AccessTransferInvalidated = 'access_transfer_invalidated';
    case TrustedAccessReset = 'trusted_access_reset';
    case PrivateLinkRotated = 'private_link_rotated';
}
