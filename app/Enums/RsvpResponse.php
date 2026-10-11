<?php

namespace App\Enums;

enum RsvpResponse: string
{
    case Attending = 'attending';
    case Declined = 'declined';
}
