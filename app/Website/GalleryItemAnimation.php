<?php

namespace App\Website;

use Illuminate\Validation\ValidationException;

final class GalleryItemAnimation
{
    public const STAGGERS = ['none', 'short', 'medium', 'long'];

    /** @return array<string, mixed>|null */
    public static function normalize(mixed $value, string $path, bool $preserveExplicitNone = false): ?array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['entrance']) !== []) {
            throw ValidationException::withMessages([$path => 'Gallery item animation must contain only the canonical entrance property.']);
        }
        if (! array_key_exists('entrance', $value)) {
            return null;
        }
        $entrance = $value['entrance'];
        if (! is_array($entrance) || array_diff(array_keys($entrance), ['type', 'speed', 'stagger']) !== []) {
            throw ValidationException::withMessages(["{$path}.entrance" => 'Gallery item entrance animation contains unsupported properties.']);
        }
        $type = $entrance['type'] ?? null;
        if ($type === null) {
            return null;
        }
        if (! is_string($type) || ! in_array($type, WebsiteAnimation::TYPES, true)) {
            throw ValidationException::withMessages(["{$path}.entrance.type" => 'The Gallery item animation type is invalid.']);
        }
        if (isset($entrance['speed']) && (! is_string($entrance['speed']) || ! in_array($entrance['speed'], WebsiteAnimation::SPEEDS, true))) {
            throw ValidationException::withMessages(["{$path}.entrance.speed" => 'The Gallery item animation speed is invalid.']);
        }
        if (isset($entrance['stagger']) && (! is_string($entrance['stagger']) || ! in_array($entrance['stagger'], self::STAGGERS, true))) {
            throw ValidationException::withMessages(["{$path}.entrance.stagger" => 'The Gallery item animation stagger is invalid.']);
        }
        if ($type === 'none') {
            return $preserveExplicitNone ? ['entrance' => ['type' => 'none']] : null;
        }

        return ['entrance' => array_filter([
            'type' => $type,
            'speed' => ($entrance['speed'] ?? 'normal') === 'normal' ? null : $entrance['speed'],
            'stagger' => ($entrance['stagger'] ?? 'none') === 'none' ? null : $entrance['stagger'],
        ], fn (mixed $item): bool => $item !== null)];
    }
}
