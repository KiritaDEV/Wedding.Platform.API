<?php

namespace App\Enums;

enum GuestSide: string
{
    case Unspecified = 'unspecified';
    case Bride = 'bride';
    case Groom = 'groom';
    case Both = 'both';
}
