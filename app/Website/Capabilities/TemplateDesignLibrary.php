<?php

namespace App\Website\Capabilities;

final readonly class TemplateDesignLibrary
{
    /**
     * @param  list<DesignColorCapability>  $colors
     * @param  list<FontFamilyCapability>  $fontFamilies
     * @param  list<PalettePresetCapability>  $palettePresets
     * @param  list<TypographyPresetCapability>  $typographyPresets
     */
    public function __construct(
        public array $colors,
        public array $fontFamilies,
        public array $palettePresets,
        public array $typographyPresets,
        public array $fontRecommendations = [],
    ) {}
}
