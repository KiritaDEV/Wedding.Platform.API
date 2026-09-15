<?php

namespace App\Website\Capabilities;

use App\Website\Elements\WebsiteElementType;
use App\Website\ProjectColorLibrary;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateDefinition;
use App\Website\WebsiteTemplateRegistry;

final class WebsiteCapabilityResolver
{
    /** @var array<string, TemplateCapabilities> */
    private array $resolvedTemplates = [];

    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionRegistry $sections,
    ) {}

    public function template(string|WebsiteTemplateDefinition $template): ?TemplateCapabilities
    {
        if (is_string($template) && isset($this->resolvedTemplates[$template])) {
            return $this->resolvedTemplates[$template];
        }

        $requestedKey = is_string($template) ? $template : null;
        $definition = is_string($template) ? $this->templates->get($template) : $template;
        if ($definition === null) {
            return null;
        }

        $sections = [];
        foreach ($definition->supportedSectionTypes as $sectionId) {
            if ($this->sections->get($sectionId) !== null) {
                $sections[] = $this->sectionFromDefinition($definition, $sectionId);
            }
        }

        $elements = collect($sections)->flatMap(fn (SectionCapability $section): array => $section->allowedElementTypes ?? [])
            ->unique()->values()->all();

        $capabilities = new TemplateCapabilities(
            templateKey: $definition->key,
            globalDesign: $this->globalDesignFromDefinition($definition),
            designLibrary: $definition->designLibrary,
            projectDefaults: $this->projectDefaultsFromDefinition($definition),
            projectColorLibrary: new ProjectColorLibraryCapability(true, ProjectColorLibrary::MAXIMUM, ProjectColorLibrary::FORMAT),
            elements: $elements,
            elementCapabilities: $this->elementCapabilitiesFromDefinition($definition),
            sections: $sections,
        );
        if ($requestedKey !== null) {
            $this->resolvedTemplates[$requestedKey] = $capabilities;
        }

        return $capabilities;
    }

    public function section(string|WebsiteTemplateDefinition $template, string $sectionId): ?SectionCapability
    {
        $capabilities = $this->template($template);

        return collect($capabilities?->sections)->first(fn (SectionCapability $section): bool => $section->id === $sectionId);
    }

    public function globalDesign(string|WebsiteTemplateDefinition $template): ?GlobalDesignCapability
    {
        return $this->template($template)?->globalDesign;
    }

    public function projectDefaults(string|WebsiteTemplateDefinition $template): ?ProjectDefaultsCapability
    {
        return $this->template($template)?->projectDefaults;
    }

    public function blockContextDefaults(string|WebsiteTemplateDefinition $template): ?ContextDefaultsCapability
    {
        $definition = is_string($template) ? $this->templates->get($template) : $template;

        return $definition === null ? null : $this->contextDefaultsFromDefinition($definition, [
            'headingFont', 'bodyFont', 'headingColor', 'bodyColor', 'accentColor',
        ]);
    }

    /** @param array<string, mixed> $legacySettings */
    public function resolveProjectDesignDefaults(
        string|WebsiteTemplateDefinition $template,
        array $legacySettings,
    ): ?ResolvedProjectDesignDefaults {
        $definition = is_string($template) ? $this->templates->get($template) : $template;
        if ($definition === null) {
            return null;
        }

        $normalized = $definition->normalizeDesignSettings($legacySettings);
        $overrides = $this->normalizeProjectDefaultOverrides($definition, $legacySettings['projectDefaults'] ?? null);
        $palette = collect($definition->designLibrary->palettePresets)->firstWhere('id', $normalized['colorTheme']);
        $typography = collect($definition->designLibrary->typographyPresets)->firstWhere('id', $normalized['fontSet']);
        if ($palette === null || $typography === null) {
            return null;
        }

        return new ResolvedProjectDesignDefaults(
            headingFontId: $overrides['headingFontId'] ?? $typography->headingFontId,
            bodyFontId: $overrides['bodyFontId'] ?? $typography->bodyFontId,
            headingColorId: $overrides['headingColorId'] ?? $palette->roles[DesignColorRole::Text->value],
            bodyColorId: $overrides['bodyColorId'] ?? $palette->roles[DesignColorRole::Text->value],
            accentColorId: $overrides['accentColorId'] ?? $palette->roles[DesignColorRole::Accent->value],
        );
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string, projectDefaults: array<string, string>, customColors: list<array{id: string, value: string}>}|null */
    public function normalizeDesignSettings(string|WebsiteTemplateDefinition $template, mixed $settings): ?array
    {
        $definition = is_string($template) ? $this->templates->get($template) : $template;
        if ($definition === null) {
            return null;
        }

        $stored = is_array($settings) ? $settings : [];

        return [
            ...$definition->normalizeDesignSettings($stored),
            'projectDefaults' => $this->normalizeProjectDefaultOverrides($definition, $stored['projectDefaults'] ?? null),
            'customColors' => (new ProjectColorLibrary)->normalize($stored['customColors'] ?? []),
        ];
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string, projectDefaults: array<string, string>}|null */
    public function canonicalDesignDefaults(string|WebsiteTemplateDefinition $template): ?array
    {
        return $this->normalizeDesignSettings($template, []);
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string, projectDefaults: object}|null */
    public function designSettingsForStorage(string|WebsiteTemplateDefinition $template, mixed $settings): ?array
    {
        $normalized = $this->normalizeDesignSettings($template, $settings);
        if ($normalized === null) {
            return null;
        }

        return [...$normalized, 'projectDefaults' => (object) $normalized['projectDefaults']];
    }

    /** @return array<string, string> */
    public function normalizeProjectDefaultOverrides(string|WebsiteTemplateDefinition $template, mixed $overrides): array
    {
        $capability = $this->projectDefaults($template);
        if ($capability === null || ! is_array($overrides)) {
            return [];
        }

        $allowed = [
            'headingFontId' => $capability->typography->headingFontIds,
            'bodyFontId' => $capability->typography->bodyFontIds,
            'headingColorId' => $capability->colors->headingColorIds,
            'bodyColorId' => $capability->colors->bodyColorIds,
            'accentColorId' => $capability->colors->accentColorIds,
        ];

        return array_filter(
            array_intersect_key($overrides, $allowed),
            fn (mixed $value, string $key): bool => is_string($value) && in_array($value, $allowed[$key], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function globalDesignControl(
        string|WebsiteTemplateDefinition $template,
        string|GlobalDesignControlId $controlId,
    ): ?GlobalDesignControlCapability {
        $id = is_string($controlId) ? GlobalDesignControlId::tryFrom($controlId) : $controlId;
        if ($id === null) {
            return null;
        }

        return collect($this->globalDesign($template)?->controls)
            ->first(fn (GlobalDesignControlCapability $control): bool => $control->id === $id);
    }

    public function allowsGlobalDesignValue(
        string|WebsiteTemplateDefinition $template,
        string|GlobalDesignControlId $controlId,
        string $value,
    ): bool {
        $control = $this->globalDesignControl($template, $controlId);

        return $control !== null && in_array($value, array_column($control->options, 'key'), true);
    }

    public function globalDesignDefault(
        string|WebsiteTemplateDefinition $template,
        string|GlobalDesignControlId $controlId,
    ): ?string {
        return $this->globalDesignControl($template, $controlId)?->default;
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string}|null */
    public function globalDesignDefaults(string|WebsiteTemplateDefinition $template): ?array
    {
        $capability = $this->globalDesign($template);
        if ($capability === null) {
            return null;
        }

        return collect($capability->controls)->mapWithKeys(
            fn (GlobalDesignControlCapability $control): array => [$control->id->value => $control->default],
        )->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{colorTheme: string, fontSet: string, artStyle: string}|null
     */
    public function normalizeGlobalDesignSettings(
        string|WebsiteTemplateDefinition $template,
        array $settings,
    ): ?array {
        $definition = is_string($template) ? $this->templates->get($template) : $template;

        return $definition?->normalizeDesignSettings($settings);
    }

    public function presentation(string|WebsiteTemplateDefinition $template, string $sectionId, ?string $presentationId = null): ?PresentationCapability
    {
        $section = $this->section($template, $sectionId);
        $presentationId ??= $section?->defaultPresentation;

        return collect($section?->presentations)->first(fn (PresentationCapability $presentation): bool => $presentation->id === $presentationId);
    }

    /** @return list<AppearanceControlCapability>|null */
    public function controlsForViewport(string|WebsiteTemplateDefinition $template, string $sectionId, ?string $presentationId, string $viewport): ?array
    {
        $section = $this->section($template, $sectionId);
        if ($section === null || ! in_array($viewport, ['desktop', ...WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS], true)) {
            return null;
        }

        $presentation = $this->presentation($template, $sectionId, $presentationId);
        if ($presentationId !== null && $presentation === null) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (AppearanceControlCapability $control): ?AppearanceControlCapability => $viewport === 'desktop' ? $control : $control->forViewport($viewport),
            [...$section->appearanceControls, ...($presentation?->appearanceControls ?? [])],
        )));
    }

    public function allowsElement(string|WebsiteTemplateDefinition $template, string $sectionId, string $elementType): bool
    {
        if (WebsiteElementType::tryFrom($elementType) === null) {
            return false;
        }

        return in_array($elementType, $this->section($template, $sectionId)?->allowedElementTypes ?? [], true);
    }

    private function globalDesignFromDefinition(WebsiteTemplateDefinition $template): GlobalDesignCapability
    {
        $controls = [];
        foreach ([
            GlobalDesignControlId::ColorTheme->value => ['colorThemes', GlobalDesignControlType::PalettePreset],
            GlobalDesignControlId::FontSet->value => ['fontSets', GlobalDesignControlType::TypographyPairing],
            GlobalDesignControlId::ArtStyle->value => ['artStyles', GlobalDesignControlType::ArtStyle],
        ] as $id => [$group, $type]) {
            $controls[] = new GlobalDesignControlCapability(
                id: GlobalDesignControlId::from($id),
                type: $type,
                default: $template->defaultDesignSettings[$id],
                options: $template->designOptions[$group],
            );
        }

        return new GlobalDesignCapability($controls);
    }

    private function projectDefaultsFromDefinition(WebsiteTemplateDefinition $template): ProjectDefaultsCapability
    {
        $library = $template->designLibrary;

        return new ProjectDefaultsCapability(
            typography: new ProjectTypographyDefaultsCapability(
                headingFontIds: array_values(array_map(
                    fn (FontFamilyCapability $family): string => $family->id,
                    array_filter($library->fontFamilies, fn (FontFamilyCapability $family): bool => in_array(TypographyRole::Heading, $family->allowedRoles, true)),
                )),
                bodyFontIds: array_values(array_map(
                    fn (FontFamilyCapability $family): string => $family->id,
                    array_filter($library->fontFamilies, fn (FontFamilyCapability $family): bool => in_array(TypographyRole::Body, $family->allowedRoles, true)),
                )),
            ),
            colors: new ProjectColorDefaultsCapability(
                headingColorIds: $this->colorIdsForProjectRole($library, ProjectColorRole::Heading),
                bodyColorIds: $this->colorIdsForProjectRole($library, ProjectColorRole::Body),
                accentColorIds: $this->colorIdsForProjectRole($library, ProjectColorRole::Accent),
            ),
        );
    }

    /** @return list<ElementCapability> */
    private function elementCapabilitiesFromDefinition(WebsiteTemplateDefinition $template): array
    {
        $headingTypography = new ElementTypographyCapability(
            TypographyRole::Heading,
            $this->fontIdsForRole($template, TypographyRole::Heading),
        );
        $bodyTypography = new ElementTypographyCapability(
            TypographyRole::Body,
            $this->fontIdsForRole($template, TypographyRole::Body),
        );
        $headingColor = new ElementColorCapability(
            ElementColorRole::HeadingColor,
            $this->colorIdsForElementRole($template, ElementColorRole::HeadingColor),
        );
        $textColor = new ElementColorCapability(
            ElementColorRole::TextColor,
            $this->colorIdsForElementRole($template, ElementColorRole::TextColor),
        );

        return array_map(function (WebsiteElementType $type) use ($headingTypography, $bodyTypography, $headingColor, $textColor): ElementCapability {
            $appearance = match ($type) {
                WebsiteElementType::Heading => new ElementAppearanceCapability([$headingTypography], [$headingColor]),
                WebsiteElementType::Text, WebsiteElementType::Quote => new ElementAppearanceCapability([$bodyTypography], [$textColor]),
                WebsiteElementType::Media => new ElementAppearanceCapability([], []),
                default => null,
            };

            return new ElementCapability($type, $appearance);
        }, WebsiteElementType::cases());
    }

    /** @return list<string> */
    private function fontIdsForRole(WebsiteTemplateDefinition $template, TypographyRole $role): array
    {
        return array_values(array_map(
            fn (FontFamilyCapability $family): string => $family->id,
            array_filter(
                $template->designLibrary->fontFamilies,
                fn (FontFamilyCapability $family): bool => in_array($role, $family->allowedRoles, true),
            ),
        ));
    }

    /** @return list<string> */
    private function colorIdsForElementRole(WebsiteTemplateDefinition $template, ElementColorRole $role): array
    {
        return array_values(array_map(
            fn (DesignColorCapability $color): string => $color->id,
            array_filter(
                $template->designLibrary->colors,
                fn (DesignColorCapability $color): bool => in_array($role, $color->allowedElementRoles, true),
            ),
        ));
    }

    /** @return list<string> */
    private function colorIdsForContainerRole(WebsiteTemplateDefinition $template, ContainerColorRole $role): array
    {
        return array_values(array_map(
            fn (DesignColorCapability $color): string => $color->id,
            array_filter(
                $template->designLibrary->colors,
                fn (DesignColorCapability $color): bool => in_array($role, $color->allowedContainerRoles, true),
            ),
        ));
    }

    private function contextDefaultsForSection(
        WebsiteTemplateDefinition $template,
        string $sectionId,
        bool $includeColors = true,
    ): ContextDefaultsCapability {
        $roles = match ($sectionId) {
            'gallery' => ['headingFont', 'headingColor'],
            default => ['headingFont', 'bodyFont', 'headingColor', 'bodyColor', 'accentColor'],
        };
        if (! $includeColors) {
            $roles = array_values(array_filter($roles, fn (string $role): bool => str_ends_with($role, 'Font')));
        }

        return $this->contextDefaultsFromDefinition($template, $roles);
    }

    /** @param list<string> $roles */
    private function contextDefaultsFromDefinition(WebsiteTemplateDefinition $template, array $roles): ContextDefaultsCapability
    {
        $typography = [];
        if (in_array('headingFont', $roles, true)) {
            $typography[] = new ContextTypographyCapability(TypographyRole::Heading, $this->fontIdsForRole($template, TypographyRole::Heading));
        }
        if (in_array('bodyFont', $roles, true)) {
            $typography[] = new ContextTypographyCapability(TypographyRole::Body, $this->fontIdsForRole($template, TypographyRole::Body));
        }

        $colors = [];
        foreach ([
            'headingColor' => ContainerColorRole::HeadingColor,
            'bodyColor' => ContainerColorRole::BodyColor,
            'accentColor' => ContainerColorRole::AccentColor,
        ] as $name => $role) {
            if (in_array($name, $roles, true)) {
                $colors[] = new ContextColorCapability($role, $this->colorIdsForContainerRole($template, $role));
            }
        }

        return new ContextDefaultsCapability($typography, $colors);
    }

    /** @return list<string> */
    private function colorIdsForProjectRole(TemplateDesignLibrary $library, ProjectColorRole $role): array
    {
        return array_values(array_map(
            fn (DesignColorCapability $color): string => $color->id,
            array_filter($library->colors, fn (DesignColorCapability $color): bool => in_array($role, $color->allowedProjectRoles, true)),
        ));
    }

    private function sectionFromDefinition(WebsiteTemplateDefinition $template, string $sectionId): SectionCapability
    {
        $appearanceOptions = $template->appearanceOptionsFor($sectionId) ?? [];
        $appearanceDefaults = $template->appearanceDefaultsFor($sectionId) ?? [];
        $controls = [];
        foreach ([
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ] as $id => $group) {
            if (in_array($sectionId, ['blank', 'hero'], true)) {
                continue;
            }
            if (isset($appearanceOptions[$group], $appearanceDefaults[$id])) {
                $options = $appearanceOptions[$group];
                if ($sectionId === 'blank' && $id === 'backgroundTreatment') {
                    $options = [
                        ['key' => 'inherit', 'displayName' => 'Use Template'],
                        ['key' => 'custom', 'displayName' => 'Custom'],
                    ];
                }
                $controls[] = $this->optionControl(
                    $id,
                    in_array($id, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true) ? AppearanceControlScope::Responsive : AppearanceControlScope::Shared,
                    $appearanceDefaults[$id],
                    $options,
                    $template,
                    $sectionId,
                );
            }
        }

        $presentationDefinition = $template->presentationCapabilityFor($sectionId);
        $presentations = [];
        if ($presentationDefinition !== null) {
            $controls[] = $this->optionControl(
                'presentation',
                AppearanceControlScope::Shared,
                $presentationDefinition['default'],
                array_map(fn (array $option): array => [
                    'key' => $option['key'],
                    'displayName' => $option['displayName'],
                ], $presentationDefinition['options']),
            );
            foreach ($presentationDefinition['options'] as $option) {
                $presentationControls = $this->presentationControls($template, $sectionId, $option['key']);
                $ownsForeground = collect($presentationControls)->contains(
                    fn (AppearanceControlCapability $control): bool => $control->id === 'foregroundColor',
                );
                $presentations[] = new PresentationCapability(
                    id: $option['key'],
                    displayName: $option['displayName'],
                    description: $option['description'],
                    preview: $option['preview'],
                    appearanceControls: $presentationControls,
                    contextDefaults: $ownsForeground
                        ? $this->contextDefaultsForSection($template, $sectionId, includeColors: false)
                        : null,
                );
            }
        }

        $allowedElements = match ($sectionId) {
            'blank', 'hero' => [WebsiteElementType::Text->value, WebsiteElementType::Date->value, WebsiteElementType::Accordion->value, WebsiteElementType::Schedule->value, WebsiteElementType::People->value, WebsiteElementType::Divider->value, WebsiteElementType::Media->value, WebsiteElementType::CompositionGroup->value],
            default => null,
        };

        return new SectionCapability(
            id: $sectionId,
            appearanceControls: $controls,
            defaultPresentation: $presentationDefinition['default'] ?? null,
            presentations: $presentations,
            contextDefaults: in_array($sectionId, ['blank', 'hero'], true) ? new ContextDefaultsCapability([], []) : $this->contextDefaultsForSection($template, $sectionId),
            allowedElementTypes: $allowedElements,
            maximumElementCount: $allowedElements === null ? null : 20,
            decorativeAppearance: in_array($sectionId, ['hero', 'gallery', 'rsvp', 'blank'], true) ? $this->sectionDecorativeAppearance($template) : null,
        );
    }

    private function sectionDecorativeAppearance(WebsiteTemplateDefinition $template): SectionDecorativeAppearanceCapability
    {
        $backgroundColorIds = collect($template->designLibrary->colors)
            ->filter(fn ($color): bool => in_array(ContainerColorRole::BackgroundColor, $color->allowedContainerRoles, true))
            ->pluck('id')
            ->values()
            ->all();
        $frameColorIds = collect($template->designLibrary->colors)
            ->filter(fn ($color): bool => in_array(ContainerColorRole::FrameColor, $color->allowedContainerRoles, true))
            ->pluck('id')
            ->values()
            ->all();

        return $template->key === 'classic-filipiniana-v1'
            ? new SectionDecorativeAppearanceCapability(
                textures: ['none', 'paper', 'fabric', 'grain'],
                patterns: ['none', 'botanical', 'heritage'],
                overlays: ['none', 'soft', 'warm', 'deep'],
                frames: ['none', 'fine', 'ornamental'],
                backgroundColorIds: $backgroundColorIds,
                frameColorIds: $frameColorIds,
            )
            : new SectionDecorativeAppearanceCapability(
                textures: ['none', 'paper', 'grain'],
                patterns: ['none', 'geometric', 'botanical'],
                overlays: ['none', 'soft', 'deep'],
                frames: ['none', 'fine'],
                backgroundColorIds: $backgroundColorIds,
                frameColorIds: $frameColorIds,
            );
    }

    /** @return list<AppearanceControlCapability> */
    private function presentationControls(WebsiteTemplateDefinition $template, string $sectionId, string $presentationId): array
    {
        $mediaControls = $template->mediaControlsFor($sectionId, $presentationId);
        $controls = [];
        foreach ($template->mediaControlSettings() as $id => $group) {
            if (! isset($mediaControls[$group])) {
                continue;
            }
            $controls[] = $this->optionControl(
                $id,
                in_array($id, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true) ? AppearanceControlScope::Responsive : AppearanceControlScope::Shared,
                $mediaControls[$group]['default'],
                $mediaControls[$group]['options'],
                $template,
                $sectionId,
                $presentationId,
            );
        }
        if (isset($mediaControls['mediaSpacing'])) {
            $controls[] = new AppearanceControlCapability(
                id: 'mediaSpacing',
                type: AppearanceControlType::Spacing,
                scope: AppearanceControlScope::Responsive,
                default: $mediaControls['mediaSpacing']['default'],
                options: $mediaControls['mediaSpacing']['options'],
                viewports: $this->viewportCapabilities($template, $sectionId, $presentationId, 'mediaSpacing', $mediaControls['mediaSpacing']['options']),
            );
        }
        if (isset($mediaControls['overlayStrength'])) {
            $control = $mediaControls['overlayStrength'];
            $controls[] = new AppearanceControlCapability(
                id: 'overlayStrength',
                type: AppearanceControlType::Number,
                scope: AppearanceControlScope::Shared,
                default: (float) $control['default'],
                minimum: (float) $control['min'],
                maximum: (float) $control['max'],
                step: (float) $control['step'],
            );
        }

        return $controls;
    }

    /** @param list<array{key: string, displayName: string}> $options */
    private function optionControl(
        string $id,
        AppearanceControlScope $scope,
        string $default,
        array $options,
        ?WebsiteTemplateDefinition $template = null,
        ?string $sectionId = null,
        ?string $presentationId = null,
    ): AppearanceControlCapability {
        return new AppearanceControlCapability(
            id: $id,
            type: AppearanceControlType::Option,
            scope: $scope,
            default: $default,
            options: $options,
            viewports: $scope === AppearanceControlScope::Responsive && $template !== null && $sectionId !== null
                ? $this->viewportCapabilities($template, $sectionId, $presentationId, $id, $options)
                : [],
        );
    }

    /**
     * @param  list<array{key: string, displayName: string}>  $fallbackOptions
     * @return array<string, ViewportControlCapability>
     */
    private function viewportCapabilities(
        WebsiteTemplateDefinition $template,
        string $sectionId,
        ?string $presentationId,
        string $setting,
        array $fallbackOptions,
    ): array {
        $viewports = [];
        foreach (WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS as $viewport) {
            $control = $template->resolvedResponsiveControlFor($sectionId, $presentationId, $viewport, $setting, $fallbackOptions);
            if ($control !== null) {
                $viewports[$viewport] = new ViewportControlCapability(
                    default: $control['default'],
                    options: $control['options'],
                );
            }
        }

        return $viewports;
    }
}
