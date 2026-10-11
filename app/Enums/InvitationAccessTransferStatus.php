<?php

namespace App\Enums;

enum InvitationAccessTransferStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Invalidated = 'invalidated';
}
