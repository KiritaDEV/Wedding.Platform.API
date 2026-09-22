<?php

namespace App\Invitations;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;

final class NameNormalizer
{
    public static function display(string $value): string
    {
        $canonical = Normalizer::normalize($value, Normalizer::FORM_C);
        if ($canonical === false) {
            throw new InvalidArgumentException('The name must contain valid Unicode.');
        }

        $collapsed = preg_replace('/\s+/u', ' ', $canonical);
        if ($collapsed === null) {
            throw new InvalidArgumentException('The name must contain valid Unicode.');
        }

        return trim($collapsed);
    }

    public static function identity(string $value): string
    {
        return Str::lower(self::display($value));
    }

    public static function nullableDisplay(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::display($value);

        return $normalized === '' ? null : $normalized;
    }

    public static function nullableIdentity(?string $value): string
    {
        return self::identity($value ?? '');
    }
}
