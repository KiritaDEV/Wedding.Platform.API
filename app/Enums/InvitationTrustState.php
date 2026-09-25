<?php

namespace App\Enums;

enum InvitationTrustState: string
{
    case Unclaimed = 'unclaimed';
    case Trusted = 'trusted';
    case ClaimedElsewhere = 'claimed_elsewhere';
}
