<?php

namespace App\Website\Elements;

final class DividerJsonShape
{
    /** Preserve empty Divider objects when encoding PHP's associative JSON data.
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function serializeElements(array $elements): array
    {
        return array_map(function (array $element): array {
            if (($element['type'] ?? null) === 'divider' && ($element['appearance'] ?? null) === []) {
                $element['appearance'] = new \stdClass;
            }
            if (($element['type'] ?? null) === 'media') {
                foreach (['presentation', 'appearance'] as $field) {
                    if (($element[$field] ?? null) === []) {
                        $element[$field] = new \stdClass;
                    }
                }
                if (is_array($element['presentation'] ?? null)) {
                    foreach (['carousel', 'responsive'] as $field) {
                        if (($element['presentation'][$field] ?? null) === []) {
                            $element['presentation'][$field] = new \stdClass;
                        }
                    }
                    if (is_array($element['presentation']['responsive'] ?? null)) {
                        foreach (['tablet', 'mobile'] as $viewport) {
                            if (($element['presentation']['responsive'][$viewport] ?? null) === []) {
                                $element['presentation']['responsive'][$viewport] = new \stdClass;
                            }
                        }
                    }
                }
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                $element['children'] = self::serializeElements($element['children']);
            }

            return $element;
        }, $elements);
    }
}
