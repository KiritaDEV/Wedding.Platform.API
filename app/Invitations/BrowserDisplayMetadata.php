<?php

namespace App\Invitations;

final class BrowserDisplayMetadata
{
    /** @return array{browser: ?string, platform: ?string} */
    public function fromUserAgent(?string $userAgent): array
    {
        if (! is_string($userAgent) || $userAgent === '') {
            return ['browser' => null, 'platform' => null];
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'CriOS/') || str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $platform = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return ['browser' => $browser, 'platform' => $platform];
    }
}
