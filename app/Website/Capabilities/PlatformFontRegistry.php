<?php

namespace App\Website\Capabilities;

final class PlatformFontRegistry
{
    public const UPSTREAM_COMMIT = 'ade3d1533e06b2b1462ffcde8e08b129627ca360';

    /** @return list<FontFamilyCapability> */
    public function realFonts(): array
    {
        return array_map(fn (array $font): FontFamilyCapability => $this->hosted(...$font), [
            ['cormorant-garamond', 'Cormorant Garamond', 'serif', 'Cormorant+Garamond', 'cormorantgaramond', [400, 600, 700], ['normal', 'italic']],
            ['playfair-display', 'Playfair Display', 'serif', 'Playfair+Display', 'playfairdisplay', [400, 600, 700], ['normal', 'italic']],
            ['libre-baskerville', 'Libre Baskerville', 'serif', 'Libre+Baskerville', 'librebaskerville', [400, 600, 700], ['normal', 'italic']],
            ['eb-garamond', 'EB Garamond', 'serif', 'EB+Garamond', 'ebgaramond', [400, 600, 700], ['normal', 'italic']],
            ['crimson-text', 'Crimson Text', 'serif', 'Crimson+Text', 'crimsontext', [400, 600, 700], ['normal', 'italic']],
            ['lora', 'Lora', 'serif', 'Lora', 'lora', [400, 600, 700], ['normal', 'italic']],
            ['merriweather', 'Merriweather', 'serif', 'Merriweather', 'merriweather', [400, 600, 700], ['normal', 'italic']],
            ['spectral', 'Spectral', 'serif', 'Spectral', 'spectral', [400, 600, 700], ['normal', 'italic']],
            ['dm-serif-display', 'DM Serif Display', 'serif', 'DM+Serif+Display', 'dmserifdisplay', [400], ['normal', 'italic']],
            ['old-standard-tt', 'Old Standard TT', 'serif', 'Old+Standard+TT', 'oldstandardtt', [400, 700], ['normal', 'italic']],
            ['cardo', 'Cardo', 'serif', 'Cardo', 'cardo', [400, 700], ['normal', 'italic']],
            ['sorts-mill-goudy', 'Sorts Mill Goudy', 'serif', 'Sorts+Mill+Goudy', 'sortsmillgoudy', [400], ['normal', 'italic']],
            ['cormorant-infant', 'Cormorant Infant', 'serif', 'Cormorant+Infant', 'cormorantinfant', [400, 600, 700], ['normal', 'italic']],
            ['crimson-pro', 'Crimson Pro', 'serif', 'Crimson+Pro', 'crimsonpro', [400, 600, 700], ['normal', 'italic']],
            ['bodoni-moda', 'Bodoni Moda', 'serif', 'Bodoni+Moda', 'bodonimoda', [400, 600, 700], ['normal', 'italic']],
            ['montserrat', 'Montserrat', 'sans', 'Montserrat', 'montserrat', [400, 600, 700], ['normal', 'italic']],
            ['lato', 'Lato', 'sans', 'Lato', 'lato', [400, 600, 700], ['normal', 'italic']],
            ['raleway', 'Raleway', 'sans', 'Raleway', 'raleway', [400, 600, 700], ['normal', 'italic']],
            ['josefin-sans', 'Josefin Sans', 'sans', 'Josefin+Sans', 'josefinsans', [400, 600, 700], ['normal', 'italic']],
            ['quicksand', 'Quicksand', 'sans', 'Quicksand', 'quicksand', [400, 600, 700], ['normal']],
            ['nunito', 'Nunito', 'sans', 'Nunito', 'nunito', [400, 600, 700], ['normal', 'italic']],
            ['poppins', 'Poppins', 'sans', 'Poppins', 'poppins', [400, 600, 700], ['normal', 'italic']],
            ['inter', 'Inter', 'sans', 'Inter', 'inter', [400, 600, 700], ['normal', 'italic']],
            ['work-sans', 'Work Sans', 'sans', 'Work+Sans', 'worksans', [400, 600, 700], ['normal', 'italic']],
            ['mulish', 'Mulish', 'sans', 'Mulish', 'mulish', [400, 600, 700], ['normal', 'italic']],
            ['dm-sans', 'DM Sans', 'sans', 'DM+Sans', 'dmsans', [400, 600, 700], ['normal', 'italic']],
            ['source-sans-3', 'Source Sans 3', 'sans', 'Source+Sans+3', 'sourcesans3', [400, 600, 700], ['normal', 'italic']],
            ['cabin', 'Cabin', 'sans', 'Cabin', 'cabin', [400, 600, 700], ['normal', 'italic']],
            ['karla', 'Karla', 'sans', 'Karla', 'karla', [400, 600, 700], ['normal', 'italic']],
            ['outfit', 'Outfit', 'sans', 'Outfit', 'outfit', [400, 600, 700], ['normal']],
            ['space-grotesk', 'Space Grotesk', 'sans', 'Space+Grotesk', 'spacegrotesk', [400, 600, 700], ['normal']],
            ['syne', 'Syne', 'sans', 'Syne', 'syne', [400, 600, 700], ['normal']],
            ['jetbrains-mono', 'JetBrains Mono', 'mono', 'JetBrains+Mono', 'jetbrainsmono', [400, 600, 700], ['normal', 'italic']],
            ['ibm-plex-mono', 'IBM Plex Mono', 'mono', 'IBM+Plex+Mono', 'ibmplexmono', [400, 600, 700], ['normal', 'italic']],
            ['space-mono', 'Space Mono', 'mono', 'Space+Mono', 'spacemono', [400, 700], ['normal', 'italic']],
            ['fira-code', 'Fira Code', 'mono', 'Fira+Code', 'firacode', [400, 600, 700], ['normal']],
            ['great-vibes', 'Great Vibes', 'script', 'Great+Vibes', 'greatvibes', [400], ['normal']],
            ['dancing-script', 'Dancing Script', 'script', 'Dancing+Script', 'dancingscript', [400, 600, 700], ['normal']],
            ['parisienne', 'Parisienne', 'script', 'Parisienne', 'parisienne', [400], ['normal']],
            ['alex-brush', 'Alex Brush', 'script', 'Alex+Brush', 'alexbrush', [400], ['normal']],
            ['sacramento', 'Sacramento', 'script', 'Sacramento', 'sacramento', [400], ['normal']],
            ['allura', 'Allura', 'script', 'Allura', 'allura', [400], ['normal']],
            ['pinyon-script', 'Pinyon Script', 'script', 'Pinyon+Script', 'pinyonscript', [400], ['normal']],
            ['tangerine', 'Tangerine', 'script', 'Tangerine', 'tangerine', [400, 700], ['normal']],
            ['rouge-script', 'Rouge Script', 'script', 'Rouge+Script', 'rougescript', [400], ['normal']],
            ['mrs-saint-delafield', 'Mrs Saint Delafield', 'script', 'Mrs+Saint+Delafield', 'mrssaintdelafield', [400], ['normal']],
            ['homemade-apple', 'Homemade Apple', 'script', 'Homemade+Apple', 'homemadeapple', [400], ['normal'], 'apache', 'APACHE-2.0'],
            ['cinzel', 'Cinzel', 'display', 'Cinzel', 'cinzel', [400, 600, 700], ['normal']],
            ['cinzel-decorative', 'Cinzel Decorative', 'display', 'Cinzel+Decorative', 'cinzeldecorative', [400, 700], ['normal']],
            ['antic-didone', 'Antic Didone', 'display', 'Antic+Didone', 'anticdidone', [400], ['normal']],
            ['marcellus', 'Marcellus', 'display', 'Marcellus', 'marcellus', [400], ['normal']],
            ['poiret-one', 'Poiret One', 'display', 'Poiret+One', 'poiretone', [400], ['normal']],
            ['tenor-sans', 'Tenor Sans', 'display', 'Tenor+Sans', 'tenorsans', [400], ['normal']],
            ['cormorant-upright', 'Cormorant Upright', 'display', 'Cormorant+Upright', 'cormorantupright', [400, 600, 700], ['normal']],
            ['forum', 'Forum', 'display', 'Forum', 'forum', [400], ['normal']],
            ['bebas-neue', 'Bebas Neue', 'display', 'Bebas+Neue', 'bebasneue', [400], ['normal']],
            ['archivo-black', 'Archivo Black', 'display', 'Archivo+Black', 'archivoblack', [400], ['normal']],
        ]);
    }

    /** @return list<FontFamilyCapability> */
    public function systemFonts(): array
    {
        return [$this->system('times-new-roman', 'Times New Roman', 'serif', '"Times New Roman", Times, serif'), $this->system('courier-new', 'Courier New', 'mono', '"Courier New", Courier, monospace')];
    }

    /** @return list<FontFamilyCapability> */
    public function platformFonts(): array
    {
        return [...$this->realFonts(), ...$this->systemFonts()];
    }

    /** @return list<FontFamilyCapability> */
    public function classicLegacyFonts(): array
    {
        return [$this->legacy('editorial-serif', 'Editorial Serif', [TypographyRole::Heading]), $this->legacy('modern-sans', 'Modern Sans', [TypographyRole::Heading, TypographyRole::Body]), $this->legacy('romantic-serif', 'Romantic Serif', [TypographyRole::Heading]), $this->legacy('classic-serif', 'Classic Serif', [TypographyRole::Body])];
    }

    /** @return list<FontFamilyCapability> */
    public function modernLegacyFonts(): array
    {
        return [$this->legacy('editorial-serif', 'Editorial Serif', [TypographyRole::Heading]), $this->legacy('modern-sans', 'Modern Sans', [TypographyRole::Heading, TypographyRole::Body]), $this->legacy('fashion-serif', 'Fashion Serif', [TypographyRole::Heading]), $this->legacy('fashion-sans', 'Fashion Sans', [TypographyRole::Body])];
    }

    private function hosted(string $id, string $family, string $category, string $apiFamily, string $upstreamPath, array $weights, array $styles, string $directory = 'ofl', string $license = 'OFL-1.1'): FontFamilyCapability
    {
        return new FontFamilyCapability($id, $family, [TypographyRole::Heading, TypographyRole::Body], $family, $category, ['type' => 'googleFonts', 'apiFamily' => $apiFamily, 'upstreamUrl' => 'https://github.com/google/fonts/tree/'.self::UPSTREAM_COMMIT.'/'.$directory.'/'.$upstreamPath, 'version' => self::UPSTREAM_COMMIT], $this->fallback($category), $weights, $styles, in_array($category, ['script', 'display'], true) ? ['heading', 'accent'] : ($category === 'mono' ? [] : ['heading', 'body']), ['id' => $license, 'url' => $license === 'APACHE-2.0' ? 'https://www.apache.org/licenses/LICENSE-2.0' : 'https://openfontlicense.org/open-font-license-official-text/']);
    }

    private function system(string $id, string $family, string $category, string $stack): FontFamilyCapability
    {
        return new FontFamilyCapability($id, $family, [TypographyRole::Heading, TypographyRole::Body], $family, $category, ['type' => 'system'], $stack, [400, 600, 700], ['normal', 'italic'], [], ['id' => 'system-font']);
    }

    private function legacy(string $id, string $name, array $roles): FontFamilyCapability
    {
        $fallback = match ($id) {
            'modern-sans', 'fashion-sans' => 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif', 'romantic-serif' => '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif', 'fashion-serif' => 'Didot, "Bodoni MT", "Times New Roman", serif', default => 'Georgia, Cambria, "Times New Roman", serif'
        };

        return new FontFamilyCapability($id, $name, $roles, $name, 'legacy', ['type' => 'legacyAlias'], $fallback, [400, 600, 700], ['normal', 'italic'], [], ['id' => 'system-font-stack']);
    }

    private function fallback(string $category): string
    {
        return match ($category) {
            'sans' => 'ui-sans-serif, system-ui, sans-serif', 'script' => '"Segoe Script", "Snell Roundhand", cursive', 'display' => 'Georgia, ui-serif, serif', 'mono' => '"SFMono-Regular", Consolas, "Liberation Mono", monospace', default => 'Georgia, Cambria, "Times New Roman", serif'
        };
    }
}
