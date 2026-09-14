<?php

namespace App\Website;

use Illuminate\Validation\ValidationException;

final class BackgroundMedia
{
    /** @return array<string, list<string>> */
    public static function rules(string $prefix): array
    {
        $rules = [
            $prefix => ['sometimes', 'nullable', 'array:assetId,focalPoint,zoom'],
            "{$prefix}.assetId" => ['sometimes', 'nullable', 'string', 'ulid'],
            "{$prefix}.focalPoint" => ['sometimes', 'array:x,y'],
            "{$prefix}.focalPoint.x" => ["required_with:{$prefix}.focalPoint", 'numeric', 'between:0,1'],
            "{$prefix}.focalPoint.y" => ["required_with:{$prefix}.focalPoint", 'numeric', 'between:0,1'],
            "{$prefix}.zoom" => ['sometimes', 'numeric', 'gt:0', 'lte:3'],
        ];

        return $rules;
    }

    public static function assertJsonNumbers(mixed $media, string $path): void
    {
        if (! is_array($media)) {
            return;
        }
        if (! array_key_exists('assetId', $media)) {
            throw ValidationException::withMessages(["{$path}.assetId" => 'Background media must contain an image asset or explicit no-image state.']);
        }
        foreach ([null] as $device) {
            $framing = $media;
            if (! is_array($framing)) {
                continue;
            }
            $fieldPath = $device === null ? $path : "{$path}.responsive.{$device}";
            if (array_key_exists('assetId', $framing) && $framing['assetId'] === null && (isset($framing['focalPoint']) || isset($framing['zoom']))) {
                throw ValidationException::withMessages(["{$fieldPath}.assetId" => 'An explicit no-image background cannot contain focal-point or zoom settings.']);
            }
            if (array_key_exists('zoom', $framing) && ! is_int($framing['zoom']) && ! is_float($framing['zoom'])) {
                throw ValidationException::withMessages(["{$fieldPath}.zoom" => 'Background image zoom must be a JSON number.']);
            }
            foreach (['x', 'y'] as $coordinate) {
                $value = $framing['focalPoint'][$coordinate] ?? null;
                if ($value !== null && ! is_int($value) && ! is_float($value)) {
                    throw ValidationException::withMessages(["{$fieldPath}.focalPoint.{$coordinate}" => 'Background image focal point must be a JSON number.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $media @return array<string, mixed> */
    public static function normalize(array $media): array
    {
        return $media;
    }

    /** @return list<string> */
    public static function assetIds(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }
        $ids = [];
        foreach ([$media['assetId'] ?? null] as $id) {
            if (is_string($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
