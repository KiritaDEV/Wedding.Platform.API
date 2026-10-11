<?php

namespace App\Website;

final class ProjectColorLibrary
{
    public const ID_PREFIX = 'project-color-';

    public const MAXIMUM = 32;

    public const FORMAT = 'opaqueHex';

    public function normalizeValue(string $value): ?string
    {
        $normalized = strtoupper($value);

        return preg_match('/^#[0-9A-F]{6}$/', $normalized) === 1 ? $normalized : null;
    }

    /** @return list<array{id: string, value: string}> */
    public function normalize(mixed $colors): array
    {
        if (! is_array($colors)) {
            return [];
        }

        $normalized = [];
        $ids = [];
        $values = [];
        foreach ($colors as $color) {
            if (! is_array($color) || array_keys($color) !== ['id', 'value'] || ! is_string($color['id']) || ! is_string($color['value'])) {
                continue;
            }
            $value = $this->normalizeValue($color['value']);
            if (! $this->isCanonicalId($color['id']) || $value === null || isset($ids[$color['id']]) || isset($values[$value])) {
                continue;
            }
            $normalized[] = ['id' => $color['id'], 'value' => $value];
            $ids[$color['id']] = true;
            $values[$value] = true;
            if (count($normalized) === self::MAXIMUM) {
                break;
            }
        }

        return $normalized;
    }

    public function isCanonicalId(string $id): bool
    {
        return preg_match('/^project-color-[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $id) === 1;
    }
}
