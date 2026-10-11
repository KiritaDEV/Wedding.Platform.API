<?php

namespace App\Invitations;

use App\Models\InvitationBrowserCredential;
use Symfony\Component\HttpFoundation\Cookie;

final class InvitationTrustCookie
{
    private const PREFIX = 'wedding_invitation_trust_';

    private const MINUTES = 60 * 24 * 365 * 5;

    public function name(InvitationBrowserCredential $credential): string
    {
        return self::PREFIX.$credential->getKey();
    }

    public function make(InvitationBrowserCredential $credential, string $secret): Cookie
    {
        return cookie(
            $this->name($credential),
            $secret,
            self::MINUTES,
            '/',
            null,
            config('session.secure') ?? app()->isProduction(),
            true,
            false,
            'lax',
        );
    }
}
