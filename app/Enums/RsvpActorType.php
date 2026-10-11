<?php

namespace App\Enums;

enum RsvpActorType: string
{
    case ManagementUser = 'management_user';
    case PrivateInvitation = 'private_invitation';
}
