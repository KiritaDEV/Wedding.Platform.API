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
            throw ValidationException::withMessages(['content.semantic' => 'Blank semantic data must be an empty object.']);
        }
        $rules = $this->rulesFor($sectionType);

        if ($rules === null) {
            throw ValidationException::withMessages([
                'content' => "Section type [{$sectionType}] is not editable.",
            ]);
        }

        $validated = Validator::make(['content' => $content], $rules)->validate()['content'];
        if (in_array($sectionType, ['blank', 'hero'], true)) {
            if (($validated['compositions']['custom'] ?? null) === []) {
                unset($validated['compositions']['custom']);
            }
            $branches = ['shared' => $validated['compositions']['shared']];
            foreach ($validated['compositions']['custom'] ?? [] as $viewport => $composition) {
                $branches[$viewport] = $composition;
            }
            $allElements = [];
            foreach ($branches as $branch => $composition) {
                $flow = $this->childFlows->validate($composition['childFlow'], $allowedElementTypes ?? ['text', 'date', 'accordion', 'schedule', 'people', 'divider', 'media', 'compositionGroup'], false);
                if ($branch === 'shared') {
                    $validated['compositions']['shared']['childFlow'] = $flow;
                } else {
                    $validated['compositions']['custom'][$branch]['childFlow'] = $flow;
                }
                array_push($allElements, ...$flow['elements']);
            }
            $this->groups->assertUniqueTreeIds($allElements);
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
            'rsvp' => $this->stringContentRules([
                'heading' => 255,
                'description' => 5000,
                'buttonLabel' => 100,
            ]),
            'gallery' => [
                'content' => ['required', 'array:semantic'],
                'content.semantic' => ['required', 'array:heading,items'],
                'content.semantic.heading' => ['present', 'nullable', 'string', 'max:255'],
                'content.semantic.items' => ['present', 'array', 'size:0'],
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
}
