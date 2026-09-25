<?php

namespace App\Invitations;

final class PrivateInvitationToken
{
    public const ENTROPY_BYTES = 32;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
