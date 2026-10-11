<?php

namespace App\Enums;

enum PlatformRole: string
{
    case User = 'user';
    case SuperAdmin = 'superAdmin';
}
