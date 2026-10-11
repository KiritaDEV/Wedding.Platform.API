<?php

namespace App\Enums;

enum UserNotificationSource: string
{
    case RsvpSubmission = 'rsvp_submission';
    case AccessTransferRequest = 'access_transfer_request';
}
