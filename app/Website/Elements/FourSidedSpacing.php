<?php

namespace App\Website\Elements;

use Illuminate\Validation\ValidationException;

final class FourSidedSpacing
{
    /** @param array<string,mixed> $element @return array<string,array<string,string>> */
    public static function extractOuter(array &$element): array
    {
        $result = [];
        $touched = false;
        foreach (['base', 'tablet', 'mobile'] as $viewport) {
            $value = $viewport === 'base' ? $element['appearance']['outerSpacing'] ?? null : $element['appearance']['responsive'][$viewport]['outerSpacing'] ?? null;
            if ($value === null) {
                continue;
            }
            $touched = true;
            if (! is_array($value) || array_diff(array_keys($value), ['top', 'right', 'bottom', 'left']) !== []) {
                throw ValidationException::withMessages(['element.appearance.outerSpacing' => 'Outer spacing must use supported sides.']);
            }
            foreach ($value as $side => $preset) {
                if (! is_string($preset) || ! in_array($preset, ['none', 'xs', 's', 'm', 'l', 'xl'], true)) {
                    throw ValidationException::withMessages(["element.appearance.outerSpacing.{$side}" => 'The selected outer spacing is invalid.']);
                }
                if ($preset !== 'none' && ($viewport === 'base' || $preset !== ($result['base'][$side] ?? 'none'))) {
                    $result[$viewport][$side] = $preset;
                }
            }
            if ($viewport === 'base') {
                unset($element['appearance']['outerSpacing']);
            } else {
                unset($element['appearance']['responsive'][$viewport]['outerSpacing']);
            }
            if (($element['appearance']['responsive'][$viewport] ?? null) === []) {
                unset($element['appearance']['responsive'][$viewport]);
            }
        }
        if ($touched && ($element['appearance']['responsive'] ?? null) === []) {
            unset($element['appearance']['responsive']);
        }
        if ($touched && ($element['appearance'] ?? null) === []) {
            unset($element['appearance']);
        }

        return array_filter($result);
    }

    /** @param array<string,mixed> $appearance @param array<string,array<string,string>> $spacing @return array<string,mixed> */
    public static function restoreOuter(array $appearance, array $spacing): array
    {
        if (isset($spacing['base'])) {
            $appearance['outerSpacing'] = $spacing['base'];
        }
        foreach (['tablet', 'mobile'] as $viewport) {
            if (isset($spacing[$viewport])) {
                $appearance['responsive'][$viewport]['outerSpacing'] = $spacing[$viewport];
            }
        }

        return $appearance;
    }
}
