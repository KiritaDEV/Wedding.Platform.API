<?php

namespace App\Http\Resources;

use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\ContextDefaultsCapability;
use App\Website\Capabilities\ElementCapability;
use App\Website\Capabilities\GlobalDesignControlCapability;
use App\Website\Capabilities\PresentationCapability;
use App\Website\Capabilities\SectionCapability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteTemplateCapabilitiesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'globalDesign' => [
                'controls' => array_map(fn (GlobalDesignControlCapability $control): array => [
                    'id' => $control->id->value,
                    'type' => $control->type->value,
                    'default' => $control->default,
                    'options' => $control->options,
                ], $this->globalDesign->controls),
            ],
            'designLibrary' => [
                'colors' => array_map(fn ($color): array => [
                    'id' => $color->id,
                    'displayName' => $color->displayName,
                    'value' => $color->value,
                    'origin' => 'template',
                    'allowedProjectRoles' => array_map(fn ($role): string => $role->value, $color->allowedProjectRoles),
                    'allowedElementRoles' => array_map(fn ($role): string => $role->value, $color->allowedElementRoles),
                    'allowedContainerRoles' => array_map(fn ($role): string => $role->value, $color->allowedContainerRoles),
                ], $this->designLibrary->colors),
                'fontFamilies' => array_map(fn ($family): array => [
                    'id' => $family->id,
                    'displayName' => $family->displayName,
                    'allowedRoles' => array_map(fn ($role): string => $role->value, $family->allowedRoles),
                    'family' => $family->family,
                    'category' => $family->category,
                    'source' => $family->source,
                    'fallback' => $family->fallback,
                    'weights' => $family->weights,
                    'styles' => $family->styles,
                    'recommendedRoles' => $family->recommendedRoles,
                    'license' => $family->license,
                ], $this->designLibrary->fontFamilies),
                'fontRecommendations' => $this->designLibrary->fontRecommendations,
                'palettePresets' => array_map(fn ($preset): array => [
                    'id' => $preset->id,
                    'displayName' => $preset->displayName,
                    'roles' => $preset->roles,
                ], $this->designLibrary->palettePresets),
                'typographyPresets' => array_map(fn ($preset): array => [
                    'id' => $preset->id,
                    'displayName' => $preset->displayName,
                    'headingFontId' => $preset->headingFontId,
                    'bodyFontId' => $preset->bodyFontId,
                ], $this->designLibrary->typographyPresets),
            ],
            'projectDefaults' => [
                'typography' => [
                    'headingFont' => ['allowedFontIds' => $this->projectDefaults->typography->headingFontIds],
                    'bodyFont' => ['allowedFontIds' => $this->projectDefaults->typography->bodyFontIds],
                ],
                'colors' => [
                    'headingColor' => ['allowedColorIds' => $this->projectDefaults->colors->headingColorIds],
                    'bodyColor' => ['allowedColorIds' => $this->projectDefaults->colors->bodyColorIds],
                    'accentColor' => ['allowedColorIds' => $this->projectDefaults->colors->accentColorIds],
                ],
            ],
            'projectColorLibrary' => [
                'enabled' => $this->projectColorLibrary->enabled,
                'maximum' => $this->projectColorLibrary->maximum,
                'format' => $this->projectColorLibrary->format,
            ],
            'elements' => $this->elements,
            'elementCapabilities' => array_map(fn (ElementCapability $element): array => [
                'type' => $element->type->value,
                'appearance' => $element->appearance === null ? null : [
                    'typography' => array_map(fn ($control): array => [
                        'role' => $control->role->value,
                        'allowedFontIds' => $control->allowedFontIds,
                        'scope' => $control->scope->value,
                    ], $element->appearance->typography),
                    'colors' => array_map(fn ($control): array => [
                        'role' => $control->role->value,
                        'allowedColorIds' => $control->allowedColorIds,
                        'scope' => $control->scope->value,
                    ], $element->appearance->colors),
                ],
            ], $this->elementCapabilities),
            'sections' => array_map(fn (SectionCapability $section): array => [
                'id' => $section->id,
                'appearanceControls' => array_map($this->serializeControl(...), $section->appearanceControls),
                'contextDefaults' => $this->serializeContextDefaults($section->contextDefaults),
                'defaultPresentation' => $section->defaultPresentation,
                'presentations' => array_map(fn (PresentationCapability $presentation): array => [
                    'id' => $presentation->id,
                    'displayName' => $presentation->displayName,
                    'description' => $presentation->description,
                    'preview' => $presentation->preview,
                    'appearanceControls' => array_map($this->serializeControl(...), $presentation->appearanceControls),
                    'contextDefaults' => $presentation->contextDefaults === null
                        ? null
                        : $this->serializeContextDefaults($presentation->contextDefaults),
                ], $section->presentations),
                'elements' => $section->allowedElementTypes === null ? null : [
                    'allowedTypes' => $section->allowedElementTypes,
                    'maxCount' => $section->maximumElementCount,
                    'compositionGroups' => $section->compositionGroups,
                ],
                'decorativeAppearance' => $section->decorativeAppearance === null ? null : [
                    'textures' => $section->decorativeAppearance->textures,
                    'patterns' => $section->decorativeAppearance->patterns,
                    'overlays' => $section->decorativeAppearance->overlays,
                    'frames' => $section->decorativeAppearance->frames,
                    'backgroundColorIds' => $section->decorativeAppearance->backgroundColorIds,
                    'frameColorIds' => $section->decorativeAppearance->frameColorIds,
                ],
            ], $this->sections),
        ];
    }

    private function serializeContextDefaults(ContextDefaultsCapability $capability): array
    {
        return [
            'typography' => array_map(fn ($control): array => [
                'role' => $control->role->value,
                'allowedFontIds' => $control->allowedFontIds,
                'scope' => $control->scope->value,
            ], $capability->typography),
            'colors' => array_map(fn ($control): array => [
                'role' => $control->role->value,
                'allowedColorIds' => $control->allowedColorIds,
                'scope' => $control->scope->value,
            ], $capability->colors),
        ];
    }

    private function serializeControl(AppearanceControlCapability $control): array
    {
        $serialized = [
            'id' => $control->id,
            'type' => $control->type->value,
            'scope' => $control->scope->value,
            'default' => $control->default,
        ];

        if ($control->type !== AppearanceControlType::Number) {
            $serialized['options'] = $control->options;
        } else {
            $serialized['minimum'] = $control->minimum;
            $serialized['maximum'] = $control->maximum;
            $serialized['step'] = $control->step;
        }

        if ($control->viewports !== []) {
            $serialized['viewports'] = array_map(fn ($viewport): array => [
                'default' => $viewport->default,
                'options' => $viewport->options,
            ], $control->viewports);
        }

        return $serialized;
    }
}
