<?php

namespace App\Enums;

enum InvitationAccessAuditActor: string
{
    case ManagementUser = 'management_user';
    case PrivateBrowser = 'private_browser';
    case System = 'system';
}
