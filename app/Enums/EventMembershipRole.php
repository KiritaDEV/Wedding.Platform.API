<?php

namespace App\Enums;

enum EventMembershipRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
}
