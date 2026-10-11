<?php

namespace App\Enums;

enum UserNotificationType: string
{
    case GuestRsvpReceived = 'guest_rsvp_received';
    case GuestRsvpUpdated = 'guest_rsvp_updated';
    case AccessTransferRequested = 'access_transfer_requested';
}
