<?php

namespace App\Website;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\DividerCatalog;
use App\Website\Elements\SectionChildFlowValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteSectionContentValidator
{
    public function __construct(
        private readonly SectionChildFlowValidator $childFlows,
        private readonly CompositionGroupValidator $groups,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validate(string $sectionType, array $content, ?array $allowedElementTypes = null, ?array $allowedFontIds = null, ?array $allowedColorIds = null, ?string $templateKey = null): array
    {
        $this->assertNoAuthoredResponsiveState($content);
        if (($content['compositions']['custom'] ?? null) === []) {
            unset($content['compositions']['custom']);
        }
        if ($sectionType === 'blank' && ($content['semantic'] ?? null) !== []) {
            throw ValidationException::withMessages(['content.semantic' => ucfirst($sectionType).' semantic data must be an empty object.']);
        }
        $rules = $this->rulesFor($sectionType);

        if ($rules === null) {
            throw ValidationException::withMessages([
                'content' => "Section type [{$sectionType}] is not editable.",
            ]);
        }

        $validated = Validator::make(['content' => $content], $rules)->validate()['content'];
        if ($sectionType === 'rsvp') {
            $this->assertRsvpRuntimeResources($validated['semantic']['runtimeAppearance'] ?? [], $allowedFontIds, $allowedColorIds);
        }
        if ($sectionType === 'gallery') {
            $ids = [];
            foreach ($validated['semantic']['items'] as $index => $item) {
                $id = trim($item['id']);
                if (isset($ids[$id])) {
                    throw ValidationException::withMessages(["content.semantic.items.{$index}.id" => 'Gallery item IDs must be unique.']);
                }
                $ids[$id] = true;
                $validated['semantic']['items'][$index]['id'] = $id;
            }
        }
        if (in_array($sectionType, ['blank', 'hero', 'gallery', 'rsvp'], true)) {
            if (($validated['compositions']['custom'] ?? null) === []) {
                unset($validated['compositions']['custom']);
            }
            $branches = ['shared' => $validated['compositions']['shared']];
            foreach ($validated['compositions']['custom'] ?? [] as $viewport => $composition) {
                $branches[$viewport] = $composition;
            }
            $allElements = [];
            foreach ($branches as $branch => $composition) {
                $gallery = $sectionType === 'gallery';
                $rsvp = $sectionType === 'rsvp';
                $defaults = $gallery ? ['text', 'divider', 'compositionGroup'] : ($rsvp ? ['text', 'divider', 'media', 'compositionGroup'] : ['text', 'date', 'accordion', 'schedule', 'people', 'divider', 'media', 'compositionGroup']);
                $flow = $this->childFlows->validate($composition['childFlow'], $allowedElementTypes ?? $defaults, $sectionType !== 'blank' && $sectionType !== 'hero');
                if ($branch === 'shared') {
                    $validated['compositions']['shared']['childFlow'] = $flow;
                } else {
                    $validated['compositions']['custom'][$branch]['childFlow'] = $flow;
                }
                array_push($allElements, ...$flow['elements']);
            }
            $this->groups->assertUniqueTreeIds($allElements);
            if ($sectionType === 'gallery') {
                $assertGalleryType = function (array $element) use (&$assertGalleryType): void {
                    if (! in_array($element['type'] ?? null, ['text', 'divider', 'compositionGroup'], true)) {
                        throw ValidationException::withMessages(['content.compositions' => 'Gallery supports only Text, Divider, and Group content.']);
                    }
                    foreach ($element['children'] ?? [] as $child) {
                        $assertGalleryType($child);
                    }
                };
                foreach ($allElements as $element) {
                    $assertGalleryType($element);
                }
            }
            if ($sectionType === 'rsvp') {
                $assertRsvpType = function (array $element) use (&$assertRsvpType): void {
                    if (! in_array($element['type'] ?? null, ['text', 'divider', 'media', 'compositionGroup'], true)) {
                        throw ValidationException::withMessages(['content.compositions' => 'RSVP supports only Text, Divider, Media, and Group content.']);
                    }
                    foreach ($element['children'] ?? [] as $child) {
                        $assertRsvpType($child);
                    }
                };
                foreach ($allElements as $element) {
                    $assertRsvpType($element);
                }
            }
            $textElements = [];
            $collectText = function (array $element, string $path) use (&$collectText, &$textElements): void {
                if (in_array(($element['type'] ?? null), ['text', 'date', 'divider'], true)) {
                    $textElements[] = [$element, $path];
                }
                if (($element['type'] ?? null) === 'compositionGroup') {
                    foreach ($element['children'] as $index => $child) {
                        $collectText($child, "{$path}.children.{$index}");
                    }
                }
            };
            foreach ($allElements as $index => $element) {
                $collectText($element, "{$index}");
            }
            foreach ($textElements as [$element, $path]) {
                if (! in_array(($element['type'] ?? null), ['text', 'date', 'divider'], true)) {
                    continue;
                }
                if ($element['type'] === 'divider' && isset($element['appearance']['assetId']) && $templateKey !== null
                    && ! in_array($element['appearance']['assetId'], DividerCatalog::assetIdsForTemplate($templateKey), true)) {
                    throw ValidationException::withMessages(["content.childFlow.elements.{$path}.appearance.assetId" => 'The selected Divider asset is not supported by this Template.']);
                }
                $fontId = in_array($element['type'], ['text', 'date'], true) ? ($element['appearance']['fontFamilyId'] ?? null) : null;
                if (is_string($fontId) && $allowedFontIds !== null && ! in_array($fontId, $allowedFontIds, true)) {
                    throw ValidationException::withMessages(["content.childFlow.elements.{$path}.appearance.fontFamilyId" => 'The selected Text font is not supported by this Template.']);
                }
                foreach (['colorId', 'textShadowColorId', 'shadowColorId', 'glowColorId'] as $appearanceColorField) {
                    $colorId = $element['appearance'][$appearanceColorField] ?? null;
                    if (is_string($colorId) && $allowedColorIds !== null && ! in_array($colorId, $allowedColorIds, true)) {
                        throw ValidationException::withMessages(["content.childFlow.elements.{$path}.appearance.{$appearanceColorField}" => 'The selected Text color is not supported by this Website.']);
                    }
                }
                if ($element['type'] === 'text') {
                    foreach ($element['document']['children'] as $blockIndex => $block) {
                        foreach ($block['children'] as $runIndex => $run) {
                            $inlineColorId = $run['colorId'] ?? null;
                            if (is_string($inlineColorId) && $allowedColorIds !== null && ! in_array($inlineColorId, $allowedColorIds, true)) {
                                throw ValidationException::withMessages(["content.childFlow.elements.{$path}.document.children.{$blockIndex}.children.{$runIndex}.colorId" => 'The selected inline Text color is not supported by this Website.']);
                            }
                        }
                    }
                }
            }
        }
        array_walk_recursive($validated, function (mixed &$value, string|int $key): void {
            if ($value === null && $key !== 'media' && $key !== 'role' && $key !== 'assetId') {
                $value = '';
            }
        });

        return $validated;
    }

    private function assertNoAuthoredResponsiveState(array $content): void
    {
        $visit = function (mixed $value, string $path) use (&$visit): void {
            if (! is_array($value)) {
                return;
            }
            if (array_key_exists('responsive', $value)) {
                throw ValidationException::withMessages(["{$path}.responsive" => 'Device-specific authored properties require a custom Section composition.']);
            }
            foreach ($value as $key => $child) {
                $visit($child, $path.'.'.$key);
            }
        };
        $visit($content['compositions'] ?? [], 'content.compositions');
    }

    /** @return array<string, list<string>>|null */
    private function rulesFor(string $sectionType): ?array
    {
        return match ($sectionType) {
            'hero' => $this->heroRules(),
            'blank' => $this->compositionEnvelopeRules(),
            'rsvp' => [...$this->compositionEnvelopeRules(), ...$this->rsvpRuntimeAppearanceRules()],
            'gallery' => [...$this->compositionEnvelopeRules(),
                'content.semantic' => ['required', 'array:items'],
                'content.semantic.items' => ['present', 'array', 'list', 'max:24'],
                'content.semantic.items.*' => ['required', 'array:id,type,mediaId,focalPoint,zoom'],
                'content.semantic.items.*.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                'content.semantic.items.*.type' => ['required', 'in:image'],
                'content.semantic.items.*.mediaId' => ['required', 'string', 'ulid'],
                'content.semantic.items.*.focalPoint' => ['sometimes', 'array:x,y'],
                'content.semantic.items.*.focalPoint.x' => ['required_with:content.semantic.items.*.focalPoint', 'numeric', 'between:0,1'],
                'content.semantic.items.*.focalPoint.y' => ['required_with:content.semantic.items.*.focalPoint', 'numeric', 'between:0,1'],
                'content.semantic.items.*.zoom' => ['sometimes', 'numeric', 'between:1,3'],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $fields
     * @return array<string, list<string>>
     */
    private function stringContentRules(array $fields): array
    {
        $rules = [
            'content' => ['required', 'array:semantic'],
            'content.semantic' => ['required', 'array:'.implode(',', array_keys($fields))],
        ];

        foreach ($fields as $field => $maximum) {
            $rules["content.semantic.{$field}"] = ['present', 'nullable', 'string', "max:{$maximum}"];
        }

        return $rules;
    }

    /** @param array<string, list<string>> $rules @return array<string, list<string>> */
    private function childFlowRules(array $rules): array
    {
        $rules['content'][1] .= ',childFlow';
        $rules['content.childFlow'] = ['sometimes', 'array'];

        return $rules;
    }

    /** @param array<string, list<string>> $rules */
    private function singleMediaRules(array $rules): array
    {
        $rules['content'][1] .= ',media';
        $rules['content.media'] = ['sometimes', 'nullable', 'array:assetId,focalPoint,zoom'];
        $rules['content.media.assetId'] = ['required_with:content.media', 'string', 'ulid'];
        $rules['content.media.focalPoint'] = ['sometimes', 'array:x,y'];
        $rules['content.media.focalPoint.x'] = ['required_with:content.media.focalPoint', 'numeric', 'between:0,1'];
        $rules['content.media.focalPoint.y'] = ['required_with:content.media.focalPoint', 'numeric', 'between:0,1'];
        $rules['content.media.zoom'] = ['sometimes', 'numeric', 'between:1,3'];

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function heroRules(): array
    {
        return [...$this->compositionEnvelopeRules(),
            'content.semantic' => ['present', 'array']];
    }

    /** @return array<string, list<string>> */
    private function compositionEnvelopeRules(): array
    {
        return [
            'content' => ['required', 'array:semantic,compositions'],
            'content.semantic' => ['present', 'array'],
            'content.compositions' => ['required', 'array:shared,custom'],
            'content.compositions.shared' => ['required', 'array:childFlow'],
            'content.compositions.shared.childFlow' => ['required', 'array'],
            'content.compositions.custom' => ['sometimes', 'array:desktop,tablet,mobile'],
            'content.compositions.custom.*' => ['required', 'array:childFlow'],
            'content.compositions.custom.*.childFlow' => ['required', 'array'],
        ];
    }

    /** @return array<string, list<string>> */
    private function rsvpRuntimeAppearanceRules(): array
    {
        $rules = [
            'content.semantic' => ['present', 'array:runtimeAppearance'],
            'content.semantic.runtimeAppearance' => ['sometimes', 'array:status,guestName,responseLabel,supporting,choice,action'],
        ];
        foreach (['status', 'guestName', 'responseLabel', 'supporting'] as $role) {
            $rules = [...$rules, ...$this->runtimeTextRules("content.semantic.runtimeAppearance.{$role}")];
        }
        $rules = [...$rules, ...$this->runtimeTextRules('content.semantic.runtimeAppearance.action.typography')];
        $rules += [
            'content.semantic.runtimeAppearance.choice' => ['sometimes', 'array:layout,direction,size,radius,borderWidth,gap,unselected,selected,disabled,responsive'],
            'content.semantic.runtimeAppearance.choice.layout' => ['sometimes', 'in:cards,segmented'],
            'content.semantic.runtimeAppearance.choice.direction' => ['sometimes', 'in:row,column'],
            'content.semantic.runtimeAppearance.choice.size' => ['sometimes', 'in:compact,normal,large'],
            'content.semantic.runtimeAppearance.choice.radius' => ['sometimes', 'in:square,soft,rounded,pill'],
            'content.semantic.runtimeAppearance.choice.borderWidth' => ['sometimes', 'in:none,thin,medium,thick'],
            'content.semantic.runtimeAppearance.choice.gap' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'content.semantic.runtimeAppearance.choice.unselected' => ['sometimes', 'array:textColorId,backgroundColorId,borderColorId'],
            'content.semantic.runtimeAppearance.choice.selected' => ['sometimes', 'array:textColorId,backgroundColorId,borderColorId,emphasis'],
            'content.semantic.runtimeAppearance.choice.selected.emphasis' => ['sometimes', 'in:normal,semibold,bold'],
            'content.semantic.runtimeAppearance.choice.disabled' => ['sometimes', 'array:opacity'],
            'content.semantic.runtimeAppearance.choice.disabled.opacity' => ['sometimes', 'in:soft,muted'],
            'content.semantic.runtimeAppearance.choice.responsive' => ['sometimes', 'array:tablet,mobile'],
            'content.semantic.runtimeAppearance.choice.responsive.*' => ['sometimes', 'array:direction,size'],
            'content.semantic.runtimeAppearance.choice.responsive.*.direction' => ['sometimes', 'in:row,column'],
            'content.semantic.runtimeAppearance.choice.responsive.*.size' => ['sometimes', 'in:compact,normal,large'],
            'content.semantic.runtimeAppearance.action' => ['sometimes', 'array:typography,textColorId,backgroundColorId,borderColorId,borderWidth,radius,size,paddingX,paddingY,width,alignment,variant,responsive'],
            'content.semantic.runtimeAppearance.action.borderWidth' => ['sometimes', 'in:none,thin,medium,thick'],
            'content.semantic.runtimeAppearance.action.radius' => ['sometimes', 'in:square,soft,rounded,pill'],
            'content.semantic.runtimeAppearance.action.size' => ['sometimes', 'in:compact,normal,large'],
            'content.semantic.runtimeAppearance.action.paddingX' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'content.semantic.runtimeAppearance.action.paddingY' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'content.semantic.runtimeAppearance.action.width' => ['sometimes', 'in:intrinsic,full'],
            'content.semantic.runtimeAppearance.action.alignment' => ['sometimes', 'in:start,center,end'],
            'content.semantic.runtimeAppearance.action.variant' => ['sometimes', 'in:filled,outline,minimal'],
            'content.semantic.runtimeAppearance.action.responsive' => ['sometimes', 'array:tablet,mobile'],
            'content.semantic.runtimeAppearance.action.responsive.*' => ['sometimes', 'array:size,width,alignment'],
            'content.semantic.runtimeAppearance.action.responsive.*.size' => ['sometimes', 'in:compact,normal,large'],
            'content.semantic.runtimeAppearance.action.responsive.*.width' => ['sometimes', 'in:intrinsic,full'],
            'content.semantic.runtimeAppearance.action.responsive.*.alignment' => ['sometimes', 'in:start,center,end'],
        ];
        foreach (['unselected', 'selected'] as $state) {
            foreach (['textColorId', 'backgroundColorId', 'borderColorId'] as $field) {
                $rules["content.semantic.runtimeAppearance.choice.{$state}.{$field}"] = ['sometimes', 'string', 'min:1'];
            }
        }
        foreach (['textColorId', 'backgroundColorId', 'borderColorId'] as $field) {
            $rules["content.semantic.runtimeAppearance.action.{$field}"] = ['sometimes', 'string', 'min:1'];
        }

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function runtimeTextRules(string $path): array
    {
        return [
            $path => ['sometimes', 'array:fontFamilyId,fontSize,fontWeight,lineHeight,letterSpacing,alignment,colorId,textShadow,textShadowColorId,glow,glowColorId,italic,underline,strikethrough,textTransform,responsive'],
            "{$path}.fontFamilyId" => ['sometimes', 'string', 'min:1'],
            "{$path}.fontSize" => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            "{$path}.fontWeight" => ['sometimes', 'integer', 'in:400,600,700'],
            "{$path}.lineHeight" => ['sometimes', 'in:tight,normal,relaxed'],
            "{$path}.letterSpacing" => ['sometimes', 'in:tight,normal,wide'],
            "{$path}.alignment" => ['sometimes', 'in:start,center,end'],
            "{$path}.colorId" => ['sometimes', 'string', 'min:1'],
            "{$path}.textShadow" => ['sometimes', 'in:none,soft,medium,strong'],
            "{$path}.textShadowColorId" => ['sometimes', 'string', 'min:1'],
            "{$path}.glow" => ['sometimes', 'in:none,soft,medium,strong'],
            "{$path}.glowColorId" => ['sometimes', 'string', 'min:1'],
            "{$path}.italic" => ['sometimes', 'boolean'],
            "{$path}.underline" => ['sometimes', 'boolean'],
            "{$path}.strikethrough" => ['sometimes', 'boolean'],
            "{$path}.textTransform" => ['sometimes', 'in:none,uppercase,lowercase,capitalize'],
            "{$path}.responsive" => ['sometimes', 'array:tablet,mobile'],
            "{$path}.responsive.*" => ['sometimes', 'array:fontSize,alignment'],
            "{$path}.responsive.*.fontSize" => ['sometimes', 'in:xs,s,m,l,xl,2xl,3xl,4xl,5xl,6xl,7xl'],
            "{$path}.responsive.*.alignment" => ['sometimes', 'in:start,center,end'],
        ];
    }

    /** @param array<string, mixed> $appearance */
    private function assertRsvpRuntimeResources(array $appearance, ?array $allowedFontIds, ?array $allowedColorIds): void
    {
        $textRoles = ['status', 'guestName', 'responseLabel', 'supporting'];
        foreach ($textRoles as $role) {
            $this->assertRuntimeTextResources($appearance[$role] ?? [], "content.semantic.runtimeAppearance.{$role}", $allowedFontIds, $allowedColorIds);
        }
        $this->assertRuntimeTextResources($appearance['action']['typography'] ?? [], 'content.semantic.runtimeAppearance.action.typography', $allowedFontIds, $allowedColorIds);
        foreach (['choice.unselected', 'choice.selected', 'action'] as $owner) {
            $value = $owner === 'action' ? ($appearance['action'] ?? []) : ($appearance['choice'][explode('.', $owner)[1]] ?? []);
            foreach (['textColorId', 'backgroundColorId', 'borderColorId'] as $field) {
                $colorId = $value[$field] ?? null;
                if (is_string($colorId) && $allowedColorIds !== null && ! in_array($colorId, $allowedColorIds, true)) {
                    throw ValidationException::withMessages(["content.semantic.runtimeAppearance.{$owner}.{$field}" => 'The selected RSVP color is not supported by this Website.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $appearance */
    private function assertRuntimeTextResources(array $appearance, string $path, ?array $allowedFontIds, ?array $allowedColorIds): void
    {
        $fontId = $appearance['fontFamilyId'] ?? null;
        if (is_string($fontId) && $allowedFontIds !== null && ! in_array($fontId, $allowedFontIds, true)) {
            throw ValidationException::withMessages(["{$path}.fontFamilyId" => 'The selected RSVP font is not supported by this Template.']);
        }
        foreach (['colorId', 'textShadowColorId', 'glowColorId'] as $field) {
            $colorId = $appearance[$field] ?? null;
            if (is_string($colorId) && $allowedColorIds !== null && ! in_array($colorId, $allowedColorIds, true)) {
                throw ValidationException::withMessages(["{$path}.{$field}" => 'The selected RSVP color is not supported by this Website.']);
            }
        }
    }
}
