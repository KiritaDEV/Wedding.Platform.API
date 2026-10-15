<?php

namespace App\Website\Elements;

use App\Website\WebsiteAnimation;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class Swatches
{
    public static function validJson(mixed $element): bool
    {
        if (! $element instanceof \stdClass || ! is_array($element->items ?? null)) {
            return false;
        }
        foreach ($element->items as $item) {
            if (! $item instanceof \stdClass) {
                return false;
            }
        }
        if (property_exists($element, 'appearance')) {
            if (! $element->appearance instanceof \stdClass) {
                return false;
            }
            foreach (['captionTypography', 'outerSpacing', 'animation'] as $field) {
                if (property_exists($element->appearance, $field) && ! $element->appearance->{$field} instanceof \stdClass) {
                    return false;
                }
            }
            if (isset($element->appearance->animation) && property_exists($element->appearance->animation, 'entrance') && ! $element->appearance->animation->entrance instanceof \stdClass) {
                return false;
            }
        }

        return true;
    }

    public static function validate(array $element, Closure $validateTypography): array
    {
        foreach (['isHidden'] as $field) {
            if (array_key_exists($field, $element) && ! is_bool($element[$field])) {
                self::invalid($field);
            }
        }
        $appearance = $element['appearance'] ?? [];
        if (array_key_exists('appearance', $element) && (! is_array($element['appearance']) || ($appearance !== [] && array_is_list($appearance)))) {
            self::invalid('appearance');
        }
        foreach ($appearance as $field => $value) {
            $valid = match ($field) {
                'showLabels', 'showHex' => is_bool($value),
                'captionTypography', 'outerSpacing', 'animation' => is_array($value) && ($value === [] || ! array_is_list($value)),
                default => is_string($value),
            };
            if (! $valid) {
                self::invalid('appearance.'.$field);
            }
        }
        $validated = Validator::make(['element' => $element], [
            'element' => ['required', 'array:id,type,editorName,isHidden,items,appearance'],
            'element.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'element.type' => ['required', 'in:swatches'],
            'element.editorName' => ['required', 'string', 'max:80', 'not_regex:/^\s*$/u'],
            'element.items' => ['required', 'array', 'list', 'min:1', 'max:12'],
            'element.items.*' => ['required', 'array:id,color,label'],
            'element.items.*.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'element.items.*.color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/D'],
            'element.items.*.label' => ['sometimes', 'string', 'regex:/^[^<>]*$/u'],
            'element.isHidden' => ['sometimes', 'boolean'],
            'element.appearance' => ['sometimes', 'array:shape,size,cornerRadius,surfaceStyle,gap,alignment,showLabels,showHex,captionStyle,captionTextColor,captionBackgroundColor,captionTypography,outerSpacing,animation'],
            'element.appearance.shape' => ['sometimes', 'filled', 'string', 'in:circle,roundedSquare,square,rectangle'],
            'element.appearance.size' => ['sometimes', 'filled', 'string', 'in:small,medium,large,xl,2xl,3xl'],
            'element.appearance.cornerRadius' => ['sometimes', 'filled', 'string', 'in:none,small,medium,large,full'],
            'element.appearance.surfaceStyle' => ['sometimes', 'filled', 'string', 'in:flat,raised,inset'],
            'element.appearance.gap' => ['sometimes', 'filled', 'string', 'in:none,xs,s,m,l,xl'],
            'element.appearance.alignment' => ['sometimes', 'filled', 'string', 'in:start,center,end'],
            'element.appearance.showLabels' => ['sometimes', 'boolean'],
            'element.appearance.showHex' => ['sometimes', 'boolean'],
            'element.appearance.captionStyle' => ['sometimes', 'filled', 'string', 'in:plain,soft'],
            'element.appearance.captionTextColor' => ['sometimes', 'filled', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/D'],
            'element.appearance.captionBackgroundColor' => ['sometimes', 'filled', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/D'],
            'element.appearance.captionTypography' => ['sometimes', 'array:fontFamilyId,fontSize,fontWeight,lineHeight,letterSpacing'],
        ])->validate()['element'];
        foreach (['captionTextColor', 'captionBackgroundColor'] as $field) {
            if (isset($validated['appearance'][$field])) {
                $validated['appearance'][$field] = strtoupper($validated['appearance'][$field]);
            }
        }
        $validated['id'] = trim($validated['id']);
        $seen = [$validated['id'] => true];
        foreach ($validated['items'] as $index => &$item) {
            $item['id'] = trim($item['id']);
            if (isset($seen[$item['id']])) {
                self::invalid("items.{$index}.id");
            }
            $seen[$item['id']] = true;
            $item['color'] = strtoupper($item['color']);
            if (isset($item['label'])) {
                $item['label'] = preg_replace('/^\s+|\s+$/u', '', $item['label']);
                if (mb_strlen($item['label']) > 80) {
                    self::invalid("items.{$index}.label");
                }
                if ($item['label'] === '') {
                    unset($item['label']);
                }
            }
        }
        unset($item);
        if (isset($appearance['captionTypography'])) {
            $validateTypography($appearance['captionTypography']);
            if (isset($appearance['captionTypography']['fontSize']) && ! in_array($appearance['captionTypography']['fontSize'], ['xs', 's', 'm'], true)) {
                self::invalid('appearance.captionTypography.fontSize');
            }
        }
        $spacing = FourSidedSpacing::extractOuter($validated);
        $animation = WebsiteAnimation::extract($validated);
        if ($spacing !== []) {
            $validated['appearance'] = FourSidedSpacing::restoreOuter($validated['appearance'] ?? [], $spacing);
        }
        WebsiteAnimation::restore($validated, $animation);

        return $validated;
    }

    private static function invalid(string $field): never
    {
        throw ValidationException::withMessages(['element.'.$field => 'Use the supported Swatches value.']);
    }
}
