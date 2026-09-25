<?php

namespace App\Invitations;

final class BrowserCredentialSecret
{
    public const ENTROPY_BYTES = 32;

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function verifies(string $secret, string $expectedHash): bool
    {
        return hash_equals($expectedHash, self::hash($secret));
    }
}
