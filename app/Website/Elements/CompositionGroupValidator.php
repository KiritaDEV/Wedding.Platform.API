<?php

namespace App\Website\Elements;

use App\Website\BackgroundMedia;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CompositionGroupValidator
{
    /** @param array<string,mixed> $group @param Closure(array<string,mixed>):array<string,mixed> $validateChild @return array<string,mixed> */
    public function validate(array $group, Closure $validateChild): array
    {
        $outerSpacing = FourSidedSpacing::extractOuter($group);
        $opacity = $group['appearance']['backgroundImageOpacity'] ?? null;
        if ($opacity !== null && ! is_int($opacity)) {
            throw ValidationException::withMessages(['element.appearance.backgroundImageOpacity' => 'Group background image opacity must be a JSON integer.']);
        }
        BackgroundMedia::assertJsonNumbers($group['backgroundMedia'] ?? null, 'element.backgroundMedia');
        foreach (['textureStrength', 'patternStrength'] as $strength) {
            $value = $group['appearance']['decorativeAppearance']['background'][$strength] ?? null;
            if ($value !== null && ! is_int($value)) {
                throw ValidationException::withMessages(["element.appearance.decorativeAppearance.background.{$strength}" => 'Group decorative strength must be an integer.']);
            }
        }
        $validated = Validator::make(['element' => $group], [
            'element' => ['required', 'array:id,type,editorName,children,layout,appearance,backgroundMedia,isHidden'], 'element.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'], 'element.type' => ['required', 'in:compositionGroup'], 'element.editorName' => ['required', 'string', 'max:80', 'not_regex:/^\s*$/u'], 'element.isHidden' => ['sometimes', 'boolean'],
            'element.children' => ['present', 'array', 'list', 'max:20'], 'element.children.*' => ['required', 'array'],
            'element.layout' => ['sometimes', 'array:width,direction,gap,padding,alignment,division,contentPosition,responsive'], 'element.layout.width' => ['sometimes', 'in:full,wide,medium,narrow'], 'element.layout.direction' => ['sometimes', 'in:vertical,horizontal'], 'element.layout.gap' => ['sometimes', 'in:none,xs,s,m,l,xl'], 'element.layout.alignment' => ['sometimes', 'in:start,center,end,stretch'], 'element.layout.division' => ['sometimes', 'in:50-50,60-40,40-60,thirds'], 'element.layout.contentPosition' => ['sometimes', 'in:top-start,top-center,top-end,center-start,center,center-end,bottom-start,bottom-center,bottom-end'],
            'element.layout.padding' => ['sometimes', 'array:top,right,bottom,left'], 'element.layout.padding.*' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'element.layout.responsive' => ['sometimes', 'array:tablet,mobile'], 'element.layout.responsive.*' => ['sometimes', 'array:width,direction,gap,padding,alignment,division,contentPosition'], 'element.layout.responsive.*.width' => ['sometimes', 'in:full,wide,medium,narrow'], 'element.layout.responsive.*.direction' => ['sometimes', 'in:vertical,horizontal'], 'element.layout.responsive.*.gap' => ['sometimes', 'in:none,xs,s,m,l,xl'], 'element.layout.responsive.*.alignment' => ['sometimes', 'in:start,center,end,stretch'], 'element.layout.responsive.*.division' => ['sometimes', 'in:50-50,60-40,40-60,thirds'], 'element.layout.responsive.*.contentPosition' => ['sometimes', 'in:top-start,top-center,top-end,center-start,center,center-end,bottom-start,bottom-center,bottom-end'], 'element.layout.responsive.*.padding' => ['sometimes', 'array:top,right,bottom,left'], 'element.layout.responsive.*.padding.*' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'element.appearance' => ['sometimes', 'array:backgroundColorId,backgroundImageOpacity,shadow,decorativeAppearance'], 'element.appearance.backgroundColorId' => ['sometimes', 'string', 'max:255'], 'element.appearance.backgroundImageOpacity' => ['sometimes', 'integer', 'between:0,100'], 'element.appearance.shadow' => ['sometimes', 'in:none,soft,medium,strong'],
            'element.appearance.decorativeAppearance' => ['sometimes', 'array:background'], 'element.appearance.decorativeAppearance.background' => ['sometimes', 'array:texture,textureStrength,pattern,patternStrength'],
            'element.appearance.decorativeAppearance.background.texture' => ['sometimes', 'in:none,paper,fabric,grain'], 'element.appearance.decorativeAppearance.background.textureStrength' => ['sometimes', 'integer', 'between:10,100'],
            'element.appearance.decorativeAppearance.background.pattern' => ['sometimes', 'in:none,botanical,geometric,heritage'], 'element.appearance.decorativeAppearance.background.patternStrength' => ['sometimes', 'integer', 'between:10,100'],
            ...BackgroundMedia::rules('element.backgroundMedia'),
        ])->validate()['element'];
        $validated['id'] = trim($validated['id']);
        if ($outerSpacing !== []) {
            $validated['appearance'] = FourSidedSpacing::restoreOuter($validated['appearance'] ?? [], $outerSpacing);
        }
        if (($validated['appearance']['backgroundImageOpacity'] ?? null) === 100) {
            unset($validated['appearance']['backgroundImageOpacity']);
        }
        if (is_array($validated['backgroundMedia'] ?? null)) {
            $validated['backgroundMedia'] = BackgroundMedia::normalize($validated['backgroundMedia']);
        }
        if (($validated['layout']['contentPosition'] ?? null) === 'center') {
            unset($validated['layout']['contentPosition']);
        }
        foreach (['tablet', 'mobile'] as $viewport) {
            if (($validated['layout']['responsive'][$viewport]['contentPosition'] ?? null) === ($validated['layout']['contentPosition'] ?? 'center')) {
                unset($validated['layout']['responsive'][$viewport]['contentPosition']);
            }
            if (($validated['layout']['responsive'][$viewport] ?? null) === []) {
                unset($validated['layout']['responsive'][$viewport]);
            }
        }
        if (($validated['layout']['responsive'] ?? null) === []) {
            unset($validated['layout']['responsive']);
        }
        $validated['children'] = array_map(function (array $child) use ($validateChild): array {
            if (! in_array(($child['type'] ?? null), ['text', 'date', 'accordion', 'schedule', 'people', 'divider', 'media', 'compositionGroup'], true)) {
                throw ValidationException::withMessages(['element.children' => 'Groups support Text, Date, Divider, Media, and one nested Group level.']);
            }

            return $validateChild($child);
        }, $validated['children']);
        $this->assertUniqueTreeIds([$validated]);

        return $validated;
    }

    /** @param list<array<string,mixed>> $elements */
    public function assertUniqueTreeIds(array $elements): void
    {
        $seen = [];
        $visit = function (array $element) use (&$seen, &$visit): void {
            $id = $element['id'];
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['elements' => "Element IDs must be unique within a section; duplicate [{$id}] found."]);
            }
            $seen[$id] = true;
            if (($element['type'] ?? null) === 'media') {
                foreach ($element['items'] as $item) {
                    $visit($item);
                }
            }
            if (($element['type'] ?? null) === 'people') {
                foreach ($element['groups'] as $group) {
                    $visit(['id' => $group['id'], 'type' => 'peopleGroup']);
                    foreach ($group['people'] as $person) {
                        $visit(['id' => $person['id'], 'type' => 'person']);
                    }
                }
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                foreach ($element['children'] as $child) {
                    $visit($child);
                }
            }
        };
        foreach ($elements as $element) {
            $visit($element);
        }
    }
}
