<?php

namespace App\Invitations;

final class LikePattern
{
    public const ESCAPE_CHARACTER = '!';

    public static function contains(string $value): string
    {
        $escaped = str_replace(
            [self::ESCAPE_CHARACTER, '%', '_'],
            [self::ESCAPE_CHARACTER.self::ESCAPE_CHARACTER, self::ESCAPE_CHARACTER.'%', self::ESCAPE_CHARACTER.'_'],
            $value,
        );

        return '%'.$escaped.'%';
    }

    public static function clause(string $expression): string
    {
        return $expression." LIKE ? ESCAPE '".self::ESCAPE_CHARACTER."'";
    }
}
