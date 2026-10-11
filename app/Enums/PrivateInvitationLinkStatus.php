<?php

namespace App\Enums;

enum PrivateInvitationLinkStatus: string
{
    case Current = 'current';
    case Historical = 'historical';
    case Unknown = 'unknown';
}
