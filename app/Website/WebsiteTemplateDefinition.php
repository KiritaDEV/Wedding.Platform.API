<?php

namespace App\Website;

use App\Enums\EventType;
use App\Website\Capabilities\TemplateDesignLibrary;

final readonly class WebsiteTemplateDefinition
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     * @param  list<string>  $supportedSectionTypes
     * @param  array{colorThemes: list<array{key: string, displayName: string}>, fontSets: list<array{key: string, displayName: string}>, artStyles: list<array{key: string, displayName: string}>}  $designOptions
     * @param  array{colorTheme: string, fontSet: string, artStyle: string}  $defaultDesignSettings
     * @param  array<string, array<string, list<array{key: string, displayName: string}>>>  $sectionAppearanceOptions
     * @param  array<string, array{headingAlignment: string, bodyAlignment: string, backgroundTreatment: string, emphasis: string}>  $sectionAppearanceDefaults
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public string $description,
        public array $styleTags,
        public bool $enabled,
        public array $supportedEventTypes,
        public array $supportedSectionTypes,
        public array $designOptions,
        public array $defaultDesignSettings,
        public TemplateDesignLibrary $designLibrary,
        public array $sectionAppearanceOptions,
        public array $sectionAppearanceDefaults,
        public array $sectionMediaCapabilities = [],
        public array $sectionItemMediaCapabilities = [],
        public array $sectionPresentationCapabilities = [],
        public array $sectionPresentationFallbacks = [],
    ) {}

    /** @param array<string, mixed> $settings */
    public function normalizeDesignSettings(array $settings): array
    {
        $groups = ['colorTheme' => 'colorThemes', 'fontSet' => 'fontSets', 'artStyle' => 'artStyles'];
        $normalized = [];

        foreach ($groups as $setting => $group) {
            $allowed = array_column($this->designOptions[$group] ?? [], 'key');
            $current = $settings[$setting] ?? null;
            $normalized[$setting] = is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $this->defaultDesignSettings[$setting];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $appearance */
    public function normalizeSectionAppearance(string $sectionType, array $appearance): array
    {
        $groups = [
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ];
        $options = $this->appearanceOptionsFor($sectionType) ?? [];
        $normalized = [];

        foreach ($groups as $setting => $group) {
            $allowed = array_column($options[$group] ?? [], 'key');
            $current = $appearance[$setting] ?? null;
            $fallback = $this->sectionAppearanceDefaults[$sectionType][$setting] ?? null;
            $normalized[$setting] = is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $fallback;
        }

        $presentation = $this->presentationCapabilityFor($sectionType);
        if ($presentation !== null) {
            $allowed = array_column($presentation['options'], 'key');
            $current = $appearance['presentation'] ?? null;
            $legacyFallback = is_string($current) ? $this->presentationFallbackFor($sectionType, $current) : null;
            $normalized['presentation'] = $legacyFallback['presentation'] ?? (is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $presentation['default']);
            $controls = $this->mediaControlsFor($sectionType, $normalized['presentation']);
            foreach ($this->mediaControlSettings() as $setting => $group) {
                if (! isset($controls[$group])) {
                    continue;
                }
                $currentValue = $appearance[$setting] ?? $legacyFallback[$setting] ?? null;
                $allowedValues = array_column($controls[$group]['options'], 'key');
                $normalized[$setting] = is_string($currentValue) && in_array($currentValue, $allowedValues, true)
                    ? $currentValue
                    : $controls[$group]['default'];
            }
            if (isset($controls['mediaSpacing'])) {
                $allowedValues = array_column($controls['mediaSpacing']['options'], 'key');
                $current = $appearance['mediaSpacing'] ?? null;
                $normalized['mediaSpacing'] = is_array($current)
                    ? array_map(
                        fn (string $side): string => is_string($current[$side] ?? null) && in_array($current[$side], $allowedValues, true)
                            ? $current[$side]
                            : $controls['mediaSpacing']['default'][$side],
                        ['top', 'right', 'bottom', 'left'],
                    )
                    : $controls['mediaSpacing']['default'];
                $normalized['mediaSpacing'] = array_combine(['top', 'right', 'bottom', 'left'], $normalized['mediaSpacing']);
            }
            if (isset($controls['overlayStrength'])) {
                $value = $appearance['overlayStrength'] ?? null;
                $normalized['overlayStrength'] = is_numeric($value) && $value >= $controls['overlayStrength']['min'] && $value <= $controls['overlayStrength']['max']
                    ? (float) $value
                    : $controls['overlayStrength']['default'];
            }
        }

        $responsive = $appearance['responsive'] ?? null;
        if (is_array($responsive)) {
            $normalizedResponsive = [];
            foreach (WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS as $viewport) {
                if (! is_array($responsive[$viewport] ?? null)) {
                    continue;
                }
                $override = $this->normalizeResponsiveOverride($sectionType, $normalized, $viewport, $responsive[$viewport]);
                if ($override !== []) {
                    $normalizedResponsive[$viewport] = $override;
                }
            }
            if ($normalizedResponsive !== []) {
                $normalized['responsive'] = $normalizedResponsive;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $appearance */
    public function resolveSectionAppearanceForViewport(string $sectionType, array $appearance, string $viewport): array
    {
        $normalized = $this->normalizeSectionAppearance($sectionType, $appearance);
        $responsive = $normalized['responsive'] ?? [];
        unset($normalized['responsive']);

        if ($viewport === 'desktop') {
            return $normalized;
        }

        if (! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true)) {
            return $normalized;
        }

        $presentation = $normalized['presentation'] ?? null;
        foreach (WebsiteSectionAppearance::RESPONSIVE_SETTINGS as $setting) {
            unset($normalized[$setting]);
            $default = $this->responsiveDefaultFor($sectionType, $presentation, $viewport, $setting);
            if ($default !== null) {
                $normalized[$setting] = $default;
            }
        }

        return [...$normalized, ...($responsive[$viewport] ?? [])];
    }

    public function responsiveDefaultFor(string $sectionType, ?string $presentation, string $viewport, string $setting): mixed
    {
        if (! in_array($setting, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true)) {
            return null;
        }

        $viewportControl = $this->responsiveControlFor($sectionType, $presentation, $viewport, $setting);
        if (array_key_exists('default', $viewportControl ?? [])) {
            return $viewportControl['default'];
        }

        if ($setting === 'headingAlignment' || $setting === 'bodyAlignment') {
            return $this->sectionAppearanceDefaults[$sectionType][$setting] ?? null;
        }

        if ($presentation === null) {
            return null;
        }

        $controls = $this->mediaControlsFor($sectionType, $presentation);
        if ($setting === 'mediaSpacing') {
            return $controls['mediaSpacing']['default'] ?? null;
        }

        $group = $this->mediaControlSettings()[$setting] ?? null;

        return $group === null ? null : ($controls[$group]['default'] ?? null);
    }

    /**
     * @param  list<array{key: string, displayName: string}>  $fallbackOptions
     * @return array{default: mixed, options: list<array{key: string, displayName: string}>}|null
     */
    public function resolvedResponsiveControlFor(
        string $sectionType,
        ?string $presentation,
        string $viewport,
        string $setting,
        array $fallbackOptions,
    ): ?array {
        $default = $this->responsiveDefaultFor($sectionType, $presentation, $viewport, $setting);
        if ($default === null) {
            return null;
        }

        $control = $this->responsiveControlFor($sectionType, $presentation, $viewport, $setting);

        return [
            'default' => $default,
            'options' => $control['options'] ?? $fallbackOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function normalizeResponsiveOverride(string $sectionType, array $base, string $viewport, array $override): array
    {
        $normalized = [];
        $options = $this->appearanceOptionsFor($sectionType) ?? [];
        $controls = isset($base['presentation']) ? $this->mediaControlsFor($sectionType, $base['presentation']) : [];
        $groups = [
            'headingAlignment' => $options['headingAlignments'] ?? [],
            'bodyAlignment' => $options['bodyAlignments'] ?? [],
            'mediaPlacement' => $controls['mediaPlacements']['options'] ?? [],
            'mediaSize' => $controls['mediaSizes']['options'] ?? [],
            'mediaContentGap' => $controls['mediaContentGaps']['options'] ?? [],
        ];

        foreach ($groups as $setting => $baseOptions) {
            if (! array_key_exists($setting, $override)) {
                continue;
            }
            $viewportControl = $this->resolvedResponsiveControlFor($sectionType, $base['presentation'] ?? null, $viewport, $setting, $baseOptions);
            $allowed = array_column($viewportControl['options'] ?? [], 'key');
            if (is_string($override[$setting]) && in_array($override[$setting], $allowed, true)) {
                $normalized[$setting] = $override[$setting];
            }
        }

        if (array_key_exists('mediaSpacing', $override) && isset($controls['mediaSpacing'])) {
            $viewportControl = $this->resolvedResponsiveControlFor(
                $sectionType,
                $base['presentation'] ?? null,
                $viewport,
                'mediaSpacing',
                $controls['mediaSpacing']['options'],
            );
            $allowed = array_column($viewportControl['options'] ?? [], 'key');
            $spacing = $override['mediaSpacing'];
            if (is_array($spacing) && collect(['top', 'right', 'bottom', 'left'])->every(
                fn (string $side): bool => is_string($spacing[$side] ?? null) && in_array($spacing[$side], $allowed, true)
            )) {
                $normalized['mediaSpacing'] = array_intersect_key($spacing, array_flip(['top', 'right', 'bottom', 'left']));
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    public function responsiveControlFor(string $sectionType, ?string $presentation, string $viewport, string $setting): ?array
    {
        if ($presentation === null || ! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true)) {
            return null;
        }

        return $this->mediaControlsFor($sectionType, $presentation)['responsive'][$viewport][$setting] ?? null;
    }

    public function supportsEventType(EventType $eventType): bool
    {
        return in_array($eventType, $this->supportedEventTypes, true);
    }

    public function supportsSection(string $sectionType): bool
    {
        return in_array($sectionType, $this->supportedSectionTypes, true);
    }

    /** @return array<string, list<array{key: string, displayName: string}>>|null */
    public function appearanceOptionsFor(string $sectionType): ?array
    {
        return $this->sectionAppearanceOptions[$sectionType] ?? null;
    }

    /** @return array{headingAlignment: string, bodyAlignment: string, backgroundTreatment: string, emphasis: string}|null */
    public function appearanceDefaultsFor(string $sectionType): ?array
    {
        return $this->sectionAppearanceDefaults[$sectionType] ?? null;
    }

    /** @return array{mode: 'single'|'multiple'}|null */
    public function mediaCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionMediaCapabilities[$sectionType] ?? null;
    }

    /** @return array{itemType: string, mode: 'single'}|null */
    public function itemMediaCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionItemMediaCapabilities[$sectionType] ?? null;
    }

    /** @return array{default: string, options: list<array{key: string, displayName: string, description: string, preview: string}>}|null */
    public function presentationCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionPresentationCapabilities[$sectionType] ?? null;
    }

    /** @return array<string, mixed> */
    public function mediaControlsFor(string $sectionType, string $presentation): array
    {
        foreach ($this->presentationCapabilityFor($sectionType)['options'] ?? [] as $option) {
            if ($option['key'] === $presentation) {
                return $option['mediaControls'] ?? [];
            }
        }

        return [];
    }

    /** @return array{presentation: string}|null */
    public function presentationFallbackFor(string $sectionType, string $presentation): ?array
    {
        return $this->sectionPresentationFallbacks[$sectionType][$presentation] ?? null;
    }

    /** @return array<string, string> */
    public function mediaControlSettings(): array
    {
        return [
            'mediaPlacement' => 'mediaPlacements',
            'mediaSize' => 'mediaSizes',
            'cornerStyle' => 'cornerStyles',
            'shadowStyle' => 'shadowStyles',
            'foregroundColor' => 'foregroundColors',
            'mediaContentGap' => 'mediaContentGaps',
        ];
    }
}
