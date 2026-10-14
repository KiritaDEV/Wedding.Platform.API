<?php

namespace App\Website\Elements;

use Illuminate\Validation\ValidationException;

final class GoogleMaps
{
    public static function validUrl(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 8192
            || ! preg_match('~^https://www\.google\.com/maps/embed\?[^#\s<>"\\\\]+$~D', $value)
            || preg_match('/%(?![0-9a-f]{2})/i', $value) || preg_match('/[^\x21-\x7e]/', $value) || ! mb_check_encoding(rawurldecode($value), 'UTF-8')) {
            return false;
        }
        $url = parse_url($value);
        if ($url === false || ($url['scheme'] ?? null) !== 'https' || ($url['host'] ?? null) !== 'www.google.com'
            || ($url['path'] ?? null) !== '/maps/embed' || isset($url['port']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            return false;
        }
        $seen = [];
        foreach (explode('&', $url['query'] ?? '') as $pair) {
            $parts = explode('=', $pair, 2);
            $key = urldecode($parts[0]);
            $entry = urldecode($parts[1] ?? '');
            if (isset($seen[$key]) || ! in_array($key, ['pb', 'hl'], true)) {
                return false;
            }
            $seen[$key] = true;
            if ($key === 'pb' && (! preg_match('/^!1m[1-9][0-9]*(?:![1-9][0-9]*[a-z][^!]*)+$/D', $entry) || preg_match('/[<>\x00-\x1f\x7f]/', $entry))) {
                return false;
            }
            if ($key === 'hl' && ! preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2,4})?$/D', $entry)) {
                return false;
            }
        }

        return isset($seen['pb']);
    }

    public static function validJsonAppearance(mixed $value): bool
    {
        if (! $value instanceof \stdClass) {
            return false;
        }
        foreach (['outerSpacing', 'responsive', 'tablet', 'mobile', 'animation'] as $key) {
            if (property_exists($value, $key) && ! self::validJsonAppearance($value->{$key})) {
                return false;
            }
        }

        return true;
    }

    public static function assertElement(array $element): void
    {
        if (array_key_exists('embedUrl', $element) && ! self::validUrl($element['embedUrl'])) {
            throw ValidationException::withMessages(['element.embedUrl' => 'Use a secure Google Maps Share embed URL.']);
        }
        if (array_key_exists('isHidden', $element) && ! is_bool($element['isHidden'])) {
            throw ValidationException::withMessages(['element.isHidden' => 'Visibility must be a JSON boolean.']);
        }
        if (array_key_exists('appearance', $element) && (! is_array($element['appearance']) || ($element['appearance'] !== [] && array_is_list($element['appearance'])))) {
            throw ValidationException::withMessages(['element.appearance' => 'Map appearance must be a JSON object.']);
        }
        foreach (['shadow', 'width', 'height', 'alignment', 'cornerRadius'] as $key) {
            if (array_key_exists($key, $element['appearance'] ?? []) && ! is_string($element['appearance'][$key])) {
                throw ValidationException::withMessages(['element.appearance.'.$key => 'Use a supported preset.']);
            }
        }
    }
}
