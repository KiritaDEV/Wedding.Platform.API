<?php

namespace App\Website;

use Illuminate\Validation\ValidationException;

final class WebsiteAnimation
{
    public const TYPES = ['none', 'fade', 'fade-up', 'fade-down', 'scale-in'];

    public const SPEEDS = ['fast', 'normal', 'slow'];

    public const DELAYS = ['none', 'short', 'medium', 'long'];

    /** @return array<string, mixed>|null */
    public static function normalize(mixed $value, string $path, bool $preserveExplicitNone = false): ?array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['entrance']) !== []) {
            throw ValidationException::withMessages([$path => 'Animation must contain only the canonical entrance property.']);
        }
        if (! array_key_exists('entrance', $value)) {
            return null;
        }
        $entrance = $value['entrance'];
        if (! is_array($entrance) || array_diff(array_keys($entrance), ['type', 'speed', 'delay']) !== []) {
            throw ValidationException::withMessages(["{$path}.entrance" => 'Entrance animation contains unsupported properties.']);
        }
        $type = $entrance['type'] ?? null;
        if ($type === null) {
            return null;
        }
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(["{$path}.entrance.type" => 'The entrance animation type is invalid.']);
        }
        foreach (['speed' => self::SPEEDS, 'delay' => self::DELAYS] as $key => $allowed) {
            if (array_key_exists($key, $entrance) && (! is_string($entrance[$key]) || ! in_array($entrance[$key], $allowed, true))) {
                throw ValidationException::withMessages(["{$path}.entrance.{$key}" => "The entrance animation {$key} is invalid."]);
            }
        }
        if ($type === 'none') {
            return $preserveExplicitNone ? ['entrance' => ['type' => 'none']] : null;
        }

        return ['entrance' => array_filter([
            'type' => $type,
            'speed' => $entrance['speed'] ?? null,
            'delay' => $entrance['delay'] ?? null,
        ], fn (mixed $item): bool => $item !== null)];
    }

    /**
     * Remove animation fields before legacy explicit-key validators run.
     *
     * @param  array<string,mixed>  $owner
     * @return array{base?:array<string,mixed>,responsive?:array<string,array<string,mixed>>}
     */
    public static function extract(array &$owner, string $path = 'element.appearance'): array
    {
        $extracted = [];
        $removedAnimation = false;
        if (! is_array($owner['appearance'] ?? null)) {
            return $extracted;
        }
        if (array_key_exists('animation', $owner['appearance'])) {
            $removedAnimation = true;
            $normalized = self::normalize($owner['appearance']['animation'], "{$path}.animation");
            unset($owner['appearance']['animation']);
            if ($normalized !== null) {
                $extracted['base'] = $normalized;
            }
        }
        foreach (['tablet', 'mobile'] as $viewport) {
            if (! array_key_exists('animation', $owner['appearance']['responsive'][$viewport] ?? [])) {
                continue;
            }
            $removedAnimation = true;
            $normalized = self::normalize($owner['appearance']['responsive'][$viewport]['animation'], "{$path}.responsive.{$viewport}.animation", true);
            unset($owner['appearance']['responsive'][$viewport]['animation']);
            if (($owner['appearance']['responsive'][$viewport] ?? null) === []) {
                unset($owner['appearance']['responsive'][$viewport]);
            }
            if (($owner['appearance']['responsive'] ?? null) === []) {
                unset($owner['appearance']['responsive']);
            }
            if ($normalized !== null) {
                $extracted['responsive'][$viewport] = $normalized;
            }
        }
        if ($removedAnimation && ($owner['appearance'] ?? null) === []) {
            unset($owner['appearance']);
        }

        return $extracted;
    }

    /** @param array<string,mixed> $owner @param array{base?:array<string,mixed>,responsive?:array<string,array<string,mixed>>} $animation */
    public static function restore(array &$owner, array $animation): void
    {
        if (isset($animation['base'])) {
            $owner['appearance']['animation'] = $animation['base'];
        }
        foreach ($animation['responsive'] ?? [] as $viewport => $value) {
            $owner['appearance']['responsive'][$viewport]['animation'] = $value;
        }
    }
}
