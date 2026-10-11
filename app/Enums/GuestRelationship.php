<?php

namespace App\Enums;

enum GuestRelationship: string
{
    case GuestOther = 'guest_other';
    case Parent = 'parent';
    case FamilyMember = 'family_member';
    case Friend = 'friend';
    case Colleague = 'colleague';
}
