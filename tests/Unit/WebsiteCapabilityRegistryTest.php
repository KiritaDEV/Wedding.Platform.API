<?php

namespace Tests\Unit;

use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlScope;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\GlobalDesignControlId;
use App\Website\Capabilities\GlobalDesignControlType;
use App\Website\Capabilities\PlatformFontRegistry;
use App\Website\Capabilities\ProjectColorRole;
use App\Website\Capabilities\TypographyRole;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use Tests\TestCase;

class WebsiteCapabilityRegistryTest extends TestCase
{
    public function test_full_platform_font_registry_is_unique_complete_and_system_safe(): void
    {
        $fonts = collect(app(PlatformFontRegistry::class)->platformFonts());
        $this->assertCount(59, $fonts);
        $this->assertCount(59, $fonts->pluck('id')->unique());
        $this->assertCount(57, $fonts->where('source.type', 'googleFonts'));

        foreach ($fonts->where('source.type', 'googleFonts') as $font) {
            $this->assertNotEmpty($font->source['upstreamUrl']);
            $this->assertNotEmpty($font->source['version']);
            $this->assertNotEmpty($font->license['id']);
            $this->assertNotEmpty($font->weights);
            $this->assertNotEmpty($font->styles);
        }

        $this->assertSame('system', $fonts->firstWhere('id', 'times-new-roman')->source['type']);
        $this->assertSame('system', $fonts->firstWhere('id', 'courier-new')->source['type']);
        $this->assertSame('APACHE-2.0', $fonts->firstWhere('id', 'homemade-apple')->license['id']);
    }

    public function test_platform_fonts_and_template_recommendations_are_serialized_without_changing_defaults(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $expectedRealIds = ['cormorant-garamond', 'playfair-display', 'inter', 'dm-sans', 'great-vibes', 'cinzel', 'jetbrains-mono'];

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
            $capabilities = $resolver->template($template);
            $payload = (new WebsiteTemplateCapabilitiesResource($capabilities))->resolve(request());
            $families = collect($payload['designLibrary']['fontFamilies'])->keyBy('id');

            foreach ($expectedRealIds as $id) {
                $this->assertSame('googleFonts', $families[$id]['source']['type']);
                $this->assertSame('OFL-1.1', $families[$id]['license']['id']);
                $this->assertNotEmpty($families[$id]['fallback']);
            }
            $this->assertSame('legacyAlias', $families['editorial-serif']['source']['type']);
            $this->assertNotEmpty($payload['designLibrary']['fontRecommendations']['heading']);
            $this->assertNotEmpty($payload['designLibrary']['fontRecommendations']['body']);
            $this->assertNotEmpty($payload['designLibrary']['fontRecommendations']['accent']);
            foreach ($payload['designLibrary']['fontRecommendations'] as $recommendations) {
                foreach ($recommendations as $id) {
                    $this->assertTrue($families->has($id));
                }
            }
        }
    }

    public function test_hero_uses_composable_elements_without_presentations(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $classicHero = $resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'hero');
        $modernHero = $resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'hero');

        $this->assertSame([], $classicHero->presentations);
        $this->assertSame([], $modernHero->presentations);
        $this->assertContains('compositionGroup', $classicHero->allowedElementTypes);
        $this->assertContains('compositionGroup', $modernHero->allowedElementTypes);
        $this->assertNotNull($classicHero->decorativeAppearance);
        $this->assertNotNull($modernHero->decorativeAppearance);
        $this->assertNull($resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'people'));
        $this->assertNull($resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'people'));
    }

    public function test_all_canonical_sections_expose_truthful_decorative_frame_capabilities(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach (['hero', 'gallery', 'rsvp', 'blank'] as $sectionType) {
            $this->assertSame(['none', 'fine', 'ornamental'], $resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $sectionType)->decorativeAppearance->frames);
            $this->assertSame(['none', 'fine'], $resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, $sectionType)->decorativeAppearance->frames);
            $this->assertNotEmpty($resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $sectionType)->decorativeAppearance->frameColorIds);
            $this->assertNotEmpty($resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, $sectionType)->decorativeAppearance->frameColorIds);
        }
    }

    public function test_global_design_capabilities_preserve_registry_options_defaults_and_resolver_lookups(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $expectedControls = [
            GlobalDesignControlId::ColorTheme->value => ['colorThemes', GlobalDesignControlType::PalettePreset],
            GlobalDesignControlId::FontSet->value => ['fontSets', GlobalDesignControlType::TypographyPairing],
            GlobalDesignControlId::ArtStyle->value => ['artStyles', GlobalDesignControlType::ArtStyle],
        ];

        foreach (app(WebsiteTemplateRegistry::class)->all() as $template) {
            $capability = $resolver->globalDesign($template);
            $this->assertNotNull($capability);
            $this->assertSame(array_keys($expectedControls), array_map(
                fn ($control): string => $control->id->value,
                $capability->controls,
            ));

            foreach ($capability->controls as $control) {
                [$group, $type] = $expectedControls[$control->id->value];
                $this->assertSame($type, $control->type);
                $this->assertSame($template->designOptions[$group], $control->options);
                $this->assertSame($template->defaultDesignSettings[$control->id->value], $control->default);
                $this->assertContains($control->default, array_column($control->options, 'key'));
                $this->assertEquals($control, $resolver->globalDesignControl($template, $control->id));
                $this->assertSame($control->default, $resolver->globalDesignDefault($template, $control->id));
                $this->assertTrue($resolver->allowsGlobalDesignValue($template, $control->id, $control->default));
                $this->assertFalse($resolver->allowsGlobalDesignValue($template, $control->id, 'unsupported-value'));
            }

            $serialized = (new WebsiteTemplateCapabilitiesResource($resolver->template($template)))->resolve(request());
            $this->assertSame(array_keys($expectedControls), array_column($serialized['globalDesign']['controls'], 'id'));
            $this->assertSame(
                $resolver->globalDesignDefaults($template),
                $resolver->normalizeGlobalDesignSettings($template, [
                    'colorTheme' => 'unsupported-value',
                    'fontSet' => null,
                ]),
            );
        }

        $classic = $resolver->globalDesign(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $modern = $resolver->globalDesign(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $this->assertSame(['terracotta', 'editorial', 'minimal'], array_column($classic->controls, 'default'));
        $this->assertSame(['ink', 'editorial', 'clean'], array_column($modern->controls, 'default'));
        foreach ([0, 1, 2] as $controlIndex) {
            $this->assertNotSame($classic->controls[$controlIndex]->options, $modern->controls[$controlIndex]->options);
        }
        $this->assertNull($resolver->globalDesign('unknown-template'));
        $this->assertNull($resolver->globalDesignDefaults('unknown-template'));
        $this->assertNull($resolver->normalizeGlobalDesignSettings('unknown-template', []));
        $this->assertNull($resolver->globalDesignControl(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control'));
        $this->assertNull($resolver->globalDesignDefault(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control'));
        $this->assertFalse($resolver->allowsGlobalDesignValue(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control', 'terracotta'));
    }

    public function test_template_design_libraries_preserve_current_palette_and_typography_contracts(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $expected = [
            WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1 => [
                'defaults' => ['colorTheme' => 'terracotta', 'fontSet' => 'editorial', 'artStyle' => 'minimal'],
                'palettes' => [
                    'terracotta' => ['Terracotta', ['canvas' => '#f8f0e4', 'surface' => '#f1e5d5', 'text' => '#3b312d', 'textMuted' => '#6c5f57', 'accent' => '#9d5b45', 'accentContrast' => '#ffffff', 'border' => '#806d5e', 'ornament' => '#78805f']],
                    'olive' => ['Olive', ['canvas' => '#f4f1e5', 'surface' => '#e8e4d2', 'text' => '#34372c', 'textMuted' => '#626454', 'accent' => '#70764e', 'accentContrast' => '#ffffff', 'border' => '#74745f', 'ornament' => '#a06a4f']],
                    'sage' => ['Sage', ['canvas' => '#f1f3e9', 'surface' => '#e2e8d9', 'text' => '#303a33', 'textMuted' => '#5f6c63', 'accent' => '#748a70', 'accentContrast' => '#ffffff', 'border' => '#718075', 'ornament' => '#a86650']],
                    'burgundy' => ['Burgundy', ['canvas' => '#f7eeea', 'surface' => '#ecddda', 'text' => '#3d292d', 'textMuted' => '#715d61', 'accent' => '#7d3443', 'accentContrast' => '#ffffff', 'border' => '#82696d', 'ornament' => '#7b7955']],
                    'neutral' => ['Warm Neutral', ['canvas' => '#f5f1eb', 'surface' => '#e9e3dc', 'text' => '#35312e', 'textMuted' => '#69625d', 'accent' => '#74645a', 'accentContrast' => '#ffffff', 'border' => '#7c746e', 'ornament' => '#777363']],
                ],
                'typography' => [
                    'editorial' => ['Editorial', 'editorial-serif', 'modern-sans'],
                    'romantic' => ['Romantic', 'romantic-serif', 'classic-serif'],
                    'modern' => ['Modern', 'modern-sans', 'modern-sans'],
                ],
            ],
            WebsiteTemplateRegistry::MODERN_EDITORIAL_V1 => [
                'defaults' => ['colorTheme' => 'ink', 'fontSet' => 'editorial', 'artStyle' => 'clean'],
                'palettes' => [
                    'ink' => ['Ink', ['canvas' => '#f5f3ee', 'surface' => '#e9e6df', 'text' => '#171717', 'textMuted' => '#65615b', 'accent' => '#171717', 'accentContrast' => '#ffffff', 'border' => '#908a80']],
                    'stone' => ['Stone', ['canvas' => '#f3f1ed', 'surface' => '#e4e0da', 'text' => '#302e2a', 'textMuted' => '#716c64', 'accent' => '#686158', 'accentContrast' => '#ffffff', 'border' => '#999188']],
                    'blush' => ['Blush', ['canvas' => '#faf3f1', 'surface' => '#f0dfdc', 'text' => '#3b292b', 'textMuted' => '#765f61', 'accent' => '#9c5f64', 'accentContrast' => '#ffffff', 'border' => '#b99191']],
                    'plum' => ['Plum', ['canvas' => '#f7f2f6', 'surface' => '#e9dde7', 'text' => '#302330', 'textMuted' => '#6e5a6c', 'accent' => '#5f405f', 'accentContrast' => '#ffffff', 'border' => '#927b8f']],
                    'navy' => ['Navy', ['canvas' => '#f1f4f7', 'surface' => '#dde4eb', 'text' => '#182432', 'textMuted' => '#596775', 'accent' => '#263c5a', 'accentContrast' => '#ffffff', 'border' => '#77889b']],
                ],
                'typography' => [
                    'editorial' => ['Editorial', 'editorial-serif', 'modern-sans'],
                    'fashion' => ['Fashion', 'fashion-serif', 'fashion-sans'],
                    'minimal' => ['Minimal', 'modern-sans', 'modern-sans'],
                ],
            ],
        ];

        foreach ($expected as $templateKey => $contract) {
            $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
            $library = $resolver->template($template)->designLibrary;
            $colors = collect($library->colors)->keyBy('id');
            $this->assertCount($colors->count(), $colors->pluck('id')->unique());
            $this->assertCount(count($library->fontFamilies), collect($library->fontFamilies)->pluck('id')->unique());

            foreach ($contract['palettes'] as $presetId => [$label, $roles]) {
                $preset = collect($library->palettePresets)->firstWhere('id', $presetId);
                $this->assertSame($label, $preset->displayName);
                $this->assertSame($roles, collect($preset->roles)->map(fn (string $colorId): string => $colors[$colorId]->value)->all());
            }
            foreach ($contract['typography'] as $presetId => [$label, $headingId, $bodyId]) {
                $preset = collect($library->typographyPresets)->firstWhere('id', $presetId);
                $families = collect($library->fontFamilies)->keyBy('id');
                $this->assertSame([$label, $headingId, $bodyId], [$preset->displayName, $preset->headingFontId, $preset->bodyFontId]);
                $this->assertContains(TypographyRole::Heading, $families[$headingId]->allowedRoles);
                $this->assertContains(TypographyRole::Body, $families[$bodyId]->allowedRoles);
            }

            $this->assertSame(array_keys($contract['palettes']), array_column($template->designOptions['colorThemes'], 'key'));
            $this->assertSame(array_column($contract['palettes'], 0), array_column($template->designOptions['colorThemes'], 'displayName'));
            $this->assertSame(array_keys($contract['typography']), array_column($template->designOptions['fontSets'], 'key'));
            $this->assertSame(array_column($contract['typography'], 0), array_column($template->designOptions['fontSets'], 'displayName'));
            $this->assertSame($contract['defaults'], $template->defaultDesignSettings);
        }

        $classic = $resolver->template(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->designLibrary;
        $modern = $resolver->template(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)->designLibrary;
        $this->assertTrue(collect($classic->palettePresets)->every(fn ($preset): bool => isset($preset->roles['ornament'])));
        $this->assertTrue(collect($modern->palettePresets)->every(fn ($preset): bool => ! isset($preset->roles['ornament'])));
    }

    public function test_legacy_presets_resolve_legal_project_defaults_for_every_template_option(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach (app(WebsiteTemplateRegistry::class)->all() as $template) {
            $capabilities = $resolver->template($template);
            $library = $capabilities->designLibrary;
            $fontFamilies = collect($library->fontFamilies)->keyBy('id');
            $colors = collect($library->colors)->keyBy('id');

            foreach ($template->designOptions['colorThemes'] as $colorTheme) {
                $resolved = $resolver->resolveProjectDesignDefaults($template, [
                    ...$template->defaultDesignSettings,
                    'colorTheme' => $colorTheme['key'],
                ]);
                $this->assertNotNull($resolved);
                foreach (['headingColorId', 'bodyColorId', 'accentColorId'] as $property) {
                    $this->assertTrue($colors->has($resolved->{$property}));
                }
                $this->assertContains($resolved->headingColorId, $capabilities->projectDefaults->colors->headingColorIds);
                $this->assertContains($resolved->bodyColorId, $capabilities->projectDefaults->colors->bodyColorIds);
                $this->assertContains($resolved->accentColorId, $capabilities->projectDefaults->colors->accentColorIds);
            }

            foreach ($template->designOptions['fontSets'] as $fontSet) {
                $resolved = $resolver->resolveProjectDesignDefaults($template, [
                    ...$template->defaultDesignSettings,
                    'fontSet' => $fontSet['key'],
                ]);
                $this->assertNotNull($resolved);
                $this->assertContains(TypographyRole::Heading, $fontFamilies[$resolved->headingFontId]->allowedRoles);
                $this->assertContains(TypographyRole::Body, $fontFamilies[$resolved->bodyFontId]->allowedRoles);
                $this->assertContains($resolved->headingFontId, $capabilities->projectDefaults->typography->headingFontIds);
                $this->assertContains($resolved->bodyFontId, $capabilities->projectDefaults->typography->bodyFontIds);
            }

            foreach ($library->colors as $color) {
                foreach ($color->allowedProjectRoles as $role) {
                    $allowed = match ($role) {
                        ProjectColorRole::Heading => $capabilities->projectDefaults->colors->headingColorIds,
                        ProjectColorRole::Body => $capabilities->projectDefaults->colors->bodyColorIds,
                        ProjectColorRole::Accent => $capabilities->projectDefaults->colors->accentColorIds,
                    };
                    $this->assertContains($color->id, $allowed);
                }
            }

            $fallback = $resolver->resolveProjectDesignDefaults($template, ['colorTheme' => 'invalid', 'fontSet' => 'invalid']);
            $defaults = $resolver->resolveProjectDesignDefaults($template, $template->defaultDesignSettings);
            $this->assertEquals($defaults, $fallback);
        }

        $classic = $resolver->resolveProjectDesignDefaults(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, [
            'colorTheme' => 'terracotta', 'fontSet' => 'editorial', 'artStyle' => 'minimal',
        ]);
        $this->assertSame(['editorial-serif', 'modern-sans', 'terracotta-text', 'terracotta-text', 'terracotta-accent'], [
            $classic->headingFontId, $classic->bodyFontId, $classic->headingColorId, $classic->bodyColorId, $classic->accentColorId,
        ]);

        $modern = $resolver->resolveProjectDesignDefaults(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, [
            'colorTheme' => 'ink', 'fontSet' => 'editorial', 'artStyle' => 'clean',
        ]);
        $this->assertSame(['editorial-serif', 'modern-sans', 'ink-text', 'ink-text', 'ink-accent'], [
            $modern->headingFontId, $modern->bodyFontId, $modern->headingColorId, $modern->bodyColorId, $modern->accentColorId,
        ]);
    }

    public function test_every_responsive_control_serializes_fully_resolved_viewport_defaults_in_resolver_parity(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $seenResponsiveControls = [];

        foreach (app(WebsiteTemplateRegistry::class)->all() as $template) {
            $capabilities = $resolver->template($template);
            $serialized = (new WebsiteTemplateCapabilitiesResource($capabilities))->resolve(request());

            foreach ($capabilities->sections as $sectionIndex => $section) {
                $contexts = [[null, $section->appearanceControls, $serialized['sections'][$sectionIndex]['appearanceControls']]];
                foreach ($section->presentations as $presentationIndex => $presentation) {
                    $contexts[] = [
                        $presentation->id,
                        $presentation->appearanceControls,
                        $serialized['sections'][$sectionIndex]['presentations'][$presentationIndex]['appearanceControls'],
                    ];
                }

                foreach ($contexts as [$presentationId, $controls, $serializedControls]) {
                    foreach ($controls as $controlIndex => $control) {
                        if ($control->scope !== AppearanceControlScope::Responsive) {
                            continue;
                        }

                        $seenResponsiveControls[$control->id] = true;
                        foreach (['tablet', 'mobile'] as $viewport) {
                            $this->assertArrayHasKey($viewport, $control->viewports);
                            $resolved = collect($resolver->controlsForViewport(
                                $template,
                                $section->id,
                                $presentationId,
                                $viewport,
                            ))->keyBy('id')->get($control->id);
                            $this->assertNotNull($resolved);
                            $this->assertSame($control->viewports[$viewport]->default, $resolved->default);
                            $this->assertSame($control->viewports[$viewport]->options, $resolved->options);
                            $this->assertSame($resolved->default, $serializedControls[$controlIndex]['viewports'][$viewport]['default']);
                            $this->assertSame($resolved->options, $serializedControls[$controlIndex]['viewports'][$viewport]['options']);
                            $this->assertResolvedDefaultIsAllowed($resolved);
                        }
                    }
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            ['headingAlignment', 'bodyAlignment'],
            array_keys($seenResponsiveControls),
        );
    }

    public function test_responsive_control_without_a_resolved_viewport_is_unsupported(): void
    {
        $control = new AppearanceControlCapability(
            id: 'mediaSize',
            type: AppearanceControlType::Option,
            scope: AppearanceControlScope::Responsive,
            default: 'balanced',
            options: [['key' => 'balanced', 'displayName' => 'Balanced']],
        );

        $this->assertNull($control->forViewport('tablet'));
        $this->assertNull($control->forViewport('mobile'));
    }

    /** @param list<string> $knownControls */
    private function assertValidControl(AppearanceControlCapability $control, array $knownControls): void
    {
        $this->assertContains($control->id, $knownControls);
        $this->assertContains($control->type, AppearanceControlType::cases());
        $this->assertContains($control->scope, AppearanceControlScope::cases());

        if ($control->type === AppearanceControlType::Number) {
            $this->assertNotNull($control->minimum);
            $this->assertNotNull($control->maximum);
            $this->assertNotNull($control->step);
            $this->assertGreaterThanOrEqual($control->minimum, $control->default);
            $this->assertLessThanOrEqual($control->maximum, $control->default);
            $this->assertGreaterThan(0, $control->step);

            return;
        }

        $allowed = array_column($control->options, 'key');
        $this->assertNotEmpty($allowed);
        if ($control->type === AppearanceControlType::Option) {
            $this->assertContains($control->default, $allowed);
        } else {
            foreach ($control->default as $value) {
                $this->assertContains($value, $allowed);
            }
        }
        foreach ($control->viewports as $viewport) {
            $viewportAllowed = array_column($viewport->options, 'key');
            $this->assertNotEmpty($viewportAllowed);
            foreach (is_array($viewport->default) ? $viewport->default : [$viewport->default] as $value) {
                $this->assertContains($value, $viewportAllowed);
            }
        }
    }

    private function assertResolvedDefaultIsAllowed(AppearanceControlCapability $control): void
    {
        $allowed = array_column($control->options, 'key');
        foreach (is_array($control->default) ? $control->default : [$control->default] as $value) {
            $this->assertContains($value, $allowed);
        }
    }
}
